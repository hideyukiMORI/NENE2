# How-to: Credit Ledger API

> **FT reference**: FT234 (`NENE2-FT/creditslog`) — Credit Ledger API

Demonstrates an append-only credit ledger where balances are never stored directly —
they are computed at query time as `SUM(amount * direction)`. Supports earning credits,
spending credits with an overdraft guard, idempotent earn via a unique key, and a
filterable transaction history.

---

## Routes

| Method | Path                                   | Description                                     |
|--------|----------------------------------------|-------------------------------------------------|
| `POST` | `/users/{userId}/credits/earn`         | Earn credits (add to balance)                   |
| `POST` | `/users/{userId}/credits/spend`        | Spend credits (deduct from balance, 409 on overdraft) |
| `GET`  | `/users/{userId}/credits/balance`      | Get current balance                             |
| `GET`  | `/users/{userId}/credits/transactions` | List transaction history (optional `?type=`)    |

---

## Ledger model: `direction` instead of signed amount

Instead of storing positive and negative amounts, each transaction stores a positive
`amount` and a signed `direction` (`+1` for earn, `-1` for spend):

```sql
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK(type IN ('earn', 'spend', 'adjust')),
    amount          INTEGER NOT NULL CHECK(amount > 0),
    direction       INTEGER NOT NULL CHECK(direction IN (1, -1)),
    description     TEXT    NOT NULL DEFAULT '',
    idempotency_key TEXT    UNIQUE,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions (user_id);
```

Benefits of the `direction` column pattern:
- `CHECK(amount > 0)` enforces that the raw amount is always positive — no accidental
  double-negation bugs when inserting.
- `CHECK(direction IN (1, -1))` constrains the multiplier to two valid values.
- The balance formula is uniform: `SUM(amount * direction)` — no conditional branching
  in the aggregation.
- An `adjust` type is available for manual corrections (e.g. refunds, admin grants)
  using either direction.

---

## Balance calculation

Balance is computed at read time — no `balance` column is ever updated:

```php
public function balance(string $userId): int
{
    $row = $this->db->fetchOne(
        'SELECT COALESCE(SUM(amount * direction), 0) AS bal FROM credit_transactions WHERE user_id = ?',
        [$userId],
    );

    return (int) ($row['bal'] ?? 0);
}
```

`COALESCE(..., 0)` handles the case where a user has no transactions — `SUM` of an
empty set returns `NULL` in SQL, which would cast to `0` anyway, but `COALESCE` makes
the intent explicit.

The index on `user_id` ensures the `SUM` aggregation scans only that user's rows.
For large ledgers, a cached balance column with optimistic locking or event-sourced
snapshots is worth considering (see `add-optimistic-locking.md`).

---

## Earn with optional idempotency key

Providing `idempotency_key` makes the earn operation safe to retry — a duplicate key
returns the original transaction instead of inserting a new one:

```php
public function earn(string $userId, int $amount, string $description, ?string $idempotencyKey): CreditTransaction
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($idempotencyKey !== null) {
        try {
            $id = $this->db->insert(
                'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'earn', $amount, 1, $description, $idempotencyKey, $now],
            );
        } catch (DatabaseConstraintException) {
            // Key already used — return the original transaction
            $row = $this->db->fetchOne(
                'SELECT * FROM credit_transactions WHERE idempotency_key = ?',
                [$idempotencyKey],
            );
            assert($row !== null);

            return $this->hydrate($row);
        }
    } else {
        $id = $this->db->insert(
            'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
            [$userId, 'earn', $amount, 1, $description, $now],
        );
    }

    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE id = ?', [$id]);
    assert($row !== null);

    return $this->hydrate($row);
}
```

The `UNIQUE` constraint on `idempotency_key` makes the DB the authority — the
application catches `DatabaseConstraintException` and re-fetches the existing row.
This avoids a SELECT-before-INSERT race condition: two concurrent retries with the
same key will result in exactly one INSERT succeeding.

---

## Spend with overdraft guard

```php
public function spend(string $userId, int $amount, string $description): CreditTransaction
{
    $balance = $this->balance($userId);
    if ($balance < $amount) {
        throw new InsufficientCreditsException("Insufficient credits: balance={$balance}, requested={$amount}");
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $id  = $this->db->insert(
        'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        [$userId, 'spend', $amount, -1, $description, $now],
    );
    // ...
}
```

The controller maps `InsufficientCreditsException` to `409 Conflict`:

```php
try {
    $tx = $this->repo->spend($userId, $amount, $description);
} catch (InsufficientCreditsException $e) {
    return $this->problems->create($request, 'insufficient-credits', 'Insufficient Credits', 409, $e->getMessage());
}
```

`409 Conflict` is preferred over `422 Unprocessable Entity` because the request is
valid — the balance state is what prevents it. A caller that retries after earning
more credits will succeed.

> **Concurrency note**: the balance check and insert are not wrapped in a transaction.
> Two concurrent spend requests can both read a sufficient balance and both insert,
> leaving the balance negative. Wrap in a transaction with `SELECT ... FOR UPDATE`
> (MySQL/PostgreSQL) or use SQLite's serialized writes for correctness under concurrency.

---

## Amount validation

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

$errors = [];
if ($amount <= 0) {
    $errors[] = ['field' => 'amount', 'code' => 'invalid', 'message' => 'amount must be a positive integer.'];
}
```

`is_int()` strict check rejects JSON floats (`1.5`) and strings (`"10"`). The DB-level
`CHECK(amount > 0)` acts as a backstop, but rejecting at the application layer gives
a structured Problem Details response instead of a DB error.

---

## Transaction history with type filter

```php
private function transactions(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = (string) ($params['userId'] ?? '');
    $q      = $request->getQueryParams();
    $type   = isset($q['type']) && is_string($q['type']) ? $q['type'] : null;

    $txs = $this->repo->listTransactions($userId, $type);

    return $this->json->create([
        'user_id'      => $userId,
        'transactions' => array_map(fn (CreditTransaction $t) => $t->toArray(), $txs),
    ]);
}
```

`?type=earn` or `?type=spend` narrows the list. No validation is performed on the type
value — an unknown type (e.g. `?type=refund`) returns an empty list rather than an
error, which is acceptable for a filter parameter.

---

## Schema design notes

| Column | Purpose |
|--------|---------|
| `amount` | Always positive; `CHECK(amount > 0)` enforces this |
| `direction` | `+1` (earn) or `-1` (spend); `CHECK(direction IN (1, -1))` |
| `type` | Human label: `earn`, `spend`, `adjust`; `CHECK` allowlist |
| `idempotency_key` | Optional `UNIQUE` key for retry-safe earn operations |
| `description` | Free-text memo for the transaction |

No `balance` column — the current balance is always derived from the ledger.

---

## Related howtos

- [`idempotency.md`](idempotency.md) — general idempotency key patterns
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — multi-currency balance management
- [`point-loyalty-system.md`](point-loyalty-system.md) — point earn/redeem with tier levels
- [`add-optimistic-locking.md`](add-optimistic-locking.md) — cached balance with version guard
- [`transactions.md`](transactions.md) — wrapping balance check and insert in a transaction
