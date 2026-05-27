# How-to: Nested JSON Validation

> **FT reference**: FT322 (`NENE2-FT/nestedlog`) — Order API with nested items validation, `items.N.field` error paths, multi-error single response, error codes, total calculation, 19 tests / 43 assertions PASS.

This guide shows how to validate nested JSON arrays (e.g. order line items) and return structured error paths that identify exactly which nested field failed.

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

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/orders` | Create order with items |
| `GET`  | `/orders` | List orders |
| `GET`  | `/orders/{id}` | Get order with items |

## Create Order

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

## Nested Error Paths — `items.N.field`

Each item error includes the array index in the field path:

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

## All Errors in One Response

All validation failures — both top-level and nested — are collected and returned together. Never return one error at a time for batch submissions:

```php
POST /orders
{
  "customer": "",      // error: required
  "items": [
    {"product_id": 0, "quantity": -1, "unit_price": 1.0}  // 2 errors
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

## Validation Rules

| Field | Rule |
|-------|------|
| `customer` | Required, non-blank, max 200 chars |
| `items` | Required, non-empty array |
| `items[].product_id` | Integer, ≥ 1 |
| `items[].quantity` | Integer, ≥ 1 |
| `items[].unit_price` | Number (int or float), > 0 |

## Implementation Pattern

```php
final class OrderValidator
{
    /** @return list<ValidationError> */
    public function validate(array $data): array
    {
        $errors = [];

        // Top-level validation
        $customer = trim($data['customer'] ?? '');
        if ($customer === '') {
            $errors[] = new ValidationError('customer', 'required', 'required');
        } elseif (strlen($customer) > 200) {
            $errors[] = new ValidationError('customer', 'max 200 chars', 'max-length');
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = new ValidationError('items', 'required non-empty array', 'required');
            return $errors;  // can't validate items further
        }

        // Nested item validation with index
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

## Error Codes

| Code | Meaning |
|------|---------|
| `required` | Field is missing or blank |
| `max-length` | Exceeds maximum length |
| `min-value` | Below minimum value (int/float) |
| `invalid-type` | Wrong type (e.g. string where int expected) |

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return only first error | Client must submit, get error, fix, resubmit N times — terrible UX for batch forms |
| Flat error path `"product_id"` for nested items | Client cannot identify which item (index 0, 1, ...) failed |
| Accept `unit_price: 0` silently | Zero-price items corrupt order totals |
| Validate items only after top-level passes | Delays feedback; collect all errors in one pass |
| Stop validation on first item error | Mask further errors in remaining items |
