---
title: "How-to: Idempotency-Key Pattern"
category: api-design
tags: [idempotency, deduplication, race-condition, retry]
difficulty: intermediate
related: [idempotency-key, idempotency-key-api, request-deduplication]
ft: FT276
---

# How-to: Idempotency-Key Pattern

> **FT reference**: FT276 (`NENE2-FT/csrflog`) — Idempotency-Key header for state-changing requests: UNIQUE DB constraint, replay returns original result (200), body changes on replay are ignored, race condition handled by DatabaseConstraintException, 15 tests / 30 assertions PASS.
>
> **ATK assessment**: ATK-01 through ATK-12 included at the end of this document.

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

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Omit Idempotency-Key to bypass duplicate check 🚫 BLOCKED

**Attack**: Send `POST /orders` without the `Idempotency-Key` header.
**Result**: BLOCKED — `trim($request->getHeaderLine('Idempotency-Key')) === ''` → 422 with `missing-idempotency-key` problem detail. No order is created.

---

### ATK-02 — Send empty Idempotency-Key 🚫 BLOCKED

**Attack**: Send `Idempotency-Key: ` (whitespace only).
**Result**: BLOCKED — `trim()` reduces whitespace-only strings to `''` → same 422 as ATK-01.

---

### ATK-03 — Replay with modified body to change order content 🚫 BLOCKED

**Attack**: Send `POST /orders` with key `uuid-abc` and `{quantity: 1}`. On replay, use the same key with `{quantity: 99}`.
**Result**: BLOCKED — the server finds the existing row by `idempotency_key` and returns it immediately, before reading the body. The new body is never processed.

---

### ATK-04 — Create two orders with different keys 🚫 BLOCKED (intended)

**Attack**: Use two different `Idempotency-Key` values to legitimately create two orders.
**Result**: SAFE (by design) — different keys are different requests. Both orders are created. This is the intended behavior: idempotency is per-key, not per-body.

---

### ATK-05 — Race condition: two concurrent requests with the same key 🚫 BLOCKED

**Attack**: Send two identical requests concurrently before either completes.
**Result**: BLOCKED — both requests pass the `findByIdempotencyKey` check (no existing row yet), but only one INSERT succeeds. The loser catches `DatabaseConstraintException`, fetches the winner's row, and returns it with 200. The UNIQUE constraint is the race guard.

---

### ATK-06 — Negative quantity injection 🚫 BLOCKED

**Attack**: Send `{item: "widget", quantity: -1, price: 9.99}` with a valid key.
**Result**: BLOCKED — `if ($quantity <= 0)` → 422 validation error. No order is created.

---

### ATK-07 — Zero quantity injection 🚫 BLOCKED

**Attack**: Send `{item: "widget", quantity: 0, price: 9.99}`.
**Result**: BLOCKED — same `quantity <= 0` guard → 422.

---

### ATK-08 — Missing required body fields 🚫 BLOCKED

**Attack**: Send `{quantity: 1}` without `item` field.
**Result**: BLOCKED — `if ($item === '')` → 422 validation error.

---

### ATK-09 — CSRF via cross-origin browser request 🚫 BLOCKED (design)

**Attack**: Malicious website makes a cross-origin `POST /orders` request from a browser.
**Result**: BLOCKED (by design) — JSON APIs require `Content-Type: application/json`. Browser CSRF attacks can only send form-encoded or plain-text bodies via `<form>` without a preflight. A JSON body triggers a CORS preflight; the server's CORS policy determines whether cross-origin writes are allowed. Additionally, requiring `Idempotency-Key` provides secondary protection since forged requests cannot predict a unique key.

---

### ATK-10 — Negative price injection 🚫 BLOCKED

**Attack**: Send `{item: "widget", quantity: 1, price: -100.0}`.
**Result**: BLOCKED — `if ($price < 0)` → 422 validation error.

---

### ATK-11 — Float/string quantity coercion 🚫 BLOCKED

**Attack**: Send `{quantity: "1"}` or `{quantity: 1.5}` (string or float).
**Result**: BLOCKED — `is_int($body['quantity'])` rejects strings and floats; `1.5` is float → 422.

---

### ATK-12 — SQL injection via Idempotency-Key 🚫 BLOCKED

**Attack**: Send `Idempotency-Key: '; DROP TABLE orders; --`.
**Result**: BLOCKED — the key is used only in parameterized queries (`WHERE idempotency_key = ?`). SQL injection via header value is not possible.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Missing Idempotency-Key | 🚫 BLOCKED |
| ATK-02 | Empty/whitespace-only key | 🚫 BLOCKED |
| ATK-03 | Replay with modified body | 🚫 BLOCKED |
| ATK-04 | Different keys = different orders | ✅ SAFE (intended) |
| ATK-05 | Race condition on same key | 🚫 BLOCKED |
| ATK-06 | Negative quantity | 🚫 BLOCKED |
| ATK-07 | Zero quantity | 🚫 BLOCKED |
| ATK-08 | Missing body fields | 🚫 BLOCKED |
| ATK-09 | CSRF via cross-origin POST | 🚫 BLOCKED |
| ATK-10 | Negative price | 🚫 BLOCKED |
| ATK-11 | Float/string quantity coercion | 🚫 BLOCKED |
| ATK-12 | SQL injection via key header | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
The Idempotency-Key pattern, parameterized queries, and strict `is_int()` validation prevent all tested attack vectors.
