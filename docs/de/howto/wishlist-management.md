# Wunschlisten-Verwaltung

Implementierungsleitfaden für Wunschlisten mit Priorität und Notiz.
Das Muster der verborgenen Existenz, idempotentes Hinzufügen und mehrfache Pfad-Parameter werden erläutert.

## Überblick

- Benutzer erstellen benannte Wunschlisten (öffentlich/privat)
- Jeder Wunschliste können Produkte hinzugefügt werden (`priority`: high/medium/low, optionale `note`)
- Private Wunschlisten geben Nicht-Eigentümern 404 zurück (Muster der verborgenen Existenz)
- Hinzufügen von Produkten ist idempotent (bestehend → 200, neu → 201)
- Keine Reihenfolge (keine Position-Verwaltung) — Hauptunterschied zu Content-Kollektionen (FT149)

## Endpunkte

| Method | Path | Beschreibung |
|---|---|---|
| `POST` | `/wishlists` | Wunschliste erstellen |
| `GET` | `/wishlists/{id}` | Wunschliste abrufen (öffentlich oder eigene) |
| `PUT` | `/wishlists/{id}` | Name und Sichtbarkeit ändern |
| `DELETE` | `/wishlists/{id}` | Wunschliste löschen |
| `POST` | `/wishlists/{id}/items` | Produkt hinzufügen (idempotent) |
| `DELETE` | `/wishlists/{id}/items/{productId}` | Produkt entfernen |

## Datenbankdesign

```sql
CREATE TABLE wishlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE wishlist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wishlist_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    note TEXT,
    added_at TEXT NOT NULL,
    UNIQUE (wishlist_id, product_id),
    CHECK (priority IN ('high', 'medium', 'low')),
    FOREIGN KEY (wishlist_id) REFERENCES wishlists(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

Im Gegensatz zu Content-Kollektionen (FT149) gibt es keine `position`-Spalte.
`UNIQUE (wishlist_id, product_id)` ist die DB-seitige Absicherung für idempotentes Hinzufügen.

## Muster der verborgenen Existenz

```php
$isOwner = $actorId !== null && (int) $wishlist['user_id'] === $actorId;
$isPublic = (bool) $wishlist['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'wishlist not found'], 404);
}
```

Nur GET gibt 404 zurück. PUT/DELETE/POST items geben 403 zurück und signalisieren dem Eigentümer fehlende Berechtigung.

## Idempotentes Hinzufügen von Artikeln

```php
$existing = $this->repository->findItem($id, $productId);
if ($existing !== null) {
    return $this->responseFactory->create([
        'message' => 'already in wishlist',
        'product_id' => $productId,
        'priority' => $existing['priority'],
        'note' => $existing['note'],
    ], 200);
}
$now = date('c');
$this->repository->addItem($id, $productId, $priority, $note, $now);
return $this->responseFactory->create([...], 201);
```

## priority-Validierung (Fallback auf Standardwert bei ungültigem Wert)

```php
private const array VALID_PRIORITIES = ['high', 'medium', 'low'];

$priority = isset($body['priority']) && is_string($body['priority'])
    && in_array($body['priority'], self::VALID_PRIORITIES, true)
    ? $body['priority']
    : 'medium';
```

Ungültige priority-Werte fallen auf `'medium'` zurück statt einen Fehler auszulösen.
So können unbekannte Prioritätswerte, die ein Client aus Gründen der Vorwärtskompatibilität sendet, sicher verarbeitet werden.

## GET /wishlists/{id} Antwortbeispiel

```json
{
  "id": 1,
  "user_id": 1,
  "name": "Birthday Wishlist",
  "is_public": true,
  "item_count": 2,
  "items": [
    {
      "product_id": 3,
      "product_name": "Wireless Headphones",
      "priority": "high",
      "note": "Black color preferred",
      "added_at": "2026-05-21T..."
    },
    {
      "product_id": 1,
      "product_name": "Coffee Mug",
      "priority": "low",
      "note": null,
      "added_at": "2026-05-21T..."
    }
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## Unterschiede zwischen Kollektion und Wunschliste

| Aspekt | Kollektion (FT149) | Wunschliste (FT151) |
|---|---|---|
| Reihenfolge | Position-Verwaltung vorhanden | Keine (Hinzufüge-Reihenfolge) |
| Artikel-Metadaten | Keine | priority + note |
| Obergrenze | 50 Einträge | Keine |
| Anwendungsfall | Leseliste, Kuratierung | Wunschliste, Geschenkregistrierung |

## Eigentümerschaftsprüfungsmuster

```php
if ((int) $wishlist['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Dasselbe Muster wird für PUT/DELETE/POST items verwendet.
