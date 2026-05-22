# How to Add Admin Report Aggregation

Build dashboard-style aggregation endpoints with date-range filters, grouping, and limit clamping.

## Schema

```sql
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT NOT NULL, item_name TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','refunded','cancelled')),
    created_at TEXT NOT NULL
);
```

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/orders` | Insert order |
| `GET` | `/reports/summary` | Total orders, revenue, avg, completed count |
| `GET` | `/reports/daily` | Orders grouped by date |
| `GET` | `/reports/by-status` | Orders grouped by status |
| `GET` | `/reports/top-items` | Top N items by revenue |

Query params (all reports): `from=YYYY-MM-DD`, `to=YYYY-MM-DD`

## Date-Range Filter (Safe Parameterized)

Build the WHERE clause dynamically, pass values as bound parameters — never interpolate:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) { $conditions[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $conditions[] = 'created_at <= ?'; $params[] = $to; }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

## Date Validation (Guard Against Injection)

Reject non-ISO-8601 dates before they reach the query:

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // reject 2026-13-01
}
```

Reject `from > to`:

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## Limit Clamping

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
if ($limit <= 0) { /* 422 */ }
```

## Aggregation Queries

**Summary**:
```sql
SELECT COUNT(*) AS total_orders,
       COALESCE(SUM(amount), 0) AS total_revenue,
       COALESCE(AVG(amount), 0) AS avg_order_value,
       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
FROM orders {where}
```

**Daily breakdown** (SQLite date substring):
```sql
SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY date ORDER BY date ASC
```

**Top items**:
```sql
SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY item_name ORDER BY revenue DESC LIMIT ?
```

## Security Notes

- All query parameters validated before use — SQL injection via `from`/`to`/`limit` is rejected with 422.
- Item names and customer IDs stored via parameterized queries — special chars and injection attempts are literal strings.
- `COALESCE(SUM(...), 0)` prevents NULL in summaries when no rows match.
- Limit clamped to `MAX_LIMIT` — prevents resource exhaustion from huge `LIMIT` values.
