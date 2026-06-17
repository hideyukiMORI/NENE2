# How-to: Content-Genehmigungsworkflow

> **FT-Referenz**: FT248 (`NENE2-FT/flowlog`) — Content-Genehmigungsworkflow-API
> **ATK**: FT248 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert einen Post-Publikations-Lebenszyklus, bei dem ein `PostStatus` `BackedEnum` den Übergangsgraphen über `canTransitionTo()` besitzt, ungültige Übergänge `InvalidTransitionException → 409` auslösen und die Ablehnung einen optionalen Grund trägt. Enthält eine vollständige Cracker-Mindset-Angriffsbewertung.

---

## Routen

| Methode | Pfad                       | Beschreibung                                                |
|---------|----------------------------|-------------------------------------------------------------|
| `POST`  | `/posts`                   | Post erstellen (startet immer als `draft`)                  |
| `GET`   | `/posts`                   | Posts auflisten (paginiert, nach Status filterbar)          |
| `GET`   | `/posts/{id}`              | Einzelnen Post abrufen                                      |
| `POST`  | `/posts/{id}/submit`       | Übergang: `draft → submitted`                               |
| `POST`  | `/posts/{id}/approve`      | Übergang: `submitted → approved`                            |
| `POST`  | `/posts/{id}/reject`       | Übergang: `submitted → rejected` (optionaler Grund)         |

> **Statische Aktionsrouten vor parametrisierten**: `/posts/{id}/submit`, `/approve`, `/reject` werden vor `/posts/{id}` registriert, damit literale Unterpfade nicht vom parametrisierten Segment erfasst werden.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL DEFAULT '',
    author        TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'draft'
                           CHECK(status IN ('draft', 'submitted', 'approved', 'rejected')),
    reject_reason TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`status` hat einen DB-Level-`CHECK`-Constraint als Sicherheitsnetz; die Anwendung validiert über `PostStatus::canTransitionTo()` vor jedem Schreibvorgang. `reject_reason` ist nullable — wird nur bei Ablehnung gesetzt.

---

## `PostStatus` BackedEnum mit `canTransitionTo()`

Der Zustandsübergangsgraph gehört dem Enum selbst:

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Submitted,
            self::Submitted => $target === self::Approved || $target === self::Rejected,
            self::Approved,
            self::Rejected  => false,  // terminale Zustände
        };
    }
}
```

Der Übergangsgraph:
```
draft → submitted → approved (terminal)
                 → rejected  (terminal)
```

`Approved` und `Rejected` sind terminale Zustände — weitere Übergänge sind nicht erlaubt. Der Versuch, einen bereits genehmigten Post zu genehmigen, löst `InvalidTransitionException` aus.

---

## Repository-Übergangsmethode

```php
public function transition(int $id, PostStatus $targetStatus, string $now, ?string $rejectReason = null): Post
{
    $post = $this->findById($id);

    if (!$post->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($post->status, $targetStatus);
    }

    $this->executor->execute(
        'UPDATE posts SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $rejectReason, $now, $id],
    );

    return new Post($id, $post->title, $post->body, $post->author, $targetStatus, $rejectReason, $post->createdAt, $now);
}
```

Die `transition()`-Methode wird von submit, approve und reject gemeinsam genutzt — jeder Handler ruft sie mit einem anderen `$targetStatus` auf. `reject_reason` ist bei approve/submit `null` und wird optional bei reject angegeben.

---

## Status-Filter mit `PostStatus::tryFrom()`

```php
$statusStr = QueryStringParser::string($request, 'status');

if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([
            new ValidationError('status', "Invalid status '{$statusStr}'. Valid values: draft, submitted, approved, rejected.", 'invalid'),
        ]);
    }
    $items = $this->repository->findByStatus($status, $pagination->limit, $pagination->offset);
}
```

`BackedEnum::tryFrom()` gibt `null` für unbekannte String-Werte zurück, anstatt eine Ausnahme auszulösen. Die explizite `null`-Prüfung erzeugt ein strukturiertes `422` mit einer lesbaren Fehlermeldung, die gültige Werte auflistet.

---

## Ablehnung mit optionalem Grund

`POST /posts/{id}/reject` akzeptiert ein optionales `reason`-Feld:

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

Ein leerer Body `{}` oder ein fehlendes `reason`-Feld führen beide zu `null`. Ein nur-Leerzeichen-Grund wird ebenfalls über `trim()` auf `null` normalisiert. Der Grund wird in der nullable-Spalte `reject_reason` gespeichert.

---

## ATK — Cracker-Mindset-Angriffstest (FT248)

### ATK-01 — Keine Authentifizierung: Jeder kann beliebige Posts genehmigen oder ablehnen

**Angriff**: Einen Post ohne Anmeldedaten genehmigen oder ablehnen.

```bash
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/reject
```

**Beobachtet**: Beide gelingen mit `200 OK`. Jeder Aufrufer kann jeden Post durch jeden erlaubten Übergang schieben.

**Urteil**: **EXPONIERT** — Authentifizierung und rollenbasierte Autorisierung hinzufügen. Nur designierte Prüfer sollten genehmigen/ablehnen können. Das Einreichen sollte erfordern, dass der Autor des Posts authentifiziert ist.

---

### ATK-02 — Ungültiger Zustandsübergang: Entwurf genehmigen

**Angriff**: Versuchen, einen Post im `draft`-Status zu genehmigen.

```bash
curl -X POST http://localhost:8200/posts/1/approve
# Post 1 befindet sich im Draft-Status
```

**Beobachtet**: `canTransitionTo(Approved)` gibt `false` zurück für `Draft` → `InvalidTransitionException` → `409 Conflict` mit von/nach-Kontext in der Antwort.

**Urteil**: **BLOCKIERT** — enum-eigener Übergangsgraph verhindert illegale Zustandssprünge.

---

### ATK-03 — Doppelte Genehmigung: Einen bereits genehmigten Post erneut genehmigen

**Angriff**: Einen Post ein zweites Mal genehmigen.

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/approve  # zweite Genehmigung
```

**Beobachtet**: Dritte Anfrage: `canTransitionTo(Approved)` von `Approved` → `false` → `409 Conflict`. Der Post verbleibt im `Approved`-Status.

**Urteil**: **BLOCKIERT** — `Approved` ist ein terminaler Zustand; das Enum gibt explizit `false` für alle Übergänge von terminalen Zuständen zurück.

---

### ATK-04 — SQL-Injection über Titel oder Body

**Angriff**: SQL-Metazeichen einbetten.

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**Beobachtet**: Werte werden über parametrisierte `?`-Platzhalter gebunden. Der Injection-Payload wird als literaler Text gespeichert.

**Urteil**: **BLOCKIERT** — Parametrisierte Abfragen verhindern SQL-Injection.

---

### ATK-05 — Ungültiger Status-Filterwert

**Angriff**: Einen unbekannten Status an den Listen-Endpunkt übergeben.

```
GET /posts?status=hacked
GET /posts?status=published
```

**Beobachtet**: `PostStatus::tryFrom('hacked')` gibt `null` zurück → `ValidationException` → `422 Unprocessable Entity` mit der Liste gültiger Status.

**Urteil**: **BLOCKIERT** — `BackedEnum::tryFrom()` + explizite Null-Prüfung lehnt unbekannte Status-Werte ab.

---

### ATK-06 — Autor-Imitation

**Angriff**: Einen Post erstellen, der behauptet, ein privilegierter Autor zu sein.

```json
{"title": "Official announcement", "author": "admin"}
```

**Beobachtet**: `201 Created` — das `author`-Feld wird wörtlich aus dem Request-Body entnommen ohne Verifizierung. Jeder String wird akzeptiert.

**Urteil**: **EXPONIERT** — `author` ist benutzerseitig angegeben ohne kryptographische Bindung. In der Produktion `author` aus der authentifizierten Sitzung/dem Token ableiten, niemals aus dem Request-Body.

---

### ATK-07 — Mass Assignment: `status` beim Erstellen injizieren

**Angriff**: `status` direkt bei der Erstellung auf `approved` setzen.

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**Beobachtet**: `createPost()` ignoriert jedes `status`-Feld im Body — es fügt immer `PostStatus::Draft->value` ein. Der zusätzliche Schlüssel wird stillschweigend verworfen.

**Urteil**: **BLOCKIERT** — Der Controller erstellt das INSERT mit einem fest codierten `PostStatus::Draft->value`-Wert; kein Body-Feld kann ihn überschreiben.

---

### ATK-08 — XSS-Payload in Titel, Body oder Autor

**Angriff**: Ein Script-Tag speichern.

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**Beobachtet**: Inhalt wird unverändert gespeichert und wörtlich in JSON zurückgegeben. Die API HTML-kodiert Ausgabe nicht.

**Urteil**: **ACCEPTED BY DESIGN** — JSON-APIs geben rohen Inhalt zurück. Die Rendering-Schicht muss vor dem Einfügen in HTML bereinigen.

---

### ATK-09 — Nicht-numerische Post-ID

**Angriff**: Einen String oder Float als `{id}` verwenden.

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**Beobachtet**: `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → keine Zeile → `PostNotFoundException` → `404 Not Found`.
- `1.5` → `findById(1)` → wenn Post 1 existiert, wird dessen Übergang ausgelöst.

**Urteil**: **TEILWEISE BLOCKIERT** — Nicht-numerische Strings werden auf 404 abgebildet. Float-Strings werden stillschweigend abgeschnitten. `ctype_digit()` für strikte ID-Validierung hinzufügen.

---

### ATK-10 — Leerer Titel oder leerer Autor

**Angriff**: Mit leeren Feldern einreichen.

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**Beobachtet**: `trim($body['title']) === ''` und `trim($body['author']) === ''`-Prüfungen greifen → `ValidationException` → `422`.

**Urteil**: **BLOCKIERT** — trim + leere-String-Prüfungen decken sowohl leere als auch nur-Leerzeichen-Werte ab.

---

### ATK-11 — Ablehnen ohne Grund angeben

**Angriff**: Mit leerem Body oder ohne `reason`-Feld ablehnen.

```bash
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject -d '{}'
curl -X POST http://localhost:8200/posts/1/reject -d '{"reason": ""}'
```

**Beobachtet**: Alle drei Fälle erzeugen `null` für `reject_reason`. Ablehnung ohne Grund wird akzeptiert — die Spalte ist nullable.

**Urteil**: **ACCEPTED BY DESIGN** — `reject_reason` ist optional. Für Produktions-Workflows, die einen obligatorischen Ablehnungsgrund erfordern, `if ($reason === null) → 422` hinzufügen.

---

### ATK-12 — Abgelehnten Post ablehnen (doppelte Ablehnung)

**Angriff**: Versuchen, einen bereits abgelehnten Post abzulehnen.

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject  # zweite Ablehnung
```

**Beobachtet**: `canTransitionTo(Rejected)` von `Rejected` → `false` → `409 Conflict`.

**Urteil**: **BLOCKIERT** — `Rejected` ist ein terminaler Zustand; das Enum gibt explizit `false` für alle Übergänge von terminalen Zuständen zurück.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|---------------|--------|
| ATK-01 | Keine Authentifizierung bei approve/reject | EXPONIERT |
| ATK-02 | Ungültiger Übergang (Entwurf genehmigen) | BLOCKIERT |
| ATK-03 | Doppelte Genehmigung | BLOCKIERT |
| ATK-04 | SQL-Injection über Titel/Body | BLOCKIERT |
| ATK-05 | Ungültiger Status-Filterwert | BLOCKIERT |
| ATK-06 | Autor-Imitation | EXPONIERT |
| ATK-07 | Mass Assignment von Status bei Erstellung | BLOCKIERT |
| ATK-08 | XSS-Payload in Inhalt | ACCEPTED BY DESIGN |
| ATK-09 | Nicht-numerische Post-ID | TEILWEISE BLOCKIERT |
| ATK-10 | Leerer Titel oder leerer Autor | BLOCKIERT |
| ATK-11 | Ablehnen ohne Grund (optional) | ACCEPTED BY DESIGN |
| ATK-12 | Doppelte Ablehnung | BLOCKIERT |

**Reale Schwachstellen vor dem Produktionseinsatz zu beheben**:
1. **ATK-01** — Authentifizierung und rollenbasierte Autorisierung hinzufügen (Prüfer-Rolle für approve/reject)
2. **ATK-06** — `author` aus verifizierter Identität ableiten, niemals aus dem Request-Body
3. **ATK-09** — `ctype_digit()`-Schutz für ID-Pfadparameter hinzufügen

---

## Verwandte Anleitungen

- [`state-machine-audit-log.md`](state-machine-audit-log.md) — Zustandsübergang mit Prüfhistorie und InvalidTransitionException
- [`approval-workflow.md`](approval-workflow.md) — Genehmigungsanfrage mit mehreren Genehmigern
- [`step-workflow-approval.md`](step-workflow-approval.md) — Mehrstufiger Workflow mit geordneten Schritten
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — Entwurf/Veröffentlichung-Lebenszyklus-Muster
