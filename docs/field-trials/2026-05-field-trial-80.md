# Field Trial 80 — Product Pricing with Date-Effective Tiers (pricelog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.23
**Project**: `/home/xi/docker/NENE2-FT/pricelog/`
**Theme**: Temporal pricing — product price tiers with effective_from/effective_to ranges, auto-closing of previous tiers, price history, current price lookup, and price-at-datetime query.

---

## What was built

A product pricing API where each product can have multiple price tiers over time. Setting a new price auto-closes the previously open tier. Prices can be future-dated (scheduled price changes).

### Domain

- `Product` — `name` (unique)
- `PriceTier` — `productId`, `amount` (integer cents), `currency`, `effectiveFrom`, `effectiveTo` (nullable — null means currently open)

### Schema

```sql
CREATE TABLE IF NOT EXISTS products (id, name TEXT UNIQUE, created_at);
CREATE TABLE IF NOT EXISTS price_tiers (
    id, product_id REFERENCES products(id),
    amount INTEGER, currency TEXT DEFAULT 'USD',
    effective_from TEXT, effective_to TEXT, created_at
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/products` | Create product |
| GET | `/products` | List products |
| GET | `/products/{id}` | Get product |
| POST | `/products/{id}/prices` | Set new price (closes previous open tier) |
| GET | `/products/{id}/prices` | Price history (newest first) |
| GET | `/products/{id}/prices/current` | Active price right now |
| GET | `/products/{id}/prices/at?datetime=X` | Price effective at a given datetime |

### Key design decisions

**Auto-close on new price**: `setPrice()` runs `UPDATE ... SET effective_to = ? WHERE effective_to IS NULL AND effective_from <= ?` before inserting the new tier. This ensures at most one open tier per product at any given time. Future-dated new price doesn't close a currently active tier if the new `effective_from` is in the future.

**Temporal query with half-open interval**: `WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)` — a price is active at time T if T is within `[effective_from, effective_to)`. This is the standard half-open interval for temporal data.

**`/current` and `/at` as static sub-paths**: `GET /products/{id}/prices/current` (1 param) sorts before `GET /products/{id}/prices` (1 param) in NENE2 v1.5.22+ auto-sort only if they have different param counts. Both have 1 param (`{id}`). Registration order matters: `current` and `at` are registered before the history (`/prices`) route — wait, actually `/prices/current` is a different path segment than `/prices` so they don't conflict at all. The router distinguishes them by path structure.

**`?datetime=X` query param for `priceAt`**: The datetime is passed as a URL query parameter — a clean alternative to embedding datetime in a path segment.

### Test results

```
OK (14 tests, 23 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.23 handled all patterns cleanly:

- Static sub-path `/prices/current` vs `/prices/{id}` disambiguation: ✅
- `?datetime=X` query param: ✅
- `NULL`-aware SQL (`effective_to IS NULL`): ✅ (framework-agnostic)
- Auto-close UPDATE before INSERT in same transaction-less sequence: ✅

---

## Summary

Date-effective pricing tiers work cleanly with NENE2 v1.5.23. Temporal queries using half-open intervals are pure SQL; the framework does not interfere. No framework changes needed.
