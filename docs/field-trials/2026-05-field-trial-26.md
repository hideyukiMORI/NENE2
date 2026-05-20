# Field Trial 26 — Expense Tracker API (expenselog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/expenselog/`
**NENE2 version**: 1.5.9
**Theme**: PATCH partial updates, date-range filtering, monthly aggregation, route ordering with static segments vs path parameters

## What was built

A personal expense tracker API with:

- `GET /expenses` — list with `?from=`, `?to=`, `?category=` filters and pagination
- `POST /expenses` — create with date (YYYY-MM-DD), amount (integer cents), category, note
- `GET /expenses/summary?month=YYYY-MM` — monthly aggregate: total, count, per-category breakdown
- `GET /expenses/{id}` — fetch by ID
- `PATCH /expenses/{id}` — partial update (any subset of: date, amount, category, note)
- `DELETE /expenses/{id}` — delete

`amount` stored as integer (cents) to avoid floating-point issues. SQLite `strftime('%Y-%m', date)` used for monthly grouping.

34/34 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. Route ordering: static segments must precede path parameters (Medium)

**Description**: The router matches in registration order. The static route `GET /expenses/summary` must be registered before the parameterised route `GET /expenses/{id}`. If `{id}` is registered first, a request to `/expenses/summary` matches it with `id = "summary"`, which then fails with a domain-specific 404 (`expense-not-found`) rather than a meaningful routing error.

**Severity**: Medium

**Reproduction**:
```php
// WRONG order — summary hits {id} with id="summary"
$router->get('/expenses/{id}', $this->show(...));
$router->get('/expenses/summary', $this->summary(...)); // never reached

// CORRECT order — static segment matched first
$router->get('/expenses/summary', $this->summary(...));
$router->get('/expenses/{id}', $this->show(...));
```

**Impact**: Silent misbehaviour — no routing error, just a wrong 404 with a confusing message. Easy to introduce when adding sub-resource routes to an existing controller.

**Potential fix**: Document this in `add-custom-route.md`. Optionally warn when a static route is registered after a parameterised route that would shadow it.

---

### 2. PATCH requires `array_key_exists` — no framework helper (Medium)

**Description**: PATCH semantics require distinguishing "field not in body" (keep existing value) from "field present with null/empty" (treat as update). PHP's `isset()` conflates absent with null, requiring `array_key_exists()` at every field.

**Severity**: Medium

**Reproduction**:
```php
$body = JsonRequestBodyParser::parse($request); // array<string, mixed>

// Wrong: isset() cannot distinguish absent from null
$amount = isset($body['amount']) ? $body['amount'] : null;

// Correct but verbose:
$amount = array_key_exists('amount', $body) ? $body['amount'] : null;
```

With five patchable fields, this produces five boilerplate lines of `array_key_exists`. The framework has no helper to extract a PATCH diff.

**Potential fix**: A `PatchExtractor` helper or a note in `implement-patch-endpoint.md` explaining why `array_key_exists` is necessary.

---

### 3. Date format validation — no built-in helper (Low)

**Description**: Validating that a string matches `YYYY-MM-DD` requires a manual `preg_match`. There is no `ValidationError` factory method or format-check helper in the framework.

**Severity**: Low

**Reproduction**:
```php
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

**Impact**: Minor boilerplate. Consistent pattern once learned; no framework change needed immediately.

---

### 4. Aggregation requires raw SQL — no query builder (Positive / By Design)

**Description**: The monthly summary endpoint uses raw `strftime('%Y-%m', date)` in a GROUP BY query. There is no query builder or aggregation helper. This is intentional in NENE2's thin-SQL design — it is not a friction, just a documentation opportunity.

**Pattern used**:
```sql
SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
  FROM expenses
 WHERE strftime('%Y-%m', date) = ?
 GROUP BY category
 ORDER BY total DESC
```

This worked correctly. SQLite's `strftime` is standard and portable within SQLite.

## Tests

34 tests, 67 assertions — all pass.

Coverage: list (empty, pagination, date-from filter, date-to filter, date range, category filter, invalid limit), create (201 with all fields, missing date, invalid date format, negative amount, zero amount, missing category, default note), show (200, 404), PATCH (amount, category, date, preserves unchanged fields, invalid amount, invalid date, 404), delete (204, removes, 404), summary (empty month, aggregates by category, missing month, invalid format), infrastructure (security headers + X-Request-Id, unknown route 404, method not allowed 405).
