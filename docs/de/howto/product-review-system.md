# Produkt-Bewertungs- und Rezensionssystem

Implementierung eines Produkt-Bewertungs- und Rezensionssystems in NENE2.
Enthält die Einschränkung 1 Benutzer–1 Produkt–1 Rezension, Bewertungsaggregation (Durchschnitt, Verteilung) und CRUD.

---

## Endpunktübersicht

| Methode | Pfad | Beschreibung | Authentifizierung |
|---|---|---|---|
| `POST` | `/products/{productId}/reviews` | Rezension einreichen | Erforderlich |
| `GET` | `/products/{productId}/reviews` | Rezensionen auflisten (Cursor) | Erforderlich |
| `GET` | `/products/{productId}/reviews/summary` | Durchschnittsbewertung und Verteilung | Erforderlich |
| `PUT` | `/products/{productId}/reviews/{reviewId}` | Rezension aktualisieren | Nur Eigentümer |
| `DELETE` | `/products/{productId}/reviews/{reviewId}` | Rezension löschen | Nur Eigentümer |

---

## DB-Schema

```sql
CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
    body TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (product_id, user_id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## Durchsetzung der 1-Benutzer-1-Produkt-1-Rezension-Einschränkung

Der `UNIQUE(product_id, user_id)`-Constraint verhindert doppelte Rezensionen auf Datenbankebene.

Vorabprüfung auch auf Anwendungsebene mit 409 Conflict:

```php
if ($this->repository->findByProductAndUser($productId, $userId) !== null) {
    return $this->json->create(['error' => 'bereits rezensiert'], 409);
}
```

Nach dem Löschen ist eine erneute Einreichung möglich (UNIQUE-Constraint wird aufgehoben).

---

## Bewertungsvalidierung

```php
// is_int() lehnt Fließkommazahlen ab (JSON 4.5 ist float → 422)
if (!isset($body['rating']) || !is_int($body['rating'])) {
    $errors[] = new ValidationError('rating', 'Bewertung muss eine Ganzzahl sein.', 'required');
} elseif ($body['rating'] < 1 || $body['rating'] > 5) {
    $errors[] = new ValidationError('rating', 'Bewertung muss zwischen 1 und 5 liegen.', 'out_of_range');
}
```

| Wert | Ergebnis |
|---|---|
| `5` (int) | Bestanden |
| `4.5` (float) | 422 |
| `0` | 422 |
| `6` | 422 |
| Ausgelassen | 422 |

---

## Bewertungsaggregation (Summary)

```sql
-- Gesamt und Durchschnitt
SELECT COUNT(*) as total, AVG(rating) as avg_rating
FROM reviews WHERE product_id = ?

-- Verteilung nach Sternanzahl
SELECT COUNT(*) as cnt FROM reviews WHERE product_id = ? AND rating = ?
```

Beispielantwort:

```json
GET /products/1/reviews/summary

{
    "total": 150,
    "avg_rating": 4.23,
    "distribution": {
        "1": 5,
        "2": 8,
        "3": 20,
        "4": 52,
        "5": 65
    }
}
```

Bei 0 Rezensionen: `"avg_rating": null`.

---

## Eigentümerprüfung (Aktualisieren und Löschen)

```php
$review = $this->repository->findReviewById($reviewId);
if ($review === null || (int) $review['product_id'] !== $productId) {
    return $this->json->create(['error' => 'Rezension nicht gefunden'], 404);
}
if ((int) $review['user_id'] !== $userId) {
    return $this->json->create(['error' => 'Verboten'], 403);
}
```

Die Cross-Prüfung der `product_id` gibt 404 zurück, auch wenn eine Rezensions-ID eines anderen Produkts angegeben wird.

---

## Cursor-Pagination

```
GET /products/1/reviews?limit=20
→ { items: [...], next_cursor: 42 }

GET /products/1/reviews?limit=20&before_id=42
→ { items: [...], next_cursor: null }
```

`next_cursor: null` signalisiert das Ende.

---

## Implementierungsbeispiel (FT154)

`/home/xi/docker/NENE2-FT/reviewlog/`
