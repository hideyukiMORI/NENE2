# Idempotency-Key Pattern

Prevent duplicate orders or resource creation caused by network retries by requiring clients to supply an `Idempotency-Key` header on every state-changing request.

## Why it matters

When a client sends `POST /orders` and the network drops before it receives a response, it will retry. Without idempotency, that retry creates a second order. With an `Idempotency-Key`, the server can detect the retry and return the original result instead of creating a duplicate.

Stripe, GitHub, and many other production APIs use this exact pattern.

## Database schema

Add a `UNIQUE` constraint on the idempotency key column. This single constraint handles the race condition described below.

```sql
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    created_at       TEXT    NOT NULL
);
```

## Handler implementation

```php
// 1. Read and validate the header
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create(
        $request,
        'missing-idempotency-key',
        'Idempotency-Key header is required for this endpoint.',
        [],
        422,
    );
}

// 2. Check for an existing entry (replay path)
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // replay — return original with 200
}

// 3. Validate the request body
$body = json_decode((string) $request->getBody(), true);
// ... validate fields ...

// 4. Create — UNIQUE constraint handles the race condition
try {
    $order = $repo->create($key, $item, $quantity, $totalPrice);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    // Another request with the same key won the race — return its result
    $existing = $repo->findByIdempotencyKey($key);
    if ($existing !== null) {
        return $json->create($existing->toArray(), 200);
    }
    return $problems->create($request, 'conflict', 'Conflict.', [], 409);
}
```

## Repository

```php
public function findByIdempotencyKey(string $key): ?Order
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM orders WHERE idempotency_key = ?',
        [$key],
    );
    return $row !== null ? Order::fromRow($row) : null;
}

public function create(string $key, string $item, int $quantity, float $totalPrice): Order
{
    // Throws DatabaseConstraintException on UNIQUE violation (race condition)
    $this->executor->insert(
        'INSERT INTO orders (idempotency_key, item, quantity, total_price, created_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $item, $quantity, $totalPrice, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
    );
    // ...
}
```

## Key design decisions

### Replay returns 200, not 201

The second request is a replay, not a creation. Using `200 OK` tells the client "you've seen this before" without creating confusion about what was created.

### Replay ignores the body

If the client sends the same `Idempotency-Key` with a different body, the **original** result is returned. The server treats a matching key as proof that the request was already processed, regardless of what the body says.

```
POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1}

POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1}   ← original order, body ignored
```

This is intentional. If the client wants to create a genuinely different resource, it must use a new key.

### UNIQUE constraint as the race condition guard

Two concurrent requests with the same key will race. The DB's `UNIQUE` constraint ensures only one insert succeeds. The loser catches `DatabaseConstraintException` and fetches the winner's row.

## What clients should use as the key

UUID v4 is the most common choice. The client generates the key before sending the request and stores it locally so it can retry with the same key if needed.

```js
// Client-side (JavaScript)
const key = crypto.randomUUID();
const response = await fetch('/orders', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': key,
    },
    body: JSON.stringify({ item: 'Widget', quantity: 1, price: 9.99 }),
});
```

## Reading the header

PSR-7 header names are case-insensitive. `getHeaderLine('Idempotency-Key')`, `getHeaderLine('idempotency-key')`, and `getHeaderLine('IDEMPOTENCY-KEY')` all return the same value. NENE2 uses Nyholm/PSR-7 which implements this correctly.
