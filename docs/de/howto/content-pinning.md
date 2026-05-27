# Content Pinning

Implementierungsleitfaden für das Anheften (Pinnen) von Inhalten (Artikeln).
Erläutert geordnetes Anheften, Obergrenzenverwaltung, idempotentes Hinzufügen und automatische Positions-Reindexierung.

## Überblick

- Benutzer heften Artikel an (max. 10)
- `position`-Spalte für Reihenfolgeverwaltung (beginnt bei 1)
- Idempotentes Hinzufügen (200 bei bestehend, 201 bei neu)
- Nach dem Ablösen wird `position` automatisch reindexiert
- Reihenfolge kann über `PUT /pins/order` beliebig geändert werden

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/pins` | Artikel anheften (idempotent) |
| `DELETE` | `/pins/{articleId}` | Ablösen |
| `GET` | `/pins` | Angeheftete Liste (geordnet) |
| `PUT` | `/pins/order` | Reihenfolge ändern |

## Datenbankdesign

```sql
CREATE TABLE pins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,    -- beginnt bei 1, fortlaufende Ganzzahl
    pinned_at TEXT NOT NULL,
    UNIQUE (user_id, article_id), -- jeder Benutzer kann Artikel nur einmal anheften
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## Idempotentes Anheften

```php
public function pin(int $userId, int $articleId, string $now): bool
{
    $existing = $this->findPin($userId, $articleId);
    if ($existing !== null) {
        return false;  // Bereits vorhanden: false = 200
    }
    $nextPosition = $this->maxPosition($userId) + 1;
    $this->executor->execute('INSERT INTO pins ...', [$userId, $articleId, $nextPosition, $now]);
    return true;  // Neu: true = 201
}
```

Rückgabewert `true` → 201 Created, `false` → 200 OK (Aufrufer entscheidet den Status).

## Positions-Reindexierung nach dem Ablösen

```php
public function unpin(int $userId, int $articleId): bool
{
    $removedPosition = (int) $existing['position'];
    $this->executor->execute('DELETE FROM pins WHERE user_id = ? AND article_id = ?', [...]);
    // Elemente nach der gelöschten Position um eins nach vorne verschieben
    $this->executor->execute(
        'UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?',
        [$userId, $removedPosition]
    );
    return true;
}
```

Verhindert Lücken in der Positionsabfolge durch automatische Reindexierung.

## Obergrenzprüfung

```php
if ($this->repository->countPins($actorId) >= $this->repository->maxPins()) {
    $existing = $this->repository->findPin($actorId, $articleId);
    if ($existing === null) {
        return $this->responseFactory->create([
            'error' => 'pin limit reached',
            'max' => $this->repository->maxPins()
        ], 422);
    }
}
```

Bei erneutem Anheften eines bereits angehefteten Artikels (idempotent) wird die Obergrenzprüfung übersprungen.

## Reihenfolge ändern (Reorder)

```php
public function reorder(int $userId, array $orderedArticleIds): bool
{
    $currentPins = $this->listPins($userId);
    $currentIds = array_map(fn (array $p) => (int) $p['article_id'], $currentPins);
    sort($currentIds);
    $sortedInput = $orderedArticleIds;
    sort($sortedInput);
    if ($currentIds !== $sortedInput) {
        return false;  // Stimmt nicht mit aktueller Anheftungsliste überein
    }
    foreach ($orderedArticleIds as $position => $articleId) {
        $this->executor->execute(
            'UPDATE pins SET position = ? WHERE user_id = ? AND article_id = ?',
            [$position + 1, $userId, $articleId]
        );
    }
    return true;
}
```

Wenn `article_ids` nicht exakt mit der aktuellen Anheftungsliste (ungeordnet) übereinstimmt → 422.
Es werden nur Reihenfolgeänderungen akzeptiert, keine Hinzufügungen oder Löschungen.

## GET /pins-Antwort

```json
{
  "pins": [
    {"article_id": 3, "title": "Article 3", "position": 1, "pinned_at": "2026-05-21T10:00:00+00:00"},
    {"article_id": 1, "title": "Article 1", "position": 2, "pinned_at": "2026-05-21T09:00:00+00:00"}
  ],
  "count": 2
}
```

Sortiert nach `position` aufsteigend. Per `ORDER BY p.position ASC` über JOIN abgerufen.

## Oberlimit aufheben (Ablösen ermöglicht neues Anheften)

```
10 angeheftet → DELETE /pins/{id} → 9 → POST /pins ermöglicht Hinzufügen
```

Das Limit wird dynamisch anhand der aktuellen Anzahl bewertet, sodass direkt nach dem Ablösen neu angeheftet werden kann.
