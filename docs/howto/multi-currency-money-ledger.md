---
title: "How-to: Multi-Currency Money Ledger with Integer Cents"
category: product
tags: [money, ledger, multi-currency, integer-arithmetic, transactions]
difficulty: advanced
related: [multi-currency-wallet, point-ledger-api, money-integer-arithmetic]
---

# How-to: Multi-Currency Money Ledger with Integer Cents

> **FT reference**: FT262 (`NENE2-FT/moneylog`) — Multi-currency ledger API using integer minor units (cents) and a `Money` value object

Demonstrates a double-entry-style ledger API that stores monetary amounts as integer minor units
(cents, yen, pence) to avoid floating-point precision errors. A `Money` value object enforces
the invariants: positive amount and 3-character ISO 4217 currency code. Per-currency balance is
computed with `SUM(CASE WHEN type = 'credit' ...)` in a single SQL query.

---

## Routes

| Method | Path            | Description                                 |
|--------|-----------------|---------------------------------------------|
| `POST` | `/entries`      | Create a ledger entry (credit or debit)     |
| `GET`  | `/entries`      | List entries (paginated)                    |
| `GET`  | `/entries/{id}` | Get a single entry                          |
| `GET`  | `/balance`      | Per-currency balance (credit − debit)       |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS entries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    description  TEXT    NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    currency     TEXT    NOT NULL CHECK(length(currency) = 3),
    type         TEXT    NOT NULL CHECK(type IN ('credit', 'debit')),
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_entries_currency ON entries(currency);
CREATE INDEX IF NOT EXISTS idx_entries_created  ON entries(created_at);
```

`CHECK(amount_cents > 0)` enforces positive amounts at the DB level — a safety net for bugs
or direct DB access. `CHECK(length(currency) = 3)` enforces ISO 4217 format.
`CHECK(type IN ('credit', 'debit'))` prevents invalid state.

---

## Why integer cents, not float?

```php
// ❌ Float arithmetic loses precision
var_dump(0.1 + 0.2);  // float(0.30000000000000004)

// ✅ Integer arithmetic is exact
$total = 10 + 20;     // int(30) — always exact
```

Monetary amounts stored as `FLOAT` accumulate rounding errors across sums and cannot be
compared reliably with `===`. Integer minor units (cents for USD/EUR, yen for JPY) are always
exact. Display conversion (`$cents / 100.0`) only happens at serialization, not in business logic.

**Caveat**: `JPY` and similar zero-decimal currencies store whole units as "cents"
(i.e., ¥1000 = 1000 cents). `formatDecimal()` in this FT uses a fixed 2-decimal default;
a production implementation should look up the currency's decimal places.

---

## `Money` value object

```php
final readonly class Money
{
    public function __construct(
        public int    $amountCents,
        public string $currency,
    ) {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amountCents must be positive, got {$amountCents}.");
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException("currency must be a 3-character ISO 4217 code, got '{$currency}'.");
        }
    }

    public function formatDecimal(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
```

The constructor validates its own invariants. A `Money` object that exists is always valid —
callers never need to re-check the values. `readonly` prevents mutation after construction.

`formatDecimal()` is for display only. Never store or compare the formatted string; always
compare `amountCents` integers.

---

## `EntryType` backed enum

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

`EntryType::from('credit')` in hydration converts the DB string to the enum. If the DB
somehow contains an unexpected value, `from()` throws — no silent corruption.

`EntryType::tryFrom($value)` in the controller returns `null` for unknown values, which
the validation error check then catches:

```php
$type = $typeValue !== null ? EntryType::tryFrom($typeValue) : null;
if ($type === null) {
    $errors[] = new ValidationError('type', "type must be 'credit' or 'debit'.", 'invalid');
}
```

---

## Per-currency balance: `SUM(CASE WHEN ...)`

```php
public function balanceByCurrency(): array
{
    $rows = $this->executor->fetchAll(
        "SELECT currency,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
            SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
            SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
         FROM entries
         GROUP BY currency
         ORDER BY currency ASC",
        [],
    );
    // ...
}
```

A single query computes three aggregates per currency:
- `credit_cents`: total credits
- `debit_cents`: total debits
- `balance_cents`: net balance (`credit − debit`)

`CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END` uses a sign flip to
compute the net in one pass. A negative `balance_cents` means debits exceed credits.

**Alternative**: two queries (`SELECT SUM WHERE type = 'credit'` and `SELECT SUM WHERE type = 'debit'`),
merged in PHP. The single-query approach is more efficient and keeps the subtraction in SQL.

---

## Controller: currency normalization

```php
$money = new Money(
    (int) $body['amount_cents'],
    strtoupper((string) $body['currency']),  // ← normalize to uppercase
);
```

`strtoupper()` normalizes the currency code so `usd`, `USD`, and `Usd` are all stored as `USD`.
Without normalization, `USD` and `usd` would appear as separate currencies in the balance query.

---

## Serialization: both cents and decimal

```php
private function serialize(Entry $entry): array
{
    return [
        'id'           => $entry->id,
        'description'  => $entry->description,
        'amount_cents' => $entry->money->amountCents,   // machine-readable: exact integer
        'amount'       => $entry->money->formatDecimal(), // human-readable: "10.50"
        'currency'     => $entry->money->currency,
        'type'         => $entry->type->value,
        'created_at'   => $entry->createdAt,
    ];
}
```

Both `amount_cents` (integer) and `amount` (formatted decimal) are returned. Clients performing
calculations should use `amount_cents`; display UIs may use `amount`.

---

## Example: balance response

**Request**: `GET /balance`

```json
{
  "balances": [
    {"currency": "EUR", "credit_cents": 50000, "debit_cents": 20000, "balance_cents": 30000},
    {"currency": "JPY", "credit_cents": 100000, "debit_cents": 0, "balance_cents": 100000},
    {"currency": "USD", "credit_cents": 150000, "debit_cents": 75000, "balance_cents": 75000}
  ]
}
```

EUR balance: 500.00 − 200.00 = 300.00 EUR. USD balance: 1500.00 − 750.00 = 750.00 USD.

---

## Design comparison

| Storage approach | Precision | Trade-offs |
|---|---|---|
| `INTEGER` cents | Exact | Requires display conversion; currency must specify decimal places |
| `DECIMAL(19,4)` | Exact | DB-native; not available in SQLite; format for display |
| `FLOAT`/`REAL` | Lossy | Never use for money — rounding errors accumulate |
| `TEXT` ("10.50") | N/A | Sort and sum require casting; no arithmetic in SQL |

SQLite's `INTEGER` with cents is the simplest safe approach for SQLite-backed APIs.
For MySQL/PostgreSQL, `DECIMAL(19,4)` is more conventional.

---

## Related howtos

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomic multi-write for fund transfers
- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — bulk entry import with partial success
- [`leaderboard-ranking-api.md`](leaderboard-ranking-api.md) — aggregate queries with SQL window functions
