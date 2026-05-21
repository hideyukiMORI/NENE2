# How to Build a Guest Order System (Cart → Order → Order Items) with NENE2

This guide walks through building an e-commerce ordering flow where users add products to a cart, check stock, and place an order that captures price snapshots in order items.

**Field Trial**: FT139  
**NENE2 version**: ^1.5  
**Covered topics**: multi-table joins, stock validation, price snapshot in order_items, cart isolation, `array_sum` total calculation

---

## What we're building

- `POST /products` — create a product (name, price, stock)
- `POST /cart` — add a product to cart (accumulates quantity if already present)
- `GET /cart` — view cart contents with total (X-User-Id identifies the user)
- `DELETE /cart/{productId}` — remove an item from the cart
- `POST /orders` — place an order (validates stock, decrements stock, clears cart)
- `GET /orders/{orderId}` — view order details with items (owner only)

---

## Database schema

```sql
CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL DEFAULT 1,
    added_at   TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    total      INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

`UNIQUE (user_id, product_id)` on `cart_items` prevents duplicate rows — adding the same product again accumulates quantity.

---

## Price snapshot in order_items

When an order is placed, the current product `name` and `price` are copied into `order_items`. This protects historical orders from future price changes.

```php
/** @param array<int, array{product_id: int, name: string, price: int, quantity: int}> $items */
public function createOrder(int $userId, array $items, int $total, string $now): int
{
    $this->executor->execute(
        'INSERT INTO orders (user_id, total, created_at) VALUES (?, ?, ?)',
        [$userId, $total, $now],
    );

    $orderId = (int) $this->executor->lastInsertId();

    foreach ($items as $item) {
        $this->executor->execute(
            'INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']],
        );
    }

    return $orderId;
}
```

---

## Cart quantity accumulation

`UNIQUE (user_id, product_id)` means a second `POST /cart` for the same product must UPDATE, not INSERT:

```php
public function addToCart(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->findCartItem($userId, $productId);

    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE cart_items SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $userId, $productId],
        );
        return;
    }

    $this->executor->execute(
        'INSERT INTO cart_items (user_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?)',
        [$userId, $productId, $quantity, $now],
    );
}
```

---

## Stock validation before order placement

Check all items before decrementing any stock. Partial-decrement rollback is complex — validate first, then act:

```php
// Validate all items
foreach ($items as $item) {
    $product = $this->repository->findProductById($item['product_id']);

    if ($product === null || $product['stock'] < $item['quantity']) {
        return $this->responseFactory->create([
            'error'      => 'insufficient stock',
            'product_id' => $item['product_id'],
        ], 422);
    }
}

// Decrement and create order
foreach ($items as $item) {
    $this->repository->decrementStock($item['product_id'], $item['quantity']);
}

$orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
$this->repository->clearCart($actorId);
```

---

## Cart total calculation

```php
$total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));
```

This is computed in PHP from the joined query result, not in SQL. Same calculation is used for the cart preview and the stored order total.

---

## Cart isolation per user

Cart items are always filtered by `user_id`. Each user only sees and modifies their own cart. The `GET /cart` handler returns an empty list for users with no items — never another user's cart.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Adding same product twice creates duplicate rows | `UNIQUE (user_id, product_id)` + UPDATE on conflict |
| Price changes after order placement corrupt history | Copy `name` and `price` into `order_items` at order time |
| Partial stock decrement on multi-item failure | Validate all items first, then decrement all |
| Returning product's live price in order detail | Query `order_items.price`, not `products.price` |
| Cart visible across users | Always filter `cart_items` by `user_id` |
