---
title: "How-to: Shopping Cart API"
category: product
tags: [shopping-cart, upsert, integer-money, per-user]
difficulty: intermediate
related: [product-catalog, money-integer-arithmetic, order-management]
---

# How-to: Shopping Cart API

> **FT reference**: FT269 (`NENE2-FT/cartlog`) — Shopping cart: UNIQUE (user_id, product_id) per-user cart, upsert add-item (quantity accumulation), quantity=0 auto-remove semantics, integer price/subtotal, X-User-Id header identification
>
> Also validated in FT155 (`NENE2-FT/cartlog` precursor) — same cart pattern, SQLite, PHP 8.4.

Demonstrates a stateful per-user shopping cart: add items (with quantity accumulation on re-add), update quantities, remove items, and view a running total. All prices are stored as integers (cents or base units) — never floats.

---

## Routes

| Method   | Path                        | Description                              |
|----------|-----------------------------|------------------------------------------|
| `GET`    | `/cart`                     | List cart contents with subtotals and total |
| `POST`   | `/cart/items`               | Add a product (quantity accumulates if already in cart) |
| `PUT`    | `/cart/items/{productId}`   | Set quantity (0 = remove item)           |
| `DELETE` | `/cart/items/{productId}`   | Remove a specific item                   |
| `DELETE` | `/cart`                     | Clear the entire cart                    |

---

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL CHECK (price >= 0),
    stock      INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL CHECK (quantity > 0),
    added_at   TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

Key design choices:
- `UNIQUE (user_id, product_id)` — one row per (user, product) pair. Re-adding the same product accumulates quantity rather than inserting a duplicate row.
- `price INTEGER` — stored in smallest currency unit (e.g., cents). Never use `FLOAT` for money.
- `quantity INTEGER CHECK (quantity > 0)` — zero-quantity rows are deleted, not stored.
- No FK on `cart_items.price` — the price is read from `products.price` at query time (JOIN), not stored in the cart. If product price changes, the cart reflects the new price.

---

## Upsert add-item pattern

Adding an item that already exists in the cart accumulates quantity:

```php
public function addItem(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->db->fetchOne(
        'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
        [$userId, $productId],
    );

    if ($existing !== null) {
        $newQty = (int) $existing['quantity'] + $quantity;
        $this->db->execute(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?',
            [$newQty, $now, $existing['id']],
        );
    } else {
        $this->db->execute(
            'INSERT INTO cart_items (user_id, product_id, quantity, added_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $productId, $quantity, $now, $now],
        );
    }
}
```

The SELECT-then-INSERT/UPDATE pattern avoids `INSERT OR REPLACE` (which changes the `id` and `added_at`) and avoids `ON CONFLICT DO UPDATE` (not portable across all DB engines). The `UNIQUE (user_id, product_id)` constraint still guards against a race-condition duplicate INSERT.

Response status: `201 Created` if the item was new; `200 OK` if quantity was accumulated on an existing item.

---

## Quantity=0 auto-remove semantics

`PUT /cart/items/{productId}` with `quantity: 0` removes the item rather than storing a zero-quantity row:

```php
if ($quantity === 0) {
    $this->repo->removeItem($userId, $productId);
    return $this->json->createEmpty(204);
}

$this->repo->updateQuantity($userId, $productId, $quantity, $now);
```

This matches common shopping cart UX: dragging the stepper to zero removes the item. The DB `CHECK (quantity > 0)` also enforces this at the storage level.

---

## Cart total: JOIN + loop calculation

The cart response includes a real-time total computed from the JOIN result:

```php
public function getCart(int $userId): array
{
    return $this->db->fetchAll(
        'SELECT ci.id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                p.name AS product_name, p.price
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         WHERE ci.user_id = ?
         ORDER BY ci.added_at ASC, ci.id ASC',
        [$userId],
    );
}
```

```php
$items = $this->repo->getCart($userId);
$total = 0;
$formatted = [];

foreach ($items as $item) {
    $subtotal = (int) $item['price'] * (int) $item['quantity'];
    $total   += $subtotal;
    $formatted[] = $this->formatItem($item, $subtotal);
}

return $this->json->create([
    'items' => $formatted,
    'total' => $total,
    'count' => count($formatted),
]);
```

Both `price` and `subtotal` are integers. The API consumer divides by 100 for display (e.g., `1999` → `$19.99`).

---

## User identification via X-User-Id header

The FT uses a simple `X-User-Id` header (no JWT) to identify the cart owner:

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $header = $request->getHeaderLine('X-User-Id');
    if ($header === '') {
        return null;
    }
    $id = (int) $header;
    return $id > 0 ? $id : null;
}
```

The handler verifies that the user exists in the `users` table before proceeding:
```php
if ($this->repo->findUserById($userId) === null) {
    return $this->json->create(['error' => 'User not found'], 404);
}
```

**Production note**: Replace `X-User-Id` with a verified JWT or session token. The header is trivially spoofable — any caller can claim any `user_id`. Use `X-User-Id` only in trusted internal service-to-service contexts, never for public APIs.

---

## Validation

```php
// POST /cart/items body validation
private function parseAddBody(array $body): array
{
    $errors = [];

    if (!isset($body['product_id']) || !is_int($body['product_id'])) {
        $errors[] = new ValidationError('product_id', 'product_id must be an integer', 'invalid_type');
    }

    $productId = isset($body['product_id']) && is_int($body['product_id']) ? $body['product_id'] : 0;
    if ($productId <= 0 && $errors === []) {
        $errors[] = new ValidationError('product_id', 'product_id must be positive', 'invalid_value');
    }

    if (!isset($body['quantity']) || !is_int($body['quantity'])) {
        $errors[] = new ValidationError('quantity', 'quantity must be an integer', 'invalid_type');
    }

    $quantity = isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0;
    if ($quantity <= 0 && !isset($errors[1])) {
        $errors[] = new ValidationError('quantity', 'quantity must be positive', 'invalid_value');
    }

    return [$productId, $quantity, $errors];
}
```

Type checks (`is_int`) reject float or string quantities — `"3"` and `3.0` are both invalid.

---

## Example responses

**GET /cart**:
```json
{
    "items": [
        {
            "id": 1,
            "product_id": 5,
            "product_name": "Widget",
            "price": 999,
            "quantity": 2,
            "subtotal": 1998,
            "added_at": "2026-01-01T10:00:00Z",
            "updated_at": "2026-01-01T10:00:00Z"
        }
    ],
    "total": 1998,
    "count": 1
}
```

---

## AppFactory wiring example

Bootstrap the app for tests or a lightweight entry point:

```php
class AppFactory
{
    public static function createSqlite(string $dbFile): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbFile,
            user: '',
            password: '',
            charset: '',
        );
        return self::build($dbConfig);
    }

    private static function build(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory    = new PdoConnectionFactory($dbConfig);
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $psr17      = new Psr17Factory();
        $repo       = new CartRepository($executor);
        $json       = new JsonResponseFactory($psr17, $psr17);
        $registrar  = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
```

Using `RuntimeApplicationFactory` automatically provides: validation-exception → 422 mapping, error handling, and security headers.

---

## Testing patterns

```php
// Re-adding the same product accumulates quantity
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
$res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$this->assertSame(200, $res->getStatusCode());
$data = $this->json($res);
$this->assertSame(5, $data['quantity']);

// Each user's cart is isolated
$this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
$res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
$this->assertSame(0, $this->json($res)['count']);
```

> **SQLite FK caveat**: `PdoConnectionFactory` sets `PRAGMA foreign_keys = ON`. When seeding test data via a separate PDO instance, set the same pragma on that connection — otherwise JOINs silently drop rows whose FK targets were inserted via a different connection handle.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store `price` in `cart_items` at add time | Stale price if product price changes; refund / overcharge disputes |
| Use `FLOAT` for price | Floating-point rounding errors in financial totals |
| Use `X-User-Id` in a public API | Trivially spoofable; use JWT/session instead |
| Allow `quantity: 0` to store a zero row | Violates `CHECK (quantity > 0)`; confusing semantics |
| Use `INSERT OR REPLACE` for upsert | Resets `id` and `added_at`; breaks order-preserving sort |
| No `UNIQUE (user_id, product_id)` constraint | Race condition creates duplicate cart rows |
