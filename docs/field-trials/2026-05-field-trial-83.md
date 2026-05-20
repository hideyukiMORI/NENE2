# Field Trial 83 — Reward Credits Ledger (creditslog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/creditslog/`
**NENE2 version:** 1.5.23
**Theme:** Append-only credit ledger — earn, spend, balance, idempotency, per-user isolation

---

## What was built

A reward credits system implemented as an append-only ledger. Credits are never directly modified; each earn or spend operation appends a transaction row. The balance is always computed as `SUM(amount * direction)`. This prevents phantom balance corrections and gives a complete audit trail.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/users/{userId}/credits/earn` | Add credits; optional idempotency key |
| POST | `/users/{userId}/credits/spend` | Deduct credits; 409 if insufficient |
| GET | `/users/{userId}/credits/balance` | Current balance (derived from ledger) |
| GET | `/users/{userId}/credits/transactions` | Transaction history, optional `?type=earn\|spend` |

### Schema

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
```

Balance formula: `SELECT COALESCE(SUM(amount * direction), 0) FROM credit_transactions WHERE user_id = ?`

---

## Frictions found

### 1. `DatabaseConstraintException` enabled clean idempotency pattern

**Severity:** Low (informational — confirms v1.5.23 fix works)

Idempotent earn operations use a UNIQUE constraint on `idempotency_key`. On duplicate submission, the executor raises `DatabaseConstraintException` (v1.5.23), and the handler fetches the existing transaction by key to return the same response:

```php
try {
    $id = $this->db->insert('INSERT INTO credit_transactions (..., idempotency_key, ...) VALUES (?, ...)', [...]);
} catch (DatabaseConstraintException) {
    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE idempotency_key = ?', [$key]);
    return $this->hydrate($row);
}
```

This is the same pattern as FT82 (ratinglog upsert), confirming `DatabaseConstraintException` generalizes well beyond upserts to any UNIQUE-keyed deduplication scenario.

---

### 2. No NULL handling needed for `idempotency_key` in hydration

**Severity:** Low

SQLite returns `NULL` for missing `idempotency_key` values. The hydration method uses `isset($row['idempotency_key'])` to map to `?string`, which correctly handles both `NULL` (→ `null`) and a stored string (→ `string`). This pattern was already established in FT78 (notificationlog `read_at`).

---

### 3. `COALESCE` required in balance query for new users

**Severity:** Low

`SELECT SUM(amount * direction)` returns `NULL` when no rows exist (new user with zero transactions). `COALESCE(..., 0)` is required to return `0` rather than `null`.

The cast `(int) ($row['bal'] ?? 0)` also guards against this, but relying on application-side null coalescing for a meaningful business value is fragile. Encoding it in SQL is preferable.

---

### 4. CHECK constraint on `direction` prevents sign errors silently

**Severity:** Low (design note)

The schema encodes `direction IN (1, -1)` as a CHECK constraint. This means application bugs that try to insert `direction=0` or `direction=2` are caught at the DB level and surface as `DatabaseConstraintException`. No application test explicitly verifies this, but the constraint provides an invariant guarantee without additional validation code.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 16 tests, 27 assertions — OK |
| PHPStan level 8 | No errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- Ledger pattern: never UPDATE or DELETE transaction rows. Balance is always derived.
- Idempotency key uses `DatabaseConstraintException` (v1.5.23) — FT83 is the second consumer after FT82.
- Spent credits at exact balance (zero remaining) validated explicitly.
- No NENE2 framework frictions found requiring upstream fixes.
