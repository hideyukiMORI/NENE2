# Field Trial 44 — Multi-Currency Money as Integer Cents (moneylog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/moneylog/`
**NENE2 version**: 1.5.17
**Theme**: Money value object (amountCents + currency), float-free arithmetic, CASE/SUM balance aggregation per currency, backed enum for entry type

## Overview

Built a double-entry ledger API where all monetary amounts are stored as integer minor units (cents). A `Money` value object enforces the invariant at construction time. A `GET /balance` endpoint aggregates credit/debit per currency using SQL `CASE`/`SUM`, returning signed balance values. All fractions are handled by the application layer, never by floating-point arithmetic.

## Endpoints Implemented

- `POST /entries` — create ledger entry (description, amount_cents, currency, type); validates cents > 0, currency is 3-char, type is `credit|debit`
- `GET /entries` — list all entries newest-first (limit/offset)
- `GET /entries/{id}` — show single entry
- `GET /balance` — aggregate credit/debit/balance per currency

## Test Results

16 tests, 37 assertions — pass with no fixes required.

---

## Frictions Found

None. All patterns worked as expected on first attempt.

---

## Patterns Validated

### Money value object (integer cents only)

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

The constraint is enforced at construction — impossible to have a zero, negative, or malformed Money in the domain. Division by 100 for display is integer→float, not stored anywhere.

### Backed enum for entry type

```php
enum EntryType: string
{
    case Credit = 'credit';
    case Debit  = 'debit';
}
```

`EntryType::tryFrom($value)` in the route handler produces a null on unknown values, which the validation layer converts to a 422. `EntryType::from()` in the hydrator is safe because the DB `CHECK` constraint ensures only valid values are stored.

### SQL CASE/SUM balance aggregation

```sql
SELECT currency,
    SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE 0 END) AS credit_cents,
    SUM(CASE WHEN type = 'debit'  THEN amount_cents ELSE 0 END) AS debit_cents,
    SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS balance_cents
FROM entries
GROUP BY currency
ORDER BY currency ASC
```

This produces one row per currency with separate credit, debit, and net balance totals. No cross-currency arithmetic ever occurs in the database.

### API validation for money fields

```php
if (!isset($body['amount_cents']) || !is_int($body['amount_cents']) || $body['amount_cents'] <= 0) {
    $errors[] = new ValidationError('amount_cents', 'amount_cents must be a positive integer.', 'invalid');
}
```

`JsonRequestBodyParser::parse()` decodes JSON integers as PHP `int`, so the `is_int()` check works correctly without casting. A floating-point value like `99.99` would fail the `is_int()` check — the API enforces integer-only at the boundary.

### Response serialization

```json
{
  "id": 1,
  "description": "Salary",
  "amount_cents": 500000,
  "amount": "5000.00",
  "currency": "USD",
  "type": "credit",
  "created_at": "2026-05-20T12:00:00Z"
}
```

Both `amount_cents` (integer, machine-readable) and `amount` (formatted string, human-readable) are returned. Clients that need to do arithmetic should use `amount_cents`; clients that display to users can use `amount`.

### JPY note

JPY has no minor unit (1 JPY = 1 unit). If storing JPY, callers store whole yen directly as `amount_cents`. The `formatDecimal()` method would show `100.00` for 100 JPY, which is technically wrong for display — a production system would need a currency→decimal-places lookup table. For this FT the pattern is validated; the JPY display caveat is noted but not a framework issue.

---

## NENE2 Changes Required

None — zero frictions. `JsonRequestBodyParser`, `QueryStringParser`, `ValidationException`, `DomainExceptionHandlerInterface`, and `ProblemDetailsResponseFactory` composed cleanly with the money domain.

No version bump needed.
