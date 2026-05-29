---
title: "How-to: Dynamic Sort & Filter with ORDER BY Injection Prevention"
category: security
tags: [sql-injection, order-by, allowlist, sorting, filtering]
difficulty: intermediate
related: [dynamic-filter-query, sql-orderby-injection, sql-injection-defence]
---

# How-to: Dynamic Sort & Filter with ORDER BY Injection Prevention

> **FT reference**: FT341 (`NENE2-FT/sortlog`) — Dynamic sort/filter API with SQL ORDER BY injection prevention via allowlist, status filter allowlist, ReDoS-immune O(n) validation, 40+ tests covering VULN-A through VULN-L and ATK-01 through ATK-12, all PASS.

This guide shows how to implement a sortable, filterable list endpoint safely. Because `ORDER BY` cannot use parameterized placeholders in SQL, the column and direction must be validated against a strict allowlist before interpolation.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft',
    created_at TEXT    NOT NULL
);
```

## Endpoint

```
GET /articles?sort=created_at&order=desc&status=published&limit=20
```

### Valid Parameters

| Param | Allowed values | Default |
|-------|---------------|---------|
| `sort` | `id`, `title`, `status`, `created_at` | `created_at` |
| `order` | `asc`, `desc` | `desc` |
| `status` | `draft`, `published`, `archived` | (all) |
| `limit` | 1–100 | 20 |

## Response

```php
GET /articles?sort=title&order=asc&status=published
→ 200
{
  "data": [
    {"id": 2, "title": "Alpha", "status": "published", ...},
    {"id": 1, "title": "Beta",  "status": "published", ...}
  ],
  "total": 2,
  "sort": "title",
  "order": "asc"
}
```

## Allowlist Validation — The Only Safe Pattern

`ORDER BY` clauses **cannot use parameterized bind values**. The column name must be interpolated directly into SQL. This makes allowlist validation mandatory.

```php
private const SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
private const SORT_ORDERS  = ['asc', 'desc'];
private const STATUSES     = ['draft', 'published', 'archived'];

public function parseSort(string $sort, string $order): array
{
    // Exact string match in allowlist — O(n), case-sensitive, no regex
    if (!in_array($sort, self::SORT_COLUMNS, true)) {
        throw new ValidationException("Invalid sort column: {$sort}");
    }
    if (!in_array($order, self::SORT_ORDERS, true)) {
        throw new ValidationException("Invalid sort direction: {$order}");
    }
    return [$sort, $order];
}

public function parseStatus(?string $status): ?string
{
    if ($status === null) {
        return null;  // no filter
    }
    if (!in_array($status, self::STATUSES, true)) {
        throw new ValidationException("Invalid status: {$status}");
    }
    return $status;
}
```

**Why `in_array()` instead of regex:**
- `in_array($v, $list, true)` is O(n) — immune to ReDoS
- Regex `/^[a-z_]+$/` on attacker-controlled 50-char payloads can cause catastrophic backtracking
- Strict third argument (`true`) enables type-safe comparison

### Case Sensitivity

The allowlist is case-sensitive by design:

```php
GET /articles?sort=ID       → 422  // 'ID' not in allowlist
GET /articles?sort=TITLE    → 422
GET /articles?sort=Created_At → 422
GET /articles?sort=created_at → 200  ✅ exact match
```

## Query Construction

```php
$sql = 'SELECT * FROM articles';

if ($status !== null) {
    // Status uses a parameterized placeholder (safe)
    $sql .= ' WHERE status = ?';
    $params[] = $status;
}

// Sort column and direction come from the allowlist — safe to interpolate
$sql .= " ORDER BY {$sort} {$order}";
$sql .= ' LIMIT ?';
$params[] = $limit;
```

`ORDER BY` uses interpolated allowlist values; `WHERE` clause values always use `?` placeholders.

## Rejected Payloads

### Injection Patterns → 422

```php
// SQL injection in sort
?sort='; DROP TABLE articles--             → 422
?sort=id UNION SELECT 1,2,3,4,5           → 422
?sort=(SELECT name FROM sqlite_master)    → 422
?sort=CASE WHEN 1=1 THEN id ELSE title END → 422
?sort=created_at--                        → 422  // comment
?sort=created_at%00                       → 422  // null byte
?sort=1                                   → 422  // column index (not in allowlist)

// Direction injection
?order=asc; UNION SELECT 1,2,3--          → 422
?order=DESC;                              → 422

// Status filter injection
?status=' OR '1'='1                       → 422
?status=draft UNION SELECT 1,2--          → 422
?status=1                                 → 422  // must be exact status name
?status=TRUE                              → 422

// Whitespace bypass
?sort=created_at%09                       → 422  // TAB
?sort= created_at                         → 422  // leading space

// Array injection (PSR-7)
?sort[]=created_at                        → 422  // array, not string
```

### Limit Injection → 422

```php
?limit=999999           → 422  // exceeds MAX_LIMIT=100
?limit=9999999999999999999999  → 422  // overflow (strlen > 18)
?limit=-1               → 422  // negative
?limit=10.5             → 422  // float
```

### Valid Requests → 200

```php
GET /articles                                  → 200  // defaults
GET /articles?sort=title&order=asc             → 200
GET /articles?sort=id&order=desc&status=draft  → 200
GET /articles?limit=50                         → 200
```

## Timing Safety

Every rejection is instantaneous (<100ms). The allowlist check uses `in_array()` which short-circuits on first non-match — no regex backtracking:

```php
// ReDoS payload: "aaaa...a!" (50 a's + '!')
// in_array("aaaa...a!", ['id','title','status','created_at'], true) → false immediately
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Interpolate `?sort=` directly: `ORDER BY $sort` | SQL injection — attacker controls `ORDER BY` clause entirely |
| Validate with regex `/^[a-z_]+$/` only | ReDoS on 50+ char payloads; allows unknown column names like `password` |
| Case-insensitive comparison (`strcasecmp`) | `ORDER BY CREATED_AT` is valid SQL but bypasses case-sensitive tests |
| Parameterize `ORDER BY $sort` as a bind value | Most DB drivers silently treat it as a literal or throw an error |
| Allowlist only `sort`, not `order` direction | `order=asc; UNION SELECT ...` bypasses the column check |
| Trust `sort[]` array after PSR-7 parsing | `implode(', ', $sort)` with array injection produces multi-column ORDER BY |
| Omit `status` filter allowlist | `status=admin' OR '1'='1` becomes `WHERE status = 'admin' OR '1'='1'` |
