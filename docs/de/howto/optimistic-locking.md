# Optimistische Sperre

Optimistische Sperre verhindert das **Problem verlorener Updates** — wenn zwei gleichzeitige Schreiber denselben Datensatz lesen, unabhängige Änderungen vornehmen und der zweite Schreiber die Änderungen des ersten stillschweigend überschreibt.

Optimistische Sperre verwenden, wenn:
- Konflikte selten sind (die meisten Updates erfolgreich)
- Nicht-blockierende Lesevorgänge benötigt werden (kein SELECT FOR UPDATE)
- Der Datensatz ein `version`- oder `updated_at`-Feld zum Verfolgen seines Zustands hat

## Das Problem verlorener Updates

Ohne Sperre:

```
Zeit | Schreiber A           | Schreiber B
-----|----------------------|-------------------
  1  | GET /articles/1      | GET /articles/1
     | ← version: 1         | ← version: 1
  2  | [bearbeitet Titel]   | [bearbeitet Body]
  3  | PATCH /articles/1    |
     | title = "A's Titel"  |
     | ← version: 1, 200 OK |
  4  |                      | PATCH /articles/1
     |                      | body = "B's Body"
     |                      | ← version: 1, 200 OK  ← A's Titel VERLOREN
```

Schreiber B überschreibt Schreiber A's Titeländerung, weil keiner auf gleichzeitige Modifikation geprüft hat.

## Schema

Eine `version`-Spalte hinzufügen, die bei jedem Update inkrementiert wird:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
```

## Repository-Implementierung

```php
/**
 * @throws ConflictException wenn ein anderer Schreiber den Datensatz zuerst aktualisiert hat
 * @throws \RuntimeException wenn der Artikel nicht existiert
 */
public function update(int $id, string $title, string $body, int $expectedVersion): Article
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    // WHERE version = $expectedVersion ist die optimistische Sperr-Prüfung.
    // Wenn ein anderer Schreiber die Version bereits inkrementiert hat, trifft dieses UPDATE 0 Zeilen.
    $affected = $this->executor->execute(
        'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // 0 aktualisierte Zeilen: entweder nicht gefunden ODER Versionskonflikt — unterscheiden
        $current = $this->findById($id);
        if ($current === null) {
            throw new \RuntimeException("Article {$id} does not exist.");
        }
        throw new ConflictException($id, $expectedVersion);
    }

    return new Article(id: $id, title: $title, body: $body, version: $expectedVersion + 1, updatedAt: $now);
}
```

### Warum `version = version + 1` in SQL (nicht in PHP)

```php
// ❌ Race Condition: zwei Schreiber lesen beide version=1, berechnen beide version=2
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ Atomar: die Datenbank inkrementiert — version ist immer korrekt
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

Die `WHERE version = $expectedVersion`-Prüfung ist der Guard; `version = version + 1` stellt sicher, dass der neue Wert genau eines mehr ist als was den Guard passiert hat.

## Controller-Integration

Der Client muss die aktuelle `version` lesen und sie mit jedem Update zurückschicken:

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $id   = (int) Router::param($request, 'id');
    $body = json_decode((string) $request->getBody(), true);

    if (!is_array($body) || !is_int($body['version'] ?? null)) {
        return $this->problems->create($request, 'invalid-body', 'version (int) is required.', 400);
    }

    try {
        $article = $this->repo->update($id, $body['title'], $body['body'], $body['version']);
        return $this->json->create($this->serialize($article));
    } catch (ConflictException $e) {
        $current = $this->repo->findById($id);
        return $this->problems->create(
            $request,
            'conflict',
            'Optimistic lock conflict.',
            409,
            $e->getMessage(),
            $current !== null ? ['current_version' => $current->version] : [],
        );
    } catch (\RuntimeException) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }
}
```

## Client-Ablauf

```
POST /articles            → 201 { id: 1, version: 1, ... }
GET /articles/1           → 200 { id: 1, version: 1, ... }

PATCH /articles/1         → 200 { id: 1, version: 2, ... }
  { title: "...", version: 1 }

PATCH /articles/1         → 409 { type: "conflict", current_version: 2 }
  { title: "...", version: 1 }   (veraltete Version — Konflikt!)

PATCH /articles/1         → 200 { id: 1, version: 3, ... }
  { title: "...", version: 2 }   (neu abrufen oder current_version aus 409 verwenden)
```

`current_version` in der 409-Antwort zu inkludieren lässt den Client ohne extra GET erneut versuchen.

## Antwort-Payload

`version` immer in jeder Antwort einschließen, damit Clients immer den aktuellen Wert haben:

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← Client braucht dies zum Zurücksenden
        'updated_at' => $article->updatedAt,
    ];
}
```

## Optimistische vs. Pessimistische Sperre

| | Optimistisch | Pessimistisch |
|---|---|---|
| Mechanismus | `WHERE version = ?` + 0-Zeilen-Prüfung | `SELECT ... FOR UPDATE` |
| Lese-Blockierung | Keine | Blockiert andere Leser |
| Konfliktrate | Niedrig (die meisten Updates erfolgreich) | Hohe Konkurrenz OK |
| Neuversuchskosten | Client versucht bei 409 erneut | Wartet auf Lock-Freigabe |
| SQLite-Unterstützung | ✅ | ❌ (nicht unterstützt) |
| Am besten für | Seltene Konflikte, UX-gesteuerte Neuversuche | Hohe Konkurrenz, Muss-gelingen-Operationen |

## Code-Review-Checkliste

- [ ] UPDATE enthält `AND version = ?` in der WHERE-Klausel
- [ ] `execute()`-Rückgabewert (betroffene Zeilen) wird geprüft — 0 bedeutet Konflikt oder nicht gefunden
- [ ] 0-Zeilen-Fall unterscheidet "nicht gefunden" von "Versionskonflikt" (extra `findById` auf Konfliktpfad)
- [ ] `version = version + 1` wird in SQL berechnet, nicht im PHP-Anwendungscode
- [ ] Jeder Antwort-Payload enthält `version`, damit der Client immer den aktuellen hat
- [ ] 409-Antwort enthält `current_version` für Client-Neuversuch ohne extra GET
- [ ] `version` im Request-Body wird als `int`, nicht als `string` validiert (`is_int()`-Prüfung)
- [ ] Tests decken ab: erfolgreiche Aktualisierung, sukzessive Aktualisierungen, gleichzeitiger Konflikt, Neuversuch nach Konflikt, 404, fehlende Version
