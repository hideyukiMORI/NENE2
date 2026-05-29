---
title: "How-to: Expense Tracking API"
category: product
tags: [expense, tracking, pagination, patch, aggregation]
difficulty: intermediate
related: [expense-tracker, budget-tracking, time-tracking]
---

# How-to: Expense Tracking API

> **FT reference**: FT311 (`NENE2-FT/expenselog`) — Expense tracking: YYYY-MM-DD date format validation, category string (open, no enum), monthly summary aggregation by category, offset pagination with limit/offset, PATCH partial update (only provided fields changed), date-range filter, static `/summary` route before dynamic `/{id}`, 34 tests / 67 assertions PASS.

This guide shows how to build an expense tracking API with date filtering, category aggregation, pagination, and partial updates.

## Schema

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        TEXT    NOT NULL,  -- YYYY-MM-DD
    amount      INTEGER NOT NULL,  -- cents
    category    TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);
```

Indexes on `date` and `category` support fast filtering. `amount` in integer cents avoids floating-point precision issues.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/expenses` | List with optional filters + pagination |
| `POST` | `/expenses` | Create expense |
| `GET` | `/expenses/summary` | Monthly category aggregation |
| `GET` | `/expenses/{id}` | Get single expense |
| `PATCH` | `/expenses/{id}` | Partial update |
| `DELETE` | `/expenses/{id}` | Delete |

**Route order**: `/expenses/summary` must be registered **before** `/expenses/{id}` — otherwise `summary` is captured as an `id` parameter.

## Date Validation — YYYY-MM-DD

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

Only strict `YYYY-MM-DD` format is accepted. ISO 8601 strings with time components or timezone offsets are rejected.

## Category — Open String (No Enum)

```php
$category = isset($body['category']) && is_string($body['category']) && $body['category'] !== ''
    ? $body['category']
    : null;
if ($category === null) {
    $errors[] = new ValidationError('category', 'category is required.', 'required');
}
```

Categories are free-form strings (not a closed enum). Any non-empty string is valid, allowing categories like `"food"`, `"transport"`, `"entertainment"` without schema changes.

## Monthly Summary — YYYY-MM Format

```php
// Query: SELECT category, SUM(amount), COUNT(*) WHERE date LIKE 'YYYY-MM-%'
$summary = $this->repository->monthlySummary($month);

return $this->json->create([
    'month'       => $summary->month,
    'total'       => $summary->total,
    'count'       => $summary->count,
    'by_category' => $summary->byCategory, // [{category, total, count}, ...]
]);
```

Month parameter format: `YYYY-MM`. Aggregates all expenses in that month, grouped by category.

## Pagination — Offset-Based

```php
$pagination = PaginationQueryParser::parse($request);
// Returns: { limit: int, offset: int }

$items = $this->repository->findAll($from, $to, $category, $pagination->limit, $pagination->offset);
$total = $this->repository->count($from, $to, $category);

return $this->json->create([
    'items'  => $items,
    'total'  => $total,
    'limit'  => $pagination->limit,
    'offset' => $pagination->offset,
]);
```

Invalid `limit` (non-integer, negative, too large) → 422.

## PATCH — Partial Update

```php
// Only update fields that are present in the body
$date     = array_key_exists('date', $body)     ? $body['date']     : null;
$amount   = array_key_exists('amount', $body)   ? $body['amount']   : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$note     = array_key_exists('note', $body)     ? $body['note']     : null;
```

`array_key_exists()` distinguishes "field not provided" from "field provided as null". Only provided fields are validated and updated.

## Date-Range Filter

```php
// GET /expenses?date_from=2026-01-01&date_to=2026-01-31
$from = QueryStringParser::string($request, 'date_from');
$to   = QueryStringParser::string($request, 'date_to');
$category = QueryStringParser::string($request, 'category');

$items = $this->repository->findAll($from, $to, $category, $limit, $offset);
```

All filter parameters are optional. Null means "no filter on that dimension".

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Register `/expenses/{id}` before `/expenses/summary` | `"summary"` matches as `id`; summary endpoint unreachable |
| Store `amount` as FLOAT | Floating-point precision: `0.1 + 0.2 ≠ 0.3`; use integer cents |
| Accept any date string (ISO 8601 with time) | Inconsistent date comparison in WHERE clauses |
| Closed category enum | New categories require schema migration |
| `isset($body['field'])` for PATCH | `isset()` returns false for `null`; use `array_key_exists()` |
| Count query without same filters as list | Pagination total doesn't match actual filtered count |
| No index on date/category | Full table scan on every filtered list request |
