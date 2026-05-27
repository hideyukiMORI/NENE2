# Content-Collection

Implementierungsleitfaden für ein System kuratierter Artikel-Collections (öffentlich/privat).
Erläutert Sichtbarkeitseinstellungen, IDOR-Prävention (Existenzvertauschlichung), idempotentes Hinzufügen und Positions-Reindexierung nach dem Löschen.

## Überblick

- Benutzer erstellen benannte Collections (Listen)
- Jeder Collection werden Artikel hinzugefügt (max. 50)
- `is_public`-Flag: Öffentliche Collections sind für jedermann einsehbar
- Private Collections geben 404 für Nicht-Eigentümer zurück (Existenzvertauschlichungs-Muster)
- Artikel hinzufügen ist idempotent (200 bei bestehend, 201 bei neu)
- `position` wird nach dem Löschen eines Elements automatisch reindexiert

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/collections` | Collection erstellen |
| `GET` | `/collections/{id}` | Collection abrufen (öffentlich oder eigene) |
| `PUT` | `/collections/{id}` | Collection-Name / Sichtbarkeit ändern |
| `DELETE` | `/collections/{id}` | Collection löschen |
| `POST` | `/collections/{id}/items` | Artikel hinzufügen (idempotent) |
| `DELETE` | `/collections/{id}/items/{articleId}` | Artikel entfernen |

## Datenbankdesign

```sql
CREATE TABLE collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,  -- 0=privat, 1=öffentlich
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    added_at TEXT NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## Existenzvertauschlichungs-Muster (IDOR-Prävention)

Zugriff auf private Collections gibt **404** zurück, nicht 403.
Da 403 die Information "existiert, aber keine Berechtigung" preisgeben würde.

```php
if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

## Idempotentes Hinzufügen von Elementen

```php
$existing = $this->repository->findItem($id, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create(['message' => 'already in collection', 'article_id' => $articleId], 200);
}

$count = $this->repository->countItems($id);
if ($count >= CollectionRepository::maxItems()) {
    return $this->responseFactory->create(['error' => 'collection is full', 'max' => 50], 422);
}

$this->repository->addItem($id, $articleId, date('c'));
return $this->responseFactory->create(['message' => 'article added', 'article_id' => $articleId], 201);
```

Die Obergrenzprüfung wird nur ausgeführt, wenn der Artikel noch nicht hinzugefügt wurde.
Bereits hinzugefügte Artikel beeinflussen das Limit nicht (idempotenter Aufruf).

## Positions-Reindexierung nach dem Löschen eines Elements

```php
public function removeItem(int $collectionId, int $articleId): void
{
    $item = $this->findItem($collectionId, $articleId);
    $removedPosition = (int) $item['position'];
    $this->executor->execute('DELETE FROM collection_items WHERE collection_id = ? AND article_id = ?', ...);
    $this->executor->execute(
        'UPDATE collection_items SET position = position - 1 WHERE collection_id = ? AND position > ?',
        [$collectionId, $removedPosition]
    );
}
```

Elemente nach der gelöschten Position werden um eins nach vorne geschoben, um die Lücke zu schließen.

## Beispielantwort für GET /collections/{id}

```json
{
  "id": 1,
  "user_id": 1,
  "name": "My Reading List",
  "is_public": false,
  "item_count": 2,
  "items": [
    {"article_id": 3, "title": "Article 3", "position": 1, "added_at": "2026-05-21T..."},
    {"article_id": 1, "title": "Article 1", "position": 2, "added_at": "2026-05-21T..."}
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## Eigentumsrecht-Prüfungs-Muster

Bei allen Änderungs-Endpunkten (PUT/DELETE/POST items) wird eine Eigentümerprüfung durchgeführt:

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Bei GET wird basierend auf Sichtbarkeit und Eigentumsrecht zwischen 404/200 unterschieden, ohne 403 zu verwenden.
