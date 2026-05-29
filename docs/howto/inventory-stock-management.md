---
title: "How-to: Inventory Stock Management"
category: product
tags: [inventory, stock, sku, transactions]
difficulty: intermediate
related: [inventory-management, order-management]
---

# How-to: Inventory Stock Management

## Overview

This guide covers building an inventory management API with NENE2. Features include SKU-based item registration, stock-in/stock-out operations, negative stock prevention, and transaction history.

**Reference implementation**: `../NENE2-FT/inventorylog/`

---

## Schema Design

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,   -- 'in' | 'out'
    quantity   INTEGER NOT NULL,
    note       TEXT,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);
```

---

## Route Table

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/inventory/items` | Register item (SKU + name) |
| `GET` | `/inventory/items` | List all items |
| `GET` | `/inventory/items/{id}` | Get item by ID |
| `POST` | `/inventory/items/{id}/in` | Stock in (receive) |
| `POST` | `/inventory/items/{id}/out` | Stock out (ship) |
| `GET` | `/inventory/items/{id}/history` | Transaction history |

---

## SKU Validation

Restrict SKU format to prevent injection and ensure canonical form:

```php
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/', $sku)) {
    return $this->problem(422, 'validation-failed', 'sku must be uppercase alphanumeric (max 32).');
}
```

---

## Stock Operations

### Stock In

Always safe — just increment:

```php
$this->pdo->prepare('UPDATE items SET stock = stock + :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

### Stock Out (with insufficient-stock guard)

```php
if ((int) $item['stock'] < $quantity) {
    return 'insufficient_stock';   // → 409 Conflict
}
$this->pdo->prepare('UPDATE items SET stock = stock - :qty, updated_at = :now WHERE id = :id')
    ->execute([':qty' => $quantity, ':now' => $now, ':id' => $itemId]);
```

---

## Quantity Validation

Reject non-integer and non-positive quantities:

```php
$qty = $body['quantity'] ?? null;
if (!is_int($qty) || $qty <= 0) {
    return [0, null, 'quantity must be a positive integer.'];
}
```

This catches both `"50"` (string) and `-1` (negative).

---

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Item created | 201 |
| Stock added / reduced | 200 |
| Item / history found | 200 |
| Missing or empty field | 422 |
| Invalid SKU format | 422 |
| Non-integer or negative quantity | 422 |
| Item not found | 404 |
| Duplicate SKU | 409 |
| Insufficient stock | 409 |

---

## Notes

- **Atomic updates**: Use `stock = stock + :qty` and `stock = stock - :qty` in SQL to keep the balance consistent even under concurrent access.
- **Audit trail**: Every stock change writes a `stock_history` row for traceability.
- **Soft constraint**: The application checks stock before decrementing. For strict correctness under concurrency, add a `CHECK (stock >= 0)` column constraint in the DB or use transactions with row locking.
