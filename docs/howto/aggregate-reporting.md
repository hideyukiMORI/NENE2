# How-to: Aggregate Reporting API

> **FT reference**: FT245 (`NENE2-FT/agglog`) — Aggregate Reporting API

Demonstrates a multi-dimensional aggregate reporting API where a single orders table
is sliced into summary totals, daily breakdown, status distribution, and top items —
all with optional date-range filtering, `COALESCE` for zero-safe aggregations, and
`COUNT(CASE WHEN...)` for conditional counts without subqueries.

---

## Routes

| Method | Path                 | Description                                          |
|--------|----------------------|------------------------------------------------------|
| `POST` | `/orders`            | Record an order                                      |
| `GET`  | `/reports/summary`   | Total orders, revenue, avg order value, completed count |
| `GET`  | `/reports/daily`     | Revenue and order count per day                      |
| `GET`  | `/reports/by-status` | Order count and revenue grouped by status            |
| `GET`  | `/reports/top-items` | Top items by revenue (limited, ranked)               |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
```

`status` is constrained by a `CHECK` at the DB level as a safety net. `amount` is
stored as an integer (smallest currency unit). `created_at` is an ISO string — date
comparisons use string ordering on `YYYY-MM-DD` format, which is lexicographically
consistent with chronological order.

---

## Summary aggregation: `COALESCE` + `COUNT(CASE WHEN ...)`

The summary endpoint returns several aggregated metrics in a single query:

```php
$row = $this->db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(AVG(amount), 0) AS avg_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
     FROM orders {$where}",
    $params,
);
```

`COALESCE(SUM(amount), 0)` — returns `0` instead of `NULL` when the table has no
matching rows. `SUM()` and `AVG()` return `NULL` on empty sets; `COALESCE` converts
this to a safe zero.

`COUNT(CASE WHEN status = 'completed' THEN 1 END)` — counts only rows where `status =
'completed'`, without a subquery or second pass. `CASE WHEN` returns `NULL` for
non-matching rows; `COUNT` ignores `NULL`, so only completed orders are counted.

This is equivalent to a filtered `COUNT` but runs in a single scan, making it more
efficient than separate queries for each status.

---

## Daily breakdown: `substr()` for date truncation

```php
$rows = $this->db->fetchAll(
    "SELECT substr(created_at, 1, 10) AS date,
            COUNT(*) AS order_count,
            SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY date
     ORDER BY date ASC",
    $params,
);
```

`substr(created_at, 1, 10)` extracts the first 10 characters (`YYYY-MM-DD`) from the
ISO datetime string, grouping all events in the same calendar day. This is an
alternative to SQLite's `strftime('%Y-%m-%d', created_at)` for timestamp strings in
ISO 8601 format with a fixed prefix.

`GROUP BY date` uses the alias — SQLite supports aliasing in `GROUP BY` (unlike some
other databases which require repeating the expression).

---

## Status distribution: `GROUP BY status ORDER BY count DESC`

```php
$rows = $this->db->fetchAll(
    "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY status
     ORDER BY order_count DESC",
    $params,
);
```

`ORDER BY order_count DESC` places the most common status first. The result set has
at most as many rows as there are distinct status values (four in this schema).

---

## Top items: ranked by revenue with `LIMIT`

```php
$rows = $this->db->fetchAll(
    "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY item_name
     ORDER BY revenue DESC
     LIMIT ?",
    $params,
);
```

`ORDER BY revenue DESC LIMIT ?` — parameterized `LIMIT` selects the top N items by
total revenue. The `limit` path parameter is clamped server-side:

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
```

`min(..., MAX_LIMIT)` prevents clients from requesting more than 100 items. Note:
`is_numeric($q['limit'])` is used here (rather than `is_int`) because query string
values are always strings — `is_int` would always fail on query string input.

---

## Dynamic `WHERE` clause with `dateFilter()`

All aggregation queries share a `dateFilter()` helper that appends conditions only
when a date bound is provided:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) {
        $conditions[] = 'created_at >= ?';
        $params[]     = $from;
    }
    if ($to !== null) {
        $conditions[] = 'created_at <= ?';
        $params[]     = $to;
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

When both `from` and `to` are `null`, `$where` is `''` — the full table is scanned.
The caller embeds `{$where}` into the SQL string before the query is executed. The
actual values are still parameterized (`?`) — only the `WHERE` keyword is interpolated.

---

## Date validation: `createFromFormat()` round-trip

Accepting `from` and `to` as YYYY-MM-DD strings requires validating that the date is
both well-formed and semantically valid (e.g., `2026-02-30` is rejected):

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
```

Two-step validation:
1. `preg_match` — rejects non-matching format quickly without date object overhead.
2. `createFromFormat` + round-trip `format()` — detects semantically invalid dates like
   `2026-02-30` (which PHP would overflow to `2026-03-02` if validated only by regex).

The range direction is also validated:
```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

String comparison works correctly here because both dates are `YYYY-MM-DD` — a format
where lexicographic order equals chronological order.

---

## `NENE2` built-ins used

| Built-in | Purpose |
|---|---|
| `ValidationException` / `ValidationError` | Structured `422` with `errors` array |
| `JsonResponseFactory::create()` | Encodes JSON response |
| `Router` constants | `PARAMETERS_ATTRIBUTE` for path params |

---

## Related howtos

- [`event-analytics-api.md`](event-analytics-api.md) — JSON blob analytics with `json_extract()`, `COUNT(DISTINCT)` grouping
- [`cqrs-pattern.md`](cqrs-pattern.md) — SQL VIEW as read model for order aggregation
- [`credit-ledger.md`](credit-ledger.md) — `COALESCE(SUM(amount * direction), 0)` balance calculation
- [`admin-report-aggregation.md`](admin-report-aggregation.md) — admin-scoped aggregation patterns
