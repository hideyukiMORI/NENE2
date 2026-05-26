# How-to: Order Management API

This guide shows how to build a multi-item order management API with NENE2.
Pattern demonstrated by the **orderlog** field trial (FT215).

## Features

- Create orders with line items (SKU + quantity + unit price)
- Automatic total calculation from items
- Status lifecycle: `pending → confirmed → shipped → delivered → cancelled`
- User-scoped IDOR protection (returns 404, not 403, to hide existence)
- Admin override for cross-user operations
- Atomic cancel with conflict detection (cannot cancel `cancelled` or `delivered`)

## Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    total_cents INTEGER NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    sku         TEXT    NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_cents  INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/orders` | Create order with items |
| `GET` | `/orders/{id}` | Get order with items (owner or admin) |
| `POST` | `/orders/{id}/cancel` | Cancel order (owner or admin) |
| `GET` | `/users/{userId}/orders` | List orders for a user (self or admin) |

## Item Validation

```php
/** SKU: uppercase alphanumeric and hyphens, 1–32 chars */
private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';

// Per item:
// - sku: must match SKU_PATTERN
// - quantity: integer 1–9999
// - unit_cents: non-negative integer
// Max 50 items per order
```

## Repository Pattern

```php
/**
 * @param list<array{sku: string, quantity: int, unit_cents: int}> $items
 * @return array<string, mixed>
 */
public function create(int $userId, string $note, array $items): array
{
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += $item['quantity'] * $item['unit_cents'];
    }
    // INSERT order, then INSERT items, return findById()
}

/** @return 'not_found'|'not_cancellable'|'ok' */
public function cancel(int $id, int $userId, bool $isAdmin): string
{
    // Returns 'not_found' for wrong user (IDOR protection)
    // Returns 'not_cancellable' for cancelled/delivered orders
}
```

## IDOR Protection

User-scoped endpoints return `404` (not `403`) when a user accesses another user's resource:

```php
// GET /orders/{id}
if (!$isAdmin && (int) $order['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Order not found.');
}

// GET /users/{userId}/orders
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## Cancel with Match Expression

```php
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);

return match ($result) {
    'not_found'       => $this->problem(404, 'not-found', 'Order not found.'),
    'not_cancellable' => $this->problem(409, 'conflict', 'Order cannot be cancelled.'),
    default           => $this->json(['message' => 'Order cancelled.']),
};
```

## Security Patterns

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` before `hash_equals()`
- **`ctype_digit()`**: ReDoS-safe integer validation for path and header IDs
- **`is_int()`**: Strict type check — rejects floats (e.g., `1.5`) passed as JSON
- **Max items guard**: Limits to 50 items to prevent oversized payloads
