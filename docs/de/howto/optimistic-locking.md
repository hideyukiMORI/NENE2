# Optimistic Locking

Optimistic Locking verhindert das **Lost-Update-Problem** — wenn zwei gleichzeitige Schreiber denselben Datensatz lesen, unabhängige Änderungen vornehmen und der zweite Schreiber die Änderungen des ersten still überschreibt.

Optimistic Locking einsetzen, wenn:
- Konflikte selten sind (die meisten Updates gelingen)
- Nicht-blockierende Lesevorgänge benötigt werden (kein SELECT FOR UPDATE)
- Der Datensatz ein `version`- oder `updated_at`-Feld zur Zustandsverfolgung hat

## Das Lost-Update-Problem

Ohne Locking:

```
Zeit | Schreiber A             | Schreiber B
-----|------------------------|-------------------
  1  | GET /articles/1        | GET /articles/1
     | ← version: 1           | ← version: 1
  2  | [bearbeitet title]     | [bearbeitet body]
  3  | PATCH /articles/1      |
     | title = "A's title"    |
     | ← version: 1, 200 OK   |
  4  |                        | PATCH /articles/1
     |                        | body = "B's body"
     |                        | ← version: 1, 200 OK  ← A's title VERLOREN
```

Schreiber B überschreibt Schreiber As Titeländerung, weil keiner auf gleichzeitige Änderungen geprüft hat.

## Schema

Eine `version`-Spalte hinzufügen, die bei jedem Update inkrementiert:

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

    // WHERE version = $expectedVersion ist die Optimistic-Lock-Prüfung.
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
// ❌ Race Condition: zwei Schreiber lesen version=1, berechnen beide version=2
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ Atomar: Die Datenbank inkrementiert — version ist immer korrekt
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

Die `WHERE version = $expectedVersion`-Prüfung ist der Schutz; `version = version + 1` stellt sicher, dass der neue Wert genau eins mehr als der durch den Schutz bestätigte Wert ist.

## Controller-Integration

Der Client muss die aktuelle `version` lesen und sie bei jedem Update zurücksenden:

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

`current_version` in die 409-Antwort aufzunehmen erlaubt dem Client, ohne extra GET zu wiederholen.

## Antwort-Payload

`version` immer in jede Antwort einschließen, damit Clients stets den aktuellen Wert haben:

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← Client benötigt dies zum Zurücksenden
        'updated_at' => $article->updatedAt,
    ];
}
```

## Optimistic vs. Pessimistic Locking

| | Optimistic | Pessimistic |
|---|---|---|
| Mechanismus | `WHERE version = ?` + 0-Zeilen-Check | `SELECT ... FOR UPDATE` |
| Lese-Blocking | Keines | Blockiert andere Leser |
| Konfliktrate | Niedrig (die meisten Updates gelingen) | Hoher Contention OK |
| Wiederholungskosten | Client wiederholt bei 409 | Wartet auf Lock-Freigabe |
| SQLite-Unterstützung | ✅ | ❌ (nicht unterstützt) |
| Am besten für | Seltene Konflikte, UI-gesteuerte Wiederholungen | Hoher Contention, Must-Succeed-Operationen |

## Code-Review-Checkliste

- [ ] UPDATE enthält `AND version = ?` in der WHERE-Klausel
- [ ] Rückgabewert von `execute()` (betroffene Zeilen) wird geprüft — 0 bedeutet Konflikt oder nicht gefunden
- [ ] 0-Zeilen-Fall unterscheidet „nicht gefunden" von „Versionskonflikt" (extra `findById` im Konfliktpfad)
- [ ] `version = version + 1` wird in SQL berechnet, nicht im PHP-Anwendungscode
- [ ] Jeder Antwort-Payload enthält `version`, damit der Client immer den aktuellen Wert hat
- [ ] 409-Antwort enthält `current_version` für Client-Wiederholung ohne extra GET
- [ ] `version` im Request-Body wird als `int` validiert, nicht als `string` (`is_int()`-Prüfung)
- [ ] Tests decken ab: erfolgreiche Aktualisierung, aufeinanderfolgende Updates, gleichzeitiger Konflikt, Wiederholung nach Konflikt, 404, fehlende Version
