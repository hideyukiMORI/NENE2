# How-to: Pagination Boundary & Limit Injection Prevention

> **FT reference**: FT319 (`NENE2-FT/limitlog`) — Offset and cursor pagination with strict limit/page validation, MAX_LIMIT cap enforcement, ReDoS-safe ctype_digit validation, 20 tests / 384 assertions PASS.

This guide shows how to implement safe pagination with both offset and cursor strategies, while preventing integer boundary attacks and limit injection.

## Constants

```php
const DEFAULT_LIMIT = 20;
const MAX_LIMIT     = 100;
```

## Offset Pagination

```php
GET /articles?page=1&limit=10
→ 200
{
  "data": [...],      // 10 items
  "total": 25,
  "limit": 10,
  "page": 1,
  "has_more": true
}
```

```php
// page 3 of 25 items at limit=10 → last page
GET /articles?page=3&limit=10
→ 200  {"data": [...], "has_more": false}  // 5 items
```

**OFFSET calculation**: `(page - 1) * limit` — page must be ≥ 1 to prevent negative OFFSET.

## Cursor Pagination

```php
GET /articles/cursor?limit=5
→ 200  {"data": [...], "next_cursor": 42, "has_more": true}

GET /articles/cursor?after=42&limit=5
→ 200  {"data": [...], "next_cursor": 37, "has_more": true}

GET /articles/cursor?after=37&limit=5
→ 200  {"data": [...], "next_cursor": null, "has_more": false}
```

Cursor is the `id` of the last item: `WHERE id < $after ORDER BY id DESC LIMIT $limit`.

## Author Filter

```php
GET /articles/by-author?author_id=2&limit=10
→ 200  {"data": [...]}  // only author_id = 2 items
```

`author_id` must be a positive integer (same validation as `limit`).

## Limit Validation — `ctype_digit` Pattern

Use `ctype_digit()` for O(n) validation — immune to ReDoS unlike regex `^\d+$`:

```php
/**
 * Parse a query-string integer parameter.
 * Rejects: zero, negative, float, overflow, non-numeric, whitespace.
 */
function parseQueryInt(string $raw, int $min, int $max): int
{
    // Reject empty, floats, signs, whitespace, non-digit chars
    if ($raw === '' || !ctype_digit($raw)) {
        throw new ValidationException(/* 422 */);
    }
    // Guard against 64-bit overflow before cast
    if (strlen($raw) > 18) {
        throw new ValidationException(/* 422 */);
    }
    $val = (int) $raw;
    if ($val < $min || $val > $max) {
        throw new ValidationException(/* 422 */);
    }
    return $val;
}
```

### What `ctype_digit` Blocks

| Input | `ctype_digit` | Why |
|-------|--------------|-----|
| `"10"` | ✅ Pass | Valid digits |
| `"0"` | ✅ Pass (ctype) | Rejected by min=1 check |
| `"-1"` | ❌ Reject | `-` is not a digit |
| `"10.5"` | ❌ Reject | `.` is not a digit |
| `"1e2"` | ❌ Reject | `e` is not a digit |
| `"+10"` | ❌ Reject | `+` is not a digit |
| `" 10"` | ❌ Reject | space is not a digit |
| `"0x10"` | ❌ Reject | `x` is not a digit |
| `"10\x00"` | ❌ Reject | null byte is not a digit |
| 20-digit string | ❌ Reject | strlen > 18 guard |
| ReDoS payload `"1...1x"` | ❌ Reject (fast) | O(n) scan, no backtracking |

### Error Cases

```php
GET /articles?limit=999999  → 422  // exceeds MAX_LIMIT
GET /articles?limit=0       → 422  // min=1
GET /articles?limit=-1      → 422  // not ctype_digit
GET /articles?limit=10.5    → 422  // float
GET /articles?limit=abc     → 422  // non-numeric
GET /articles?page=0        → 422  // negative OFFSET
GET /articles/cursor?after=99999999999999999999  → 422  // overflow
```

## Duplicate Parameter Attack

```php
GET /articles?limit=5&limit=1000
// PHP takes last value: 1000 → exceeds MAX_LIMIT → 422
```

Most PSR-7 implementations take the last occurrence. Either 422 (last value over MAX) or 200 with the valid value is acceptable — never silently use 1000.

## Large Page Number

```php
GET /articles?page=999999&limit=10
→ 200  {"data": [], "has_more": false}  // empty, not a crash
```

A huge page that exceeds the total count is valid — it returns empty data, not an error.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| `(int) $raw` without `ctype_digit` | `-1`, `1.5`, `" 10"` all cast to integers silently |
| Regex `/^\d+$/` for integer validation | Catastrophic backtracking (ReDoS) on long mixed inputs |
| No MAX_LIMIT cap | `limit=999999` dumps entire table in one request |
| Allow `page=0` | `OFFSET = (0-1)*limit = -limit` corrupts or errors the SQL query |
| strlen-only overflow guard | `"1.5"` is 3 chars — short enough to pass but not a valid integer |
| No minimum check on `author_id` | `author_id=0` returns empty result silently; invalid semantically |
