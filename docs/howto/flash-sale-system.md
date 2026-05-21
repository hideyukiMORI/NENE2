# How to Build a Flash Sale System with NENE2

This guide walks through building a time-limited, quantity-constrained flash sale system where users can purchase a product at a discounted price during a sale window.

**Field Trial**: FT140  
**NENE2 version**: ^1.5  
**Covered topics**: time window validation, stock counting with COUNT(*), UNIQUE constraint for one-purchase-per-user, `match` expression for status, cracker-mindset attack testing

---

## What we're building

- `POST /products` — create a product
- `POST /sales` — create a flash sale (product_id, price, quantity, starts_at, ends_at)
- `GET /sales/{saleId}` — view sale details with remaining count and status
- `POST /sales/{saleId}/purchase` — purchase during active window (one per user)
- `GET /sales/{saleId}/purchases` — list all buyers

---

## Database schema

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (sale_id, user_id)` prevents a user from purchasing the same sale twice, even under concurrent requests.

---

## Time window validation

```php
$now = date('c');

if ($now < $sale['starts_at']) {
    return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
}

if ($now > $sale['ends_at']) {
    return $this->responseFactory->create(['error' => 'sale has ended'], 422);
}
```

Store `starts_at` / `ends_at` as ISO 8601 strings. String comparison works correctly for ISO 8601 because the format is lexicographically ordered.

---

## Stock counting with COUNT(*)

Instead of maintaining a mutable `remaining` column, count actual purchases:

```php
public function countPurchases(int $saleId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
        [$saleId],
    );

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}
```

Then check:

```php
$purchased = $this->repository->countPurchases($saleId);

if ($purchased >= $sale['quantity']) {
    return $this->responseFactory->create(['error' => 'sold out'], 422);
}
```

`remaining` is derived at read time: `$sale['quantity'] - $purchased`. Clamp to `max(0, $remaining)` to avoid negative display.

---

## One purchase per user — UNIQUE constraint

`UNIQUE (sale_id, user_id)` prevents duplicates at the DB level. `DatabaseConstraintException` maps to a 409:

```php
public function purchase(int $saleId, int $userId, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
            [$saleId, $userId, $now],
        );

        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

The handler returns 409 when `purchase()` returns `false`.

---

## Sale status with match expression

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

Three states: `upcoming`, `active`, `ended`. The `match` expression is exhaustive because `default` covers all other cases.

---

## Cracker attack test results (FT140)

| ID | Attack | Expected | Result |
|----|--------|----------|--------|
| ATK-01 | SQL injection in product name | 201 (verbatim stored) | Pass |
| ATK-02 | Purchase without X-User-Id | 400 | Pass |
| ATK-03 | Non-numeric X-User-Id | not 201 | Pass |
| ATK-04 | Negative saleId in URL | not 201 | Pass |
| ATK-05 | Buy before sale starts | 422 | Pass |
| ATK-06 | Buy after sale ends | 422 | Pass |
| ATK-07 | Double-purchase same sale | 409 on second | Pass |
| ATK-08 | Exhaust stock then buy | 422 sold out | Pass |
| ATK-09 | Create sale with quantity=0 | 422 | Pass |
| ATK-10 | Create sale with negative price | 422 | Pass |
| ATK-11 | Purchase as non-existent user | 404 | Pass |
| ATK-12 | ends_at before starts_at | 422 | Pass |

All 12 attack tests pass.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Mutable `remaining` column drifts under concurrency | Count from `purchases` table, derive `remaining` at read time |
| Allowing quantity=0 via API | Validate `$quantity > 0` in handler; also `CHECK (quantity > 0)` in schema |
| Negative price slips through | Validate `$price >= 0`; also `CHECK (price >= 0)` in schema |
| User buying same sale twice | `UNIQUE (sale_id, user_id)` + `DatabaseConstraintException` → 409 |
| Time comparison on non-ISO strings | Use ISO 8601 (e.g. `date('c')`) — lexicographic ordering is correct |
| `ends_at` inverted with `starts_at` | Validate `$starts_at < $ends_at` before INSERT |
