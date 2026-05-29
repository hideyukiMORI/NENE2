---
title: "How-to: Inventory Management API"
category: product
tags: [inventory, stock, adjustment, history]
difficulty: intermediate
related: [inventory-stock-management, order-management]
ft: FT220
---

# How-to: Inventory Management API

This guide shows how to build an inventory / stock management API with stock adjustments and history tracking using NENE2.
Pattern demonstrated by the **inventorylog** field trial (FT220, ATK cracker attack test).

## Features

- Create inventory items with SKU, name, price, and initial quantity (admin only)
- Get item details (public)
- Adjust stock with signed delta (positive = restock, negative = consume)
- Insufficient stock detection → 409 Conflict
- Full adjustment history log

## Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sku          TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 0,
    price_cents  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_logs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id        INTEGER NOT NULL,
    delta          INTEGER NOT NULL,
    reason         TEXT    NOT NULL DEFAULT '',
    quantity_after INTEGER NOT NULL,
    created_at     TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/items` | Admin | Create inventory item |
| `GET` | `/items/{id}` | Public | Get item with current stock |
| `POST` | `/items/{id}/adjust` | Admin | Adjust stock (delta ± N) |
| `GET` | `/items/{id}/history` | Public | Get adjustment history |

## Stock Adjustment Pattern

```php
/** @return 'ok'|'not_found'|'insufficient_stock' */
public function adjust(int $id, int $delta, string $reason): string
{
    $item = $this->findById($id);
    if ($item === null) return 'not_found';

    $newQty = (int) $item['quantity'] + $delta;
    if ($newQty < 0) return 'insufficient_stock'; // → 409

    // Atomic update + log
    $this->pdo->prepare(
        'UPDATE items SET quantity = :qty, updated_at = :now WHERE id = :id'
    )->execute([':qty' => $newQty, ':now' => $now, ':id' => $id]);

    $this->pdo->prepare(
        'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES ...'
    )->execute([...]);

    return 'ok';
}
```

## Delta Validation

```php
$delta = $body['delta'] ?? null;
if (!is_int($delta) || $delta === 0 || abs($delta) > self::MAX_QUANTITY) {
    return $this->problem(422, 'validation-failed', 'delta must be a non-zero integer with |delta| ≤ 1000000.');
}
```

## ATK Cracker Test Results (FT220)

- **ATK-01**: SQL injection in SKU → blocked by `/\A[A-Z0-9\-]{1,32}\z/` pattern (422)
- **ATK-01**: SQL injection in path ID → blocked by `ctype_digit()` (404)
- **ATK-02**: Integer overflow in `price_cents` → float rejected by `is_int()` (422)
- **ATK-03**: Oversized path ID → `strlen > 18` guard (404)
- **ATK-04**: Drain-to-zero boundary → allowed (quantity = 0 is valid)
- **ATK-05**: Oversized `quantity` (> 1,000,000) → rejected (422)
- **ATK-06**: Wrong/empty admin key → 403 (fail-closed)
- **ATK-09**: Over-drain attack → `insufficient_stock` → 409, stock unchanged
- **ATK-10**: Float `delta` → rejected by `is_int()` (422)
- **ATK-11**: No-body request → 400 (JSON body required)
- **ATK-12**: Error responses contain no SQLSTATE/stack traces/internal paths

## Security Patterns

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` before `hash_equals()`
- **`is_int()` strict checks**: price_cents, quantity, delta — rejects floats from JSON
- **`ctype_digit()`**: ReDoS-safe integer validation for path IDs
- **SKU pattern**: `/\A[A-Z0-9\-]{1,32}\z/` blocks SQL injection attempts
- **Atomic operations**: update + log insert in sequence (within single connection)
