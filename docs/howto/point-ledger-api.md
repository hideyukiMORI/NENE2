# How-to: Point Ledger API

> **FT reference**: FT300 (`NENE2-FT/pointlog`) — Point ledger API: earn/spend/adjust/expire transactions, balance tracking, overdraft prevention (CHECK balance_after >= 0), admin-only adjust, reference_id idempotency, MAX_EARN=10000 / MAX_ADJUST=100000 caps, ATK-01〜12 all BLOCKED, 30 tests / 66 assertions PASS.

This guide shows how to build a loyalty point ledger where users earn and spend points, admins adjust balances, and reference IDs prevent duplicate transactions.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    type         TEXT    NOT NULL,
    amount       INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description  TEXT    NOT NULL,
    reference_id TEXT,
    created_at   TEXT    NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Three CHECK constraints as defense-in-depth:
- `amount > 0` — no zero or negative transactions at DB level
- `balance_after >= 0` — balance can never go negative in storage
- `type IN (...)` — only known transaction types accepted

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/users/{userId}/points` | `X-User-Id` | Get current balance |
| `GET` | `/users/{userId}/points/history` | `X-User-Id` | Get transaction history |
| `POST` | `/users/{userId}/points/earn` | `X-User-Id` (self) | Earn points |
| `POST` | `/users/{userId}/points/spend` | `X-User-Id` (self) | Spend points |
| `POST` | `/users/{userId}/points/adjust` | `X-User-Id` (admin) | Admin adjustment |

## Authentication and Authorization

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}
```

Every handler calls `requireUserId()` first:

```php
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
```

Cross-user access is then checked for earn/spend:

```php
$targetUserId = (int) $this->routeParam($request, 'userId');
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Admins can view any user's balance or history. Non-admins can only access their own.

## Strict Integer Validation

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
if ($amount === null || $amount <= 0) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
}
```

`is_int()` rejects:
- Floats: `10.5` — rejected (422)
- Strings: `"100"` — rejected (422)
- Booleans: `true` — rejected (422)
- Zero: `0` — rejected (amount <= 0)
- Negatives: `-500` — rejected (amount <= 0)

## Transaction Caps

```php
private const int MAX_EARN_PER_TRANSACTION  = 10000;
private const int MAX_ADJUST_PER_TRANSACTION = 100000;
```

```php
if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max'   => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Earn is capped per-transaction at 10,000. Admin adjust is capped at 100,000 (higher because it's a privileged correction operation).

## Overdraft Prevention

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error'    => 'insufficient points',
        'balance'  => $balance,
        'required' => $amount,
    ], 422);
}
```

Check current balance before deducting. Returning the current balance and required amount in the error helps clients display a meaningful message to users.

## Admin Adjust

```php
private function handleAdjust(ServerRequestInterface $request): ResponseInterface
{
    $actorId = $this->requireUserId($request);
    if ($actorId === null) {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }
    if (!$this->isAdmin($request)) {
        return $this->responseFactory->create(['error' => 'admin role required'], 403);
    }
    // ...
    $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';
    // ...
}
```

Adjust checks `isAdmin()` **before** checking the target user — a non-admin gets 403 immediately regardless of the target. The `adjust_type` field (`'add'` default / `'subtract'`) lets admins both grant and deduct points without needing separate endpoints.

## reference_id Idempotency

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
```

When a `reference_id` is provided:
- First call → 201 Created with new transaction
- Repeated call with same `reference_id` → 200 OK with the original transaction (no new transaction created)

This prevents double-credits on network retries. The reference_id lookup is **user-scoped** (`findByReferenceId($targetUserId, ...)`) so the same reference_id can be used by different users without conflict.

## Balance Calculation

```php
// Repository: sum all earn/adjust-add transactions minus spend/adjust-subtract/expire
public function getBalance(int $userId): int
{
    // Typically: balance_after of the most recent transaction, or 0 if none
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

The `balance_after` column on each transaction stores the running balance. Getting the current balance is a single `ORDER BY id DESC LIMIT 1` query — no SUM aggregation needed.

## Response Shape

```php
private function formatTransaction(array $t): array
{
    return [
        'id'           => isset($t['id'])           ? (int)    $t['id']           : null,
        'user_id'      => isset($t['user_id'])       ? (int)    $t['user_id']       : null,
        'type'         => $t['type']         ?? null,
        'amount'       => isset($t['amount'])        ? (int)    $t['amount']        : null,
        'balance_after'=> isset($t['balance_after']) ? (int)    $t['balance_after'] : null,
        'description'  => $t['description']  ?? null,
        'reference_id' => $t['reference_id'] ?? null,
        'created_at'   => $t['created_at']   ?? null,
    ];
}
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Unauthenticated Balance Access 🚫 BLOCKED

**Attack**: `GET /users/2/points` with no `X-User-Id` header.
**Result**: BLOCKED — `requireUserId()` returns null → 401 immediately. No data returned.

---

### ATK-02 — Cross-User Balance Peek 🚫 BLOCKED

**Attack**: `GET /users/2/points` with `X-User-Id: 3` (Alice trying to read Bob's balance).
**Result**: BLOCKED — `$targetUserId (2) !== $actorId (3)` and not admin → 403.

---

### ATK-03 — Self-Grant to Other User 🚫 BLOCKED

**Attack**: `POST /users/3/points/earn` with `X-User-Id: 2` and `amount: 99999`.
**Result**: BLOCKED — actor (2) != target (3) and not admin → 403. Target balance stays 0.

---

### ATK-04 — Negative Amount Earn 🚫 BLOCKED

**Attack**: `POST /users/2/points/earn` with `amount: -500`.
**Result**: BLOCKED — `$amount <= 0` check → 422. Balance unchanged.

---

### ATK-05 — Zero Amount Transaction 🚫 BLOCKED

**Attack**: `POST /users/2/points/earn` with `amount: 0`, and separately `amount: 0` for spend.
**Result**: BLOCKED — both return 422 (`amount <= 0`). No zero-value transactions created.

---

### ATK-06 — Overdraft Spend 🚫 BLOCKED

**Attack**: Earn 100 points, then try to spend 101.
**Result**: BLOCKED — `$balance (100) < $amount (101)` → 422 with `insufficient points`. Balance stays at 100. DB `CHECK (balance_after >= 0)` provides an additional backstop.

---

### ATK-07 — Regular User Adjust 🚫 BLOCKED

**Attack**: `POST /users/2/points/adjust` with `X-User-Id: 2` (non-admin role).
**Result**: BLOCKED — `isAdmin()` check fails → 403. Balance stays 0.

---

### ATK-08 — Excessive Earn Amount 🚫 BLOCKED

**Attack**: `POST /users/2/points/earn` with `amount: 10001` (over MAX_EARN=10000).
**Result**: BLOCKED — `$amount > MAX_EARN_PER_TRANSACTION` → 422 with `max: 10000`. Balance unchanged.

---

### ATK-09 — Double-Credit via reference_id Reuse 🚫 BLOCKED

**Attack**: Earn 500 points with `reference_id: "order-999"`, then repeat the same request.
**Result**: BLOCKED — second call finds existing transaction via `findByReferenceId()` → 200 with same transaction. Balance remains 500 (not 1000).

---

### ATK-10 — Double-Debit via reference_id Reuse 🚫 BLOCKED

**Attack**: Spend 300 points with `reference_id: "redemption-777"`, then repeat.
**Result**: BLOCKED — second call returns the original spend transaction (200). Balance remains 700 (not 400).

---

### ATK-11 — SQL Injection in reference_id 🚫 BLOCKED

**Attack**: `reference_id: "' OR '1'='1' --"` in an earn request.
**Result**: BLOCKED — parameterized queries store the injection string verbatim. Balance is 100, not corrupted. `reference_id` in response matches the injected string exactly (stored as data, not interpreted as SQL).

---

### ATK-12 — Float Amount 🚫 BLOCKED

**Attack**: `POST /users/2/points/earn` with `amount: 10.5`.
**Result**: BLOCKED — `is_int(10.5)` is false → null → 422. Balance unchanged.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Unauthenticated balance access | 🚫 BLOCKED |
| ATK-02 | Cross-user balance peek | 🚫 BLOCKED |
| ATK-03 | Self-grant to other user | 🚫 BLOCKED |
| ATK-04 | Negative amount earn | 🚫 BLOCKED |
| ATK-05 | Zero amount transaction | 🚫 BLOCKED |
| ATK-06 | Overdraft spend | 🚫 BLOCKED |
| ATK-07 | Regular user adjust | 🚫 BLOCKED |
| ATK-08 | Excessive earn amount (>MAX) | 🚫 BLOCKED |
| ATK-09 | Double-credit via reference_id | 🚫 BLOCKED |
| ATK-10 | Double-debit via reference_id | 🚫 BLOCKED |
| ATK-11 | SQL injection in reference_id | 🚫 BLOCKED |
| ATK-12 | Float amount | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
No critical findings. Auth chain (401→403), amount validation (is_int + >0 + cap), overdraft guard, and reference_id idempotency prevent all known attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `X-User-Id` check (authentication skipped) | Unauthenticated access to all balances and transactions |
| Cross-user earn without admin check | Any user earns points into any other user's account |
| `$amount > 0` without `is_int()` | Float `10.5` passes; fractional points corrupt ledger integrity |
| No MAX_EARN cap | Attacker earns INT_MAX points in one request |
| No overdraft check before spend | Balance goes negative; DB CHECK is last resort, not primary guard |
| No `reference_id` idempotency | Network retry doubles credits or charges |
| Shared `reference_id` space across users | User A's `order-1` blocks User B from using the same reference |
| `getBalance()` via SUM aggregation on large tables | Full table scan per request; use `balance_after` running total instead |
| Admin adjust without role check first | Non-admin submits large adjust; check role before any business logic |
| Return 200 on duplicate without same transaction body | Client cannot verify idempotency; must return original transaction |
