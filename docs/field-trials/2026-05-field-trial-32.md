# Field Trial 32 — Inventory Stock Ledger (inventorylog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/inventorylog/`
**NENE2 version**: 1.5.15
**Theme**: Multi-operation stock ledger with atomic transactions, aggregate queries, and threshold-based reporting

## Overview

Built a stock inventory API with products, stock transactions (receive / sell / reserve / release / adjust), aggregate stock level calculation, transaction history pagination, and a low-stock alert endpoint. The repository uses `transactional()` for atomic sell/reserve operations and a SQLite aggregate query for stock level computation.

## Endpoints Implemented

- `POST /products` — create product (SKU + name), 201 with product JSON, 422 on missing fields, 409 on duplicate SKU
- `GET /products` — list products with pagination
- `GET /products/{sku}` — show product, 404 on missing
- `GET /products/{sku}/stock` — current stock level (available, reserved, total)
- `GET /products/{sku}/transactions` — paginated transaction history
- `POST /products/{sku}/receive` — add stock (+qty), 201 with transaction, 422 on non-positive qty
- `POST /products/{sku}/sell` — subtract stock (−qty stored), atomic check, 409 on insufficient stock
- `POST /products/{sku}/reserve` — reserve stock (atomic check against available), 409 on insufficient
- `POST /products/{sku}/release` — release previously reserved stock
- `POST /products/{sku}/adjust` — signed quantity correction (may be negative)
- `GET /stock/low` — products with available stock ≤ threshold (default 5), 422 on negative threshold

## Test Results

32 tests, 56 assertions — all pass after fixing 2 issues (see frictions).

---

## Frictions Found

### Friction 1 — PDO parameter binding in HAVING clause causes string comparison in SQLite [HIGH]

**Symptom**: `GET /stock/low?threshold=5` returned products with `available = 10` in addition to `available = 3`, even though the query had `HAVING available <= ?` with bound value `5`.

**Root cause**: PDO binds the `?` parameter as a string `"5"` when the HAVING clause references a computed alias. SQLite then performs text comparison: `"10" <= "5"` is `true` (because `"1" < "5"` lexicographically), so products with two-digit available values incorrectly satisfy the condition.

**Reproduction**:

```php
// BROKEN — PDO binds threshold as string → text comparison
$rows = $executor->fetchAll(
    "SELECT ..., computed_expr AS available
     FROM products p
     LEFT JOIN stock_transactions t ON t.product_id = p.id
     GROUP BY p.id
     HAVING available <= ?",
    [5],
);
// Returns products with available=3 AND available=10 when threshold=5
```

**Fix**: Wrap the parameter in `CAST(? AS INTEGER)` to force numeric comparison:

```php
// CORRECT
     HAVING available <= CAST(? AS INTEGER)
```

**Confirmed**: Hardcoded `HAVING available <= 5` worked correctly; only bound `?` triggered the text comparison bug.

**NENE2 impact**: Document this in the database howto. Users building aggregate queries with parameterized thresholds will encounter this pattern frequently (reporting, alerts, rate limits computed in SQL).

---

### Friction 2 — `transactional()` requires re-instantiating the repository with the tx executor [MEDIUM]

**Symptom**: Inside a `transactional()` callback, calling `$this->stockLevel($sku)` would use `$this->executor` (not the transaction-bound executor), breaking isolation.

**Pattern required**:

```php
return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($sku, $quantity, $note, $now): StockTransaction {
    $txRepo  = new self($tx, $this->txManager);  // ← must rebind to tx executor
    $level   = $txRepo->stockLevel($sku);        // now uses the same transaction
    // ...
});
```

**Why**: `transactional()` provides a transaction-scoped executor as the callback argument. The outer `$this->executor` still holds the non-transactional connection, so queries through it run outside the transaction and may see stale data.

**NENE2 impact**: This pattern was documented in FT29/FT30 howtos but is easy to miss. A note in the `transactional()` docblock or howto would help.

---

### Friction 3 — Stock level mental model (reserved field semantics) [LOW]

**Symptom**: A test initially asserted `reserved = 60` after reserve(30) + release(30), expecting cumulative counting. The actual value is `30` because only `type = 'reserve'` transactions are summed; `release` transactions don't subtract from `reserved`.

**Design decision**: The `reserved` field reflects "total units ever marked as reserved via reserve transactions" — it is not the current net reservation. `available` correctly accounts for both via the aggregate formula.

**Impact**: This is a design choice in this FT's data model, not a NENE2 bug. Documented here as an example of how the double-entry ledger model requires clear semantics for each transaction type.

---

## NENE2 Changes Required

| # | Change | Priority |
|---|--------|----------|
| 1 | Document `CAST(? AS INTEGER)` pattern for HAVING with PDO in the database howto | High |

## Version

NENE2 changes → v1.5.16
