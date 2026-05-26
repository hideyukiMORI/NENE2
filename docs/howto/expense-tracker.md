# How-to: Expense Tracker API

This guide shows how to build a personal expense tracking system with category-based filtering,
date-range queries, monthly summary aggregation, and full CRUD using NENE2.
Pattern demonstrated by the **expenselog** field trial (FT223).

## Features

- Create, read, update, delete expenses (date, amount, category, note)
- List with date-range filter (`?from=` / `?to=`) and category filter
- Monthly summary aggregation (total per category per month)
- Pagination with total count
- Category allowlist validation
- Amount validation: positive integer (cents)

## Schema

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category, date DESC);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/expenses` | List expenses (filterable, paginated) |
| `POST` | `/expenses` | Create expense |
| `GET` | `/expenses/summary` | Monthly summary by category |
| `GET` | `/expenses/{id}` | Get single expense |
| `PATCH` | `/expenses/{id}` | Partial update |
| `DELETE` | `/expenses/{id}` | Delete expense |

## Validation Patterns

### Amount (positive integer, stored in cents)

```php
if (!is_int($amount) || $amount <= 0) {
    $errors[] = new ValidationError('amount', 'Amount must be a positive integer (cents).');
}
```

Using `is_int()` rejects floats from JSON (`1.5` is not an int in PHP's strict mode).

### Date (ISO 8601 format)

```php
$date = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    $errors[] = new ValidationError('date', 'date must be a valid YYYY-MM-DD date.');
}
```

Round-trip validation: parse then reformat — guarantees the string was canonical.

### Category Allowlist

```php
private const array VALID_CATEGORIES = [
    'food', 'transport', 'utilities', 'entertainment',
    'health', 'shopping', 'travel', 'other',
];

if (!in_array($category, self::VALID_CATEGORIES, true)) {
    $errors[] = new ValidationError('category', 'Invalid category.');
}
```

## Date-Range Filtering

```php
public function findAll(
    ?string $from = null,
    ?string $to = null,
    ?string $category = null,
    int $limit = 20,
    int $offset = 0,
): array
```

Filters are optional — omit for all-time queries. Dates compared lexicographically (ISO 8601
strings are sortable in UTC).

## Monthly Summary Query

Aggregate by year-month using SQLite's `strftime`:

```sql
SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
FROM expenses
WHERE date BETWEEN :from AND :to
GROUP BY month, category
ORDER BY month DESC, category ASC
```

Returns per-category totals for each month:

```json
{
  "summary": [
    { "month": "2026-05", "category": "food", "total": 42000, "count": 12 },
    { "month": "2026-05", "category": "transport", "total": 8500, "count": 5 }
  ]
}
```

## PATCH Partial Update

Only fields provided in the body are updated — absent fields retain their current values:

```php
if (isset($body['amount'])) {
    if (!is_int($body['amount']) || $body['amount'] <= 0) {
        $errors[] = new ValidationError('amount', 'Amount must be a positive integer.');
    } else {
        $updates['amount'] = $body['amount'];
    }
}
// Same pattern for date, category, note
```

## Validation Patterns

| Field | Check | Reason |
|-------|-------|--------|
| `amount` | `is_int() && > 0` | Rejects floats, zero, negatives |
| `date` | Roundtrip `Y-m-d` parse | Canonical ISO 8601 only |
| `category` | `in_array(strict: true)` | Prevents typos and injection |
| `limit` / `offset` | `max(1, min(100, $limit))` | Prevents DoS and SQL injection |
