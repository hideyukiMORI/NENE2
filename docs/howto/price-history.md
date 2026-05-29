---
title: "How-to: Product Price History API"
category: product
tags: [price, history, timeline, temporal-data]
difficulty: intermediate
related: [product-catalog, money-integer-arithmetic]
---

# How-to: Product Price History API

> **FT reference**: FT67 (`NENE2-FT/pricelog`) — Product Price History API
> **ATK**: FT228 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a price-history API where each product maintains a timeline of price tiers
(effective periods). The current price and the price at any given point in time can be
queried. ATK section documents twelve attack vectors with pass/fail verdicts.

---

## Routes

| Method | Path                              | Description                            |
|--------|-----------------------------------|----------------------------------------|
| `POST` | `/products`                       | Create a product                       |
| `GET`  | `/products`                       | List all products                      |
| `GET`  | `/products/{id}`                  | Get a single product                   |
| `POST` | `/products/{id}/prices`           | Set a new price (opens a new tier)     |
| `GET`  | `/products/{id}/prices`           | List the full price history            |
| `GET`  | `/products/{id}/prices/current`   | Current active price                   |
| `GET`  | `/products/{id}/prices/at`        | Price at a specific datetime (`?datetime=`) |

---

## Price tier model

Each price has an `effective_from` and `effective_to` timestamp. A tier is "active" when:

```
effective_from <= now  AND  (effective_to IS NULL  OR  effective_to > now)
```

`effective_to IS NULL` means the tier has no end date yet (open interval).

```sql
CREATE TABLE price_tiers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id     INTEGER NOT NULL REFERENCES products(id),
    amount         INTEGER NOT NULL,       -- cents (non-negative)
    currency       TEXT    NOT NULL DEFAULT 'USD',
    effective_from TEXT    NOT NULL,
    effective_to   TEXT,                  -- NULL = open (current)
    created_at     TEXT    NOT NULL
);
```

---

## Setting a price: close the old tier, open a new one

```php
public function setPrice(int $productId, int $amount, string $currency, string $effectiveFrom): PriceTier
{
    // Close any open tier that starts before the new effective_from
    $this->db->execute(
        'UPDATE price_tiers
         SET effective_to = ?
         WHERE product_id = ? AND effective_to IS NULL AND effective_from <= ?',
        [$effectiveFrom, $productId, $effectiveFrom],
    );

    // Open a new tier
    $id = $this->db->insert(
        'INSERT INTO price_tiers (product_id, amount, currency, effective_from, effective_to, created_at)
         VALUES (?, ?, ?, ?, NULL, ?)',
        [$productId, $amount, $currency, $effectiveFrom, $now],
    );
    // ...
}
```

The UPDATE closes any open tier whose `effective_from <= newEffectiveFrom`. This correctly
handles three scenarios:
- **New effective_from in the future**: closes the current tier at the future date.
- **New effective_from in the past**: backdates the old tier's close and opens a new historical tier.
- **Duplicate effective_from**: closes the old tier at the same instant it started (zero-duration), then opens the new one.

> **Concurrency caveat**: the UPDATE and INSERT are not wrapped in a transaction. Two
> concurrent `setPrice` calls with the same `effective_from` can both pass the UPDATE
> phase and both INSERT, leaving two open tiers (`effective_to IS NULL`). The queries use
> `ORDER BY effective_from DESC LIMIT 1`, so the last insert wins, but history is corrupted.
> Wrap in `transactional()` for correctness under concurrency.

---

## Querying price at a point in time

```php
public function priceAt(int $productId, string $datetime): ?PriceTier
{
    $row = $this->db->fetchOne(
        'SELECT * FROM price_tiers
         WHERE product_id = ? AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to > ?)
         ORDER BY effective_from DESC
         LIMIT 1',
        [$productId, $datetime, $datetime],
    );

    return $row !== null ? $this->hydrateTier($row) : null;
}
```

The comparison is lexicographic string comparison on ISO 8601 datetimes stored as TEXT.
This works correctly **only when all datetimes use the same format and timezone** (e.g.
all UTC `2026-05-27 09:00:00`). Mixing formats or timezone offsets produces wrong results.

**Example**: If `effective_from` is stored as `"2026-05-27T09:00:00+09:00"` (JST) and
`?datetime=2026-05-27T00:30:00Z` (UTC, same instant), the string comparison sees them
as different and may return a wrong tier. Normalise all datetimes to UTC at write time.

---

## Amount in cents (integer)

Money values are stored as integers (cents) to avoid floating-point rounding:

```php
// POST /products/{id}/prices
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;

if ($amount === null || $amount < 0) {
    $errors[] = ['field' => 'amount', 'code' => 'required', 'message' => 'amount must be a non-negative integer (cents).'];
}
```

- `is_int()` rejects JSON floats (`9.99` → PHP float) and strings.
- `$amount < 0` rejects negative prices.
- `$amount === 0` is **allowed** (free products / promotions).

---

## ATK — Cracker attack test (FT228)

### ATK-01 — No authentication

**Attack**: Set a price on any product without credentials.

```http
POST /products/1/prices
{"amount": 1, "currency": "USD", "effective_from": "2026-01-01T00:00:00Z"}
```

**Observed**: `201 Created` — no token required.

**Verdict**: **EXPOSED** (by design for FT67 demo).
Gate price mutations behind an admin role or API key in production.

---

### ATK-02 — Backdated price manipulation

**Attack**: Set `effective_from` to a past date to alter pricing history.

```json
{"amount": 0, "currency": "USD", "effective_from": "2020-01-01T00:00:00Z"}
```

**Observed**: `201 Created`. The UPDATE closes any existing open tier at `2020-01-01`,
and a new zero-price tier spanning from 2020 onward is inserted. Historical queries
(`priceAt`) now return the backdated price for past dates.

**Verdict**: **EXPOSED** — without authentication there is no owner to authorize backdating.
With auth, require that `effective_from >= now()` unless the caller is an admin.

---

### ATK-03 — SQL injection via `?datetime=`

**Attack**: Inject SQL through the `datetime` query parameter.

```http
GET /products/1/prices/at?datetime=2026-01-01' OR '1'='1
```

**Observed**: `404 Not Found` — the injected string is used as a parameterised value,
so the literal string is compared against `effective_from`, which matches nothing.

**Verdict**: **BLOCKED** — PDO parameterised statements prevent SQL injection.

---

### ATK-04 — Zero-amount price

**Attack**: Set a product price to zero (free).

```json
{"amount": 0, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observed**: `201 Created`.

**Verdict**: **ACCEPTED BY DESIGN** — `amount === 0` is intentionally permitted
(trial plans, promotions). Document that `amount` means cents and 0 means free.
If zero-price is not valid for your domain, change `$amount < 0` to `$amount <= 0`.

---

### ATK-05 — Negative amount

**Attack**: Set a negative price (refund attack?).

```json
{"amount": -100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observed**: `422 Unprocessable Entity` — the check `$amount < 0` returns false.

**Verdict**: **BLOCKED** — negative amounts rejected at the application layer.

---

### ATK-06 — Currency code injection (no allowlist)

**Attack**: Set a price with an arbitrary or malicious currency string.

```json
{"amount": 100, "currency": "NOTCURRENCY", "effective_from": "2026-05-27T00:00:00Z"}
{"amount": 100, "currency": "<script>alert(1)</script>", "effective_from": "..."}
{"amount": 100, "currency": "'; DROP TABLE price_tiers; --", "effective_from": "..."}
```

**Observed**: All return `201 Created`. The currency string is stored verbatim.
The SQL injection string is safe (parameterised), but `"NOTCURRENCY"` and the XSS
payload are stored.

**Verdict**: **EXPOSED** — validate `currency` against an ISO 4217 allowlist:
```php
$validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
if (!in_array($currency, $validCurrencies, true)) {
    $errors[] = ['field' => 'currency', 'code' => 'invalid_value', 'message' => 'Unsupported currency code.'];
}
```

---

### ATK-07 — Extremely large amount

**Attack**: Submit an amount larger than PHP/SQLite can handle.

```json
{"amount": 9999999999999999999, "currency": "USD", "effective_from": "..."}
```

**Observed**: PHP parses large JSON integers as floats when they exceed `PHP_INT_MAX`
(2^63 - 1 on 64-bit). `is_int($body['amount'])` returns false for a float → 422.

**Verdict**: **BLOCKED** — `is_int()` correctly rejects JSON integers that overflow
to PHP float. Values within `PHP_INT_MAX` are stored correctly as SQLite integers.

---

### ATK-08 — Invalid datetime in `?datetime=`

**Attack**: Pass a non-date string to the `priceAt` endpoint.

```http
GET /products/1/prices/at?datetime=not-a-date
GET /products/1/prices/at?datetime=2026-02-30T00:00:00Z
```

**Observed**: Both return `404 Not Found` — the strings are compared lexicographically
against stored `effective_from` values and match nothing. No exception is thrown.

**Verdict**: **PARTIALLY EXPOSED** — the endpoint silently accepts invalid dates and
returns 404, which may confuse callers expecting a 422. Add format validation:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $datetime);
if ($dt === false) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'datetime', 'code' => 'invalid_format', 'message' => 'datetime must be ISO 8601.']],
    ]);
}
```

---

### ATK-09 — Future effective_from (scheduled price)

**Attack**: Set `effective_from` to a future date to schedule a price change.

```json
{"amount": 999, "currency": "USD", "effective_from": "2099-12-31T00:00:00Z"}
```

**Observed**: `201 Created`. `currentPrice()` still returns the previous price (the
future tier's `effective_from > now`), but `priceAt("2099-12-31T01:00:00Z")` returns
the new tier.

**Verdict**: **ACCEPTED BY DESIGN** — scheduled pricing is a legitimate use case.
Document it in the API spec. If scheduling should be restricted to admins, require
auth and check `effective_from <= now + 30 days` for non-admin callers.

---

### ATK-10 — Concurrent price setting (race condition)

**Attack**: Send two simultaneous `POST /products/1/prices` with the same `effective_from`.

**Observed**: Without a transaction wrapping the UPDATE + INSERT, both requests can
pass the UPDATE phase and both INSERT, creating two open tiers (`effective_to IS NULL`).
Queries use `ORDER BY effective_from DESC LIMIT 1`, so results are non-deterministic.

**Verdict**: **EXPOSED** — wrap `setPrice` in `transactional()`:
```php
return $this->txManager->transactional(function ($tx) use (...) {
    // UPDATE then INSERT inside the transaction
});
```

---

### ATK-11 — Non-existent product_id

**Attack**: Set a price for a product that does not exist.

```http
POST /products/99999/prices
{"amount": 100, "currency": "USD", "effective_from": "2026-05-27T00:00:00Z"}
```

**Observed**: `404 Not Found` — `findProduct(99999)` returns `null` and the controller
returns a not-found Problem Details response before calling `setPrice`.

**Verdict**: **BLOCKED** — existence check before mutation.

---

### ATK-12 — Non-numeric path IDs

**Attack**: Pass non-digit strings as `{id}`.

```http
GET /products/abc
GET /products/-1
POST /products/0/prices
```

**Observed**: All return `404 Not Found`. `(int) "abc"` = `0`; `findProduct(0)` returns
`null` (no product with ID 0); controller returns 404.

**Verdict**: **BLOCKED** in practice. Note: `(int) "9abc"` = `9` — a product with
ID 9 would match. Use `ctype_digit()` for strict path validation when needed.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | No authentication | EXPOSED (by design) |
| ATK-02 | Backdated price manipulation | EXPOSED |
| ATK-03 | SQL injection via `?datetime=` | BLOCKED |
| ATK-04 | Zero-amount price | ACCEPTED BY DESIGN |
| ATK-05 | Negative amount | BLOCKED |
| ATK-06 | Currency code injection (no allowlist) | EXPOSED |
| ATK-07 | Extremely large amount | BLOCKED |
| ATK-08 | Invalid datetime format | PARTIALLY EXPOSED |
| ATK-09 | Future `effective_from` (scheduled price) | ACCEPTED BY DESIGN |
| ATK-10 | Concurrent setPrice race condition | EXPOSED |
| ATK-11 | Non-existent product | BLOCKED |
| ATK-12 | Non-numeric path IDs | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01** — Add authentication/authorisation
2. **ATK-02** — Restrict backdating to admin callers (or disallow entirely)
3. **ATK-06** — Validate `currency` against ISO 4217 allowlist
4. **ATK-08** — Validate `?datetime=` format before DB query
5. **ATK-10** — Wrap `setPrice` UPDATE+INSERT in a transaction

---

## Related howtos

- [`expense-tracker.md`](expense-tracker.md) — `is_int()` amount validation and ISO 8601 date round-trip
- [`habit-tracker.md`](habit-tracker.md) — ATK-01〜12 pattern (previous ATK cycle)
- [`prevent-double-booking.md`](prevent-double-booking.md) — transactional read-check-write
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — strict ISO 8601 validation
