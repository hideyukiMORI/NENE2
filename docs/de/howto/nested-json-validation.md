# How-to: Verschachtelte JSON-Validierung

> **FT-Referenz**: FT322 (`NENE2-FT/nestedlog`) — Bestellungs-API mit verschachtelter Artikel-Validierung, `items.N.field`-Fehlerpfade, Multi-Fehler-Einzelantwort, Fehlercodes, Gesamtberechnung, 19 Tests / 43 Assertions PASS.

Diese Anleitung zeigt, wie verschachtelte JSON-Arrays (z.B. Bestellpositions-Artikel) validiert und strukturierte Fehlerpfade zurückgegeben werden, die genau angeben, welches verschachtelte Feld fehlgeschlagen ist.

## Schema

```sql
CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    total      REAL    NOT NULL DEFAULT 0.0,
    created_at TEXT    NOT NULL
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price REAL    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/orders` | Bestellung mit Artikeln erstellen |
| `GET`  | `/orders` | Bestellungen auflisten |
| `GET`  | `/orders/{id}` | Bestellung mit Artikeln abrufen |

## Bestellung erstellen

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": 1, "quantity": 2, "unit_price": 9.99},
    {"product_id": 2, "quantity": 1, "unit_price": 4.50}
  ]
}
→ 201
{
  "id": 1,
  "customer": "Alice",
  "items": [...],
  "total": 24.48      // 2×9.99 + 1×4.50
}
```

## Verschachtelte Fehlerpfade — `items.N.field`

Jeder Artikelfehler enthält den Array-Index im Feldpfad:

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": "not-an-int", "quantity": 2, "unit_price": 9.99},
    {"product_id": 1, "quantity": 1, "unit_price": -5.0}
  ]
}
→ 422
{
  "errors": [
    {"field": "items.0.product_id", "message": "...", "code": "invalid-type"},
    {"field": "items.1.unit_price",  "message": "...", "code": "min-value"}
  ]
}
```

## Alle Fehler in einer Antwort

Alle Validierungsfehler — sowohl auf oberster Ebene als auch verschachtelt — werden gesammelt und zusammen zurückgegeben. Bei Batch-Übermittlungen niemals einen Fehler nach dem anderen zurückgeben:

```php
POST /orders
{
  "customer": "",      // Fehler: required
  "items": [
    {"product_id": 0, "quantity": -1, "unit_price": 1.0}  // 2 Fehler
  ]
}
→ 422
{
  "errors": [
    {"field": "customer",          "code": "required"},
    {"field": "items.0.product_id","code": "min-value"},
    {"field": "items.0.quantity",  "code": "min-value"}
  ]
}
```

## Validierungsregeln

| Feld | Regel |
|------|-------|
| `customer` | Pflichtfeld, nicht leer, max 200 Zeichen |
| `items` | Pflichtfeld, nicht-leeres Array |
| `items[].product_id` | Integer, ≥ 1 |
| `items[].quantity` | Integer, ≥ 1 |
| `items[].unit_price` | Zahl (int oder float), > 0 |

## Implementierungsmuster

```php
final class OrderValidator
{
    /** @return list<ValidationError> */
    public function validate(array $data): array
    {
        $errors = [];

        // Validierung auf oberster Ebene
        $customer = trim($data['customer'] ?? '');
        if ($customer === '') {
            $errors[] = new ValidationError('customer', 'required', 'required');
        } elseif (strlen($customer) > 200) {
            $errors[] = new ValidationError('customer', 'max 200 chars', 'max-length');
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = new ValidationError('items', 'required non-empty array', 'required');
            return $errors;  // Artikel können nicht weiter validiert werden
        }

        // Verschachtelte Artikel-Validierung mit Index
        foreach ($items as $i => $item) {
            $prefix = "items.{$i}";

            $productId = $item['product_id'] ?? null;
            if (!is_int($productId) || $productId < 1) {
                $errors[] = new ValidationError("{$prefix}.product_id", 'must be int >= 1', 'min-value');
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = new ValidationError("{$prefix}.quantity", 'must be int >= 1', 'min-value');
            }

            $price = $item['unit_price'] ?? null;
            if ((!is_int($price) && !is_float($price)) || $price <= 0) {
                $errors[] = new ValidationError("{$prefix}.unit_price", 'must be number > 0', 'min-value');
            }
        }

        return $errors;
    }
}
```

## Fehlercodes

| Code | Bedeutung |
|------|----------|
| `required` | Feld fehlt oder ist leer |
| `max-length` | Überschreitet maximale Länge |
| `min-value` | Unterhalb des Minimalwerts (int/float) |
| `invalid-type` | Falscher Typ (z.B. String, wo int erwartet wird) |

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Nur ersten Fehler zurückgeben | Client muss N-mal einreichen, Fehler bekommen, korrigieren, erneut einreichen — schreckliche UX für Batch-Formulare |
| Flacher Fehlerpfad `"product_id"` für verschachtelte Artikel | Client kann nicht identifizieren, welcher Artikel (Index 0, 1, ...) fehlgeschlagen ist |
| `unit_price: 0` stillschweigend akzeptieren | Nullpreisartikel korrumpieren Bestellsummen |
| Artikel nur nach bestandener Top-Level-Validierung validieren | Verzögert Feedback; alle Fehler in einem Durchgang sammeln |
| Validierung beim ersten Artikelfehler stoppen | Verbirgt weitere Fehler in verbleibenden Artikeln |
