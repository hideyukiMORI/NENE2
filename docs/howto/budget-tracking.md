---
title: "How-to: Budget Tracking API"
category: product
tags: [budget, finance, tracking, spending, categories]
difficulty: intermediate
related: [expense-tracker, expense-tracking-api, credit-ledger]
---

# How-to: Budget Tracking API

> **FT reference**: FT244 (`NENE2-FT/budgetlog`) — Budget Tracking API
> **ATK**: FT244 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a multi-account budget tracking API with `income`/`expense`/`transfer`
transaction types, `TransferFundsUseCase` with balance checking inside a DB transaction,
multi-filter transaction listing with `QueryStringParser`, and category aggregation.

---

## Routes

| Method | Path                              | Description                                          |
|--------|-----------------------------------|------------------------------------------------------|
| `GET`  | `/accounts`                       | List all accounts                                    |
| `POST` | `/accounts`                       | Create an account (optional initial balance)         |
| `GET`  | `/accounts/{id}`                  | Get a single account                                 |
| `POST` | `/accounts/{id}/transactions`     | Record income or expense transaction                 |
| `GET`  | `/accounts/{id}/transactions`     | List transactions (filterable, paginated)            |
| `GET`  | `/accounts/{id}/summary`          | Balance + income/expense by category                 |
| `POST` | `/transfers`                      | Transfer funds between two accounts                  |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS accounts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL,
    balance INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL,
    type        TEXT    NOT NULL CHECK(type IN ('income','expense','transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

`balance` and `amount` are stored as integers (smallest currency unit, e.g., cents).
`type` is constrained by `CHECK(type IN ('income','expense','transfer'))` at the DB level.
`recurring` is stored as `INTEGER` (`0`/`1`), mapped to a PHP `bool`.

---

## Transaction type allowlist

The controller validates `type` against an explicit allowlist:

```php
if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = new ValidationError('type', 'Type must be income or expense.', 'invalid_value');
}
```

Only `income` and `expense` are accepted from the API. The `transfer` type is set
internally by `TransferFundsUseCase` — callers cannot inject it directly through
`POST /accounts/{id}/transactions`.

---

## Balance update: read-then-update pattern

`POST /accounts/{id}/transactions` updates the account balance after recording the
transaction:

```php
$delta = $type === 'income' ? $amount : -$amount;
$this->accounts->updateBalance($id, $account->balance + $delta);
```

The balance is read first (`findById`), delta applied in PHP, then written back
(`updateBalance`). This is **not atomic** — concurrent requests can create a race
condition (see ATK-09).

---

## TransferFundsUseCase: balance check + DB transaction

Transfers are wrapped in a DB transaction to ensure consistency:

```php
public function execute(int $fromId, int $toId, int $amount, string $description): void
{
    if ($amount <= 0) {
        throw new ValidationException([
            new ValidationError('amount', 'Amount must be greater than zero.', 'out_of_range'),
        ]);
    }

    $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount, $description): void {
        // Instantiate repos inside the callback with the transaction executor
        $accounts     = new SqliteAccountRepository($tx);
        $transactions = new SqliteTransactionRepository($tx);

        $from = $accounts->findById($fromId);
        $to   = $accounts->findById($toId);

        if ($from === null) {
            throw new ValidationException([new ValidationError('from_account_id', 'Source account not found.', 'not_found')]);
        }
        if ($to === null) {
            throw new ValidationException([new ValidationError('to_account_id', 'Destination account not found.', 'not_found')]);
        }
        if ($from->balance < $amount) {
            throw new ValidationException([new ValidationError('amount', 'Insufficient balance.', 'insufficient_balance')]);
        }

        $accounts->updateBalance($fromId, $from->balance - $amount);
        $accounts->updateBalance($toId, $to->balance + $amount);

        $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
        $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
    });
}
```

Repositories are instantiated **inside** the transaction closure with the `$tx` executor
— this ensures all reads and writes share the same connection and transaction boundary.
If any step throws, the entire transaction rolls back.

The same-account guard is in the controller:
```php
if ($fromId === $toId && $fromId > 0) {
    $errors[] = new ValidationError('to_account_id', 'Cannot transfer to the same account.', 'invalid_value');
}
```

---

## Multi-filter transaction listing

`GET /accounts/{id}/transactions` supports multiple simultaneous filters:

```php
$category  = QueryStringParser::string($req, 'category');
$minAmount = QueryStringParser::int($req, 'min_amount');
$maxAmount = QueryStringParser::int($req, 'max_amount');
$recurring = QueryStringParser::bool($req, 'recurring');
```

`QueryStringParser::int()` returns `null` when the parameter is absent — no filter.
`QueryStringParser::bool()` returns `null` when absent, `true` for `"true"/"1"`, `false`
for `"false"/"0"`.

The repository builds the `WHERE` clause dynamically:

```php
if ($category !== null)  { $where[] = 'category = ?'; $params[] = $category; }
if ($minAmount !== null) { $where[] = 'amount >= ?';  $params[] = $minAmount; }
if ($maxAmount !== null) { $where[] = 'amount <= ?';  $params[] = $maxAmount; }
if ($recurring !== null) { $where[] = 'recurring = ?'; $params[] = (int) $recurring; }
```

---

## Category summary aggregation

`GET /accounts/{id}/summary` returns balance and totals grouped by category:

```php
return $this->json->create([
    'balance'             => $account->balance,
    'income_by_category'  => $incomeByCategory,
    'expense_by_category' => $expenseByCategory,
]);
```

The repository uses `GROUP BY category` with `SUM(amount)`:

```sql
SELECT category, SUM(amount) AS total
FROM transactions
WHERE account_id = ? AND type = ?
GROUP BY category
ORDER BY total DESC
```

---

## ATK — Cracker-mindset attack test (FT244)

### ATK-01 — No authentication: accounts and transactions are public

**Attack**: List all accounts without any credentials.

```bash
curl -s http://localhost:8200/accounts
curl -s http://localhost:8200/accounts/1/transactions
```

**Observed**: Both endpoints return data without any authentication. Any caller can
enumerate all accounts and their balances.

**Verdict**: **EXPOSED** — add authentication (API key, JWT, or session) to all
endpoints. Accounts should be user-scoped.

---

### ATK-02 — Create account with negative initial balance

**Attack**: Bypass the negative-balance check.

```json
{"name": "Attack", "initial_balance": -99999}
```

**Observed**: `$initialBalance < 0` check fires → `422 Unprocessable Entity` with
`out_of_range` error.

**Verdict**: **BLOCKED** — explicit guard rejects negative initial balances.

---

### ATK-03 — Expense drives account balance negative

**Attack**: Record an expense larger than the account balance via direct transaction.

```bash
# Account has balance 100
curl -X POST /accounts/1/transactions \
  -d '{"amount": 99999, "type": "expense", "category": "food"}'
```

**Observed**: The `createTransaction` handler reads the balance then subtracts without
checking sufficiency. `100 - 99999 = -99899` — the balance is written as a negative
integer.

**Verdict**: **EXPOSED** — `POST /accounts/{id}/transactions` does not enforce a
non-negative balance constraint. Only `POST /transfers` (via `TransferFundsUseCase`)
checks `if ($from->balance < $amount)`. Add a balance sufficiency check in
`createTransaction` for expense transactions.

---

### ATK-04 — SQL injection via category or description

**Attack**: Embed SQL metacharacters in `category` or `description`.

```json
{"amount": 1, "type": "income", "category": "'; DROP TABLE transactions; --"}
```

**Observed**: All values are bound as parameterized `?` values. No string concatenation
with SQL occurs. The injection payload is stored as literal text.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection.

---

### ATK-05 — Float amount: `(int)` cast truncation

**Attack**: Send a floating-point amount.

```json
{"amount": 1.9, "type": "income", "category": "x"}
```

**Observed**: `(int) $body['amount']` truncates `1.9` to `1`. The amount `1.9`
is silently accepted and stored as `1`. A caller expecting `1.9` to be rejected
(or rounded to `2`) would be surprised.

**Verdict**: **PARTIALLY BLOCKED** — non-integer floats are accepted and silently
truncated. Use `is_int($body['amount'])` to reject non-integer types explicitly,
returning a `422` for `1.9`.

---

### ATK-06 — Zero or negative amount

**Attack**: Submit `amount: 0` or `amount: -100`.

```json
{"amount": 0, "type": "income", "category": "x"}
{"amount": -100, "type": "income", "category": "x"}
```

**Observed**: `$amount <= 0` check fires for both → `422 Unprocessable Entity`.

**Verdict**: **BLOCKED** — explicit guard rejects zero and negative amounts.

---

### ATK-07 — Transfer to same account

**Attack**: Transfer funds from an account to itself.

```json
{"from_account_id": 1, "to_account_id": 1, "amount": 100}
```

**Observed**: `$fromId === $toId && $fromId > 0` fires → `422 Unprocessable Entity`
with `invalid_value` error on `to_account_id`.

**Verdict**: **BLOCKED** — same-account transfer is explicitly rejected.

---

### ATK-08 — Transfer with insufficient funds

**Attack**: Transfer more than the source account's balance.

```json
{"from_account_id": 1, "to_account_id": 2, "amount": 99999}
```

**Observed**: Inside the transaction, `$from->balance < $amount` fires →
`ValidationException` with `insufficient_balance` → transaction rolls back → `422`.
Neither balance changes.

**Verdict**: **BLOCKED** — `TransferFundsUseCase` checks balance inside the DB
transaction. Rollback ensures atomicity.

---

### ATK-09 — Race condition on direct expense transaction

**Attack**: Submit two concurrent expense requests that both pass the balance check
(there is no check) but together exceed the balance.

**Observed**: `createTransaction` uses a read-then-update pattern without a transaction:
1. Thread A reads `balance = 100`
2. Thread B reads `balance = 100`
3. Thread A records expense of 80 → writes `balance = 20`
4. Thread B records expense of 80 → writes `balance = 20` (should be -60)

The `balance` column ends at `20` instead of the correct `-60` — but more critically,
the business constraint (non-negative balance) is never enforced at all for direct
transactions, allowing this path to bypass even the read-then-update.

**Verdict**: **EXPOSED** — the `createTransaction` path has no balance guard and no
transaction wrapping. Fix by: (1) adding `if ($type === 'expense' && $account->balance < $amount) → 422`,
and (2) wrapping the read-then-update in a DB transaction.

---

### ATK-10 — Access another account's transactions (no ownership)

**Attack**: Read transactions belonging to a different user's account.

```bash
curl -s http://localhost:8200/accounts/2/transactions
```

**Observed**: The endpoint returns all transactions for account 2 without any ownership
check. Since there is no authentication, any caller can read any account.

**Verdict**: **EXPOSED** (same root as ATK-01). Accounts must be scoped to an
authenticated user — `WHERE account_id = ? AND owner_id = ?`.

---

### ATK-11 — `recurring` field: truthy coercion

**Attack**: Send non-boolean values for `recurring`.

```json
{"amount": 1, "type": "income", "category": "x", "recurring": "yes"}
{"amount": 1, "type": "income", "category": "x", "recurring": 1}
{"amount": 1, "type": "income", "category": "x", "recurring": 0}
```

**Observed**: `(bool) $body['recurring']` coerces `"yes"` → `true`, `1` → `true`,
`0` → `false`. Any truthy string value sets `recurring = true`. There is no strict
`is_bool()` check.

**Verdict**: **PARTIALLY BLOCKED** — non-boolean types are silently coerced. Use
`is_bool($body['recurring'])` for strict type enforcement, returning `422` for
non-boolean input.

---

### ATK-12 — Non-numeric account ID in path

**Attack**: Pass a string ID in the path parameter.

```
GET /accounts/abc/transactions
GET /accounts/1.5/transactions
```

**Observed**: `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → returns `null` → `404 Not Found`.
- `1.5` → `findById(1)` → if account 1 exists, returns it silently.

**Verdict**: **PARTIALLY BLOCKED** — non-numeric strings map to 404. Float strings
are silently truncated. Add `ctype_digit()` validation for strict path parameter
checking.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | No authentication (all endpoints public) | EXPOSED |
| ATK-02 | Negative initial balance | BLOCKED |
| ATK-03 | Expense drives balance negative | EXPOSED |
| ATK-04 | SQL injection via category/description | BLOCKED |
| ATK-05 | Float amount silently truncated | PARTIALLY BLOCKED |
| ATK-06 | Zero or negative amount | BLOCKED |
| ATK-07 | Transfer to same account | BLOCKED |
| ATK-08 | Transfer with insufficient funds | BLOCKED |
| ATK-09 | Race condition on direct expense | EXPOSED |
| ATK-10 | Cross-account data access (no ownership) | EXPOSED |
| ATK-11 | `recurring` non-boolean coercion | PARTIALLY BLOCKED |
| ATK-12 | Non-numeric account ID | PARTIALLY BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01 / ATK-10** — Add authentication and per-user account ownership
2. **ATK-03 / ATK-09** — Add balance sufficiency check + DB transaction in `createTransaction`
3. **ATK-05** — Replace `(int)` cast with `is_int()` check for strict type enforcement
4. **ATK-11** — Replace `(bool)` cast with `is_bool()` check
5. **ATK-12** — Add `ctype_digit()` guard for ID path parameters

---

## Related howtos

- [`credit-ledger.md`](credit-ledger.md) — append-only ledger with direction ±1 and InsufficientCreditsException
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — multi-currency balance management
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface patterns
- [`note-management-ownership.md`](note-management-ownership.md) — per-user resource ownership with IDOR prevention
