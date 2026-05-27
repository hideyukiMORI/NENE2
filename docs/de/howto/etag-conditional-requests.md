# ETag und bedingte Requests

> **FT-Referenz**: FT307 (`NENE2-FT/etaglog`) — ETag-bedingte Requests: `If-None-Match`→304, `If-Modified-Since`→304, `If-Match`→412 veraltet / 428 fehlend, Wildcard `If-Match: *` wird durchgelassen, ETag ändert sich nach jedem Update, 15 Tests PASS.

ETags ermöglichen es Clients, das erneute Herunterladen unveränderter Inhalte zu vermeiden und veralteten Zustand vor dem Schreiben zu erkennen. NENE2 stellt zwei Helfer für die häufigsten Muster bereit.

| Szenario | Header | Helfer | Bei Übereinstimmung |
|---|---|---|---|
| Bedingtes GET | `If-None-Match` | `ConditionalGetHelper` | 304 Not Modified |
| Bedingtes Schreiben | `If-Match` | `ConditionalWriteHelper` | Schreiben wird fortgesetzt |
| Schreiben ohne Header | — | `ConditionalWriteHelper` | 428 Precondition Required |
| Veralteter ETag beim Schreiben | `If-Match` | `ConditionalWriteHelper` | 412 Precondition Failed |

## ETag-Generierung

Einen starken ETag aus dem Ressourceninhalt als doppelt-gequoteter MD5-Wert generieren:

```php
final readonly class Article
{
    public function etag(): string
    {
        // Doppelte Anführungszeichen sind per RFC 9110 erforderlich — ohne sie schlägt If-None-Match-Vergleich immer fehl
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

Die ETag-Generierung an einem Ort behalten (eine Methode auf der Entität), damit eine Algorithmusänderung (z. B. auf SHA-256) eine einzige Bearbeitung ist.

## Bedingtes GET — 304 Not Modified

```php
private function get(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    $etag = $article->etag();

    // Gibt eine 304-Antwort zurück, wenn If-None-Match mit dem aktuellen ETag übereinstimmt.
    // Gibt null zurück, wenn eine vollständige 200-Antwort gesendet werden muss.
    $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
    if ($notModified !== null) {
        return $notModified;
    }

    return $this->json->create($this->serialize($article))
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

`ConditionalGetHelper::check()` wertet zwei Header aus:
- `If-None-Match`: exakter ETag-Match → 304
- `If-Modified-Since`: String-Vergleich `$ifModifiedSince >= $lastModified` → 304

Immer denselben `$etag`-Wert in beiden dem `check()`-Aufruf und dem `withHeader('ETag', $etag)`-Aufruf übergeben. Separate Generierung riskiert Abweichungen.

### Last-Modified-Format

Die `If-Modified-Since`-Prüfung ist ein **String-Vergleich**, kein geparster Datumsvergleich. Ein Format verwenden, das lexikografisch sortiert — ISO 8601 wird empfohlen:

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'); // ✅ 2026-05-21T12:00:00Z
```

Das HTTP-Standardformat `Sat, 21 May 2026 12:00:00 GMT` sortiert falsch — nicht mit diesem Helfer verwenden.

### 304 hat keinen Body

RFC 9110 verbietet einen Body in 304-Antworten. `ConditionalGetHelper` gibt eine leere `createResponse(304)` zurück, daher wird dies korrekt behandelt, solange die Antwort des Helfers direkt zurückgegeben wird.

## Bedingtes Schreiben — If-Match

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    // Muss VOR dem Schreiben aufgerufen werden — nachher zu prüfen ist sinnlos.
    // Gibt 428 zurück, wenn If-Match fehlt; 412, wenn If-Match vorhanden aber falsch.
    // Gibt null zurück, wenn die Vorbedingung erfüllt ist.
    $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
    if ($preconditionFailed !== null) {
        return $preconditionFailed;
    }

    $updated = $this->repo->update($id, $title, $body);

    return $this->json->create($this->serialize($updated))
        ->withHeader('ETag', $updated->etag())
        ->withHeader('Last-Modified', $updated->updatedAt);
}
```

### If-Match: * Wildcard

Ein Client kann `If-Match: *` senden, um zu bedeuten: "fortfahren, wenn die Ressource überhaupt existiert". `ConditionalWriteHelper` lässt dies bedingungslos durch. **Der Aufrufer ist dafür verantwortlich, 404 zurückzugeben, wenn die Ressource nicht existiert** — den Datensatz zuerst abrufen und mit einer 404 absichern.

### If-Match optional machen

Standardmäßig (`$require = true`) gibt ein fehlender `If-Match` 428 zurück. Um Schreibvorgänge ohne Vorbedingungsheader zu erlauben:

```php
ConditionalWriteHelper::check($request, $this->problems, $article->etag(), require: false);
```

Dies nur lockern, wenn optimistisches Sperren für die Ressource tatsächlich optional ist.

## Client-Flow

```
POST /articles            → 201 { id: 1, ... }  ETag: "abc123"
GET  /articles/1          → 200 { id: 1, ... }  ETag: "abc123"

GET  /articles/1          → 304 (kein Body)
  If-None-Match: "abc123"

PATCH /articles/1         → 200 { ... }  ETag: "def456"
  If-Match: "abc123"
  { title: "Updated" }

PATCH /articles/1         → 412 Precondition Failed
  If-Match: "abc123"       (veraltet — Inhalt hat sich geändert, ETag ist jetzt "def456")

PATCH /articles/1         → 428 Precondition Required
  (kein If-Match-Header)

PATCH /articles/1         → 200 { ... }
  If-Match: *              (Wildcard — beliebige vorhandene Version)
```

## ETag in jeder Antwort einschließen

`ETag` (und `Last-Modified`) bei POST-, GET- und PATCH-Antworten zurückgeben, damit der Client immer einen aktuellen Wert hat, ohne einen zusätzlichen Round-Trip zu benötigen:

```php
return $this->json->create($this->serialize($article), 201)
    ->withHeader('ETag', $article->etag())
    ->withHeader('Last-Modified', $article->updatedAt);
```

## ETag vs. Version-Feld

| | ETag (HTTP-Header) | Version-Feld (Body) |
|---|---|---|
| Wo geprüft | HTTP-Header | Request-Body |
| Granularität | Inhalts-Hash | Ganzzahlzähler |
| Client muss verfolgen | ETag-Wert | Versionsnummer |
| Am besten für | HTTP-Caching + optimistisches Sperren | API-Level-Konflikteerkennung |

Sie können zusammen verwendet werden: ETag für HTTP-Caching, Version für DB-Level-Konflikterkennung (siehe [optimistic-locking.md](optimistic-locking.md)).

## Code-Review-Checkliste

- [ ] ETag-String enthält umschließende doppelte Anführungszeichen (`'"' . md5(...) . '"'`)
- [ ] ETag-Generierung ist an einem Ort (Entitätsmethode), nicht über Handler dupliziert
- [ ] `ConditionalGetHelper::check()` wird vor dem Aufbau der 200-Antwort aufgerufen
- [ ] Derselbe `$etag`-Wert wird sowohl an `check()` als auch an `withHeader('ETag', $etag)` übergeben
- [ ] `ConditionalWriteHelper::check()` wird vor dem Schreiben aufgerufen
- [ ] 304-Response-Body ist leer (die Antwort des Helfers direkt zurückgeben)
- [ ] `Last-Modified`-Werte verwenden ISO 8601-Format (lexikografische Sortierung erforderlich)
- [ ] Jede Antwort (201, 200) enthält `ETag`, damit der Client immer einen frischen Wert hat
- [ ] Tests decken ab: 200 ohne `If-None-Match`, 304 bei Match, 200 bei veraltetem ETag, 428 ohne `If-Match`, 412 bei veraltetem `If-Match`, 200 bei korrektem `If-Match`, `If-Match: *`
