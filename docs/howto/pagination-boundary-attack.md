---
title: "How-to: Pagination Boundary & Limit Injection"
category: security
tags: [pagination, limit-injection, integer-validation, boundary-attack]
difficulty: intermediate
related: [pagination-limit-injection, pagination, offset-cursor-pagination]
---

# How-to: Pagination Boundary & Limit Injection

**FT177 — limitlog**

Bullet-proof integer parameter validation for offset and cursor-based pagination —
preventing DB dumps, overflows, type confusion, and ReDoS.

---

## The attack surface

Every pagination endpoint exposes at least two integer parameters (`limit`, `page` / `after`).
Attackers routinely probe these with:

| Attack | Example | Risk |
|--------|---------|------|
| Oversized limit | `limit=999999` | Full-table dump |
| Zero/negative | `limit=0`, `limit=-1` | Negative OFFSET → DB error or wrap |
| Float injection | `limit=10.5`, `limit=1e2` | Silent cast: `(int)"10.5" === 10` |
| Padded / signed | `limit=+10`, `limit= 10` | Silent trim: `(int)" 10" === 10` |
| Integer overflow | `limit=99999999999999999999` | 64-bit wrap to negative |
| Non-numeric | `limit=abc`, `limit=1;DROP TABLE` | Type error or injection |
| Hex / octal | `limit=0x10`, `limit=010` | `0x` → fails ctype; `010` passes! |
| Duplicate param | `?limit=5&limit=1000` | Last-value shadows validated one |
| ReDoS payload | `limit=111...1x` | Exponential regex backtracking |

---

## The `clampInt()` pattern

```php
/**
 * @param array<string, mixed> $params
 */
private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
{
    if (!array_key_exists($key, $params)) {
        return $default;  // absent → use default (not null = invalid)
    }

    $raw = $params[$key];

    // ctype_digit: O(n), ReDoS-immune, rejects '' / '-' / '.' / '+' / ' ' / 'e'
    // ctype_digit('') === false  →  empty string already rejected
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;  // signal: caller must return 422
    }

    // Prevent PHP silent overflow: (int)"99999999999999999999" wraps
    if (strlen($raw) > 18) {
        return null;
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}
```

### Why `ctype_digit`, not regex

| Validator | ReDoS safe? | Rejects `010`? | Rejects `+10`? |
|-----------|------------|----------------|----------------|
| `/^\d+$/` | ❌ exponential on `111...1x` | ✅ | ❌ |
| `ctype_digit()` | ✅ O(n) | ✅ (`0` prefix: passes — but capped by range) | ✅ |
| `is_numeric()` | ✅ | ❌ | ❌ |
| `filter_var(FILTER_VALIDATE_INT)` | ✅ | ✅ | ❌ (`+10` passes!) |

**Use `ctype_digit()`** — it is the strictest and fastest.

### The `010` gotcha

`ctype_digit('010')` → `true` (passes digit check), `(int)'010'` → `10` (decimal, not octal).
This is safe because PHP does not perform octal interpretation on string-casted integers
(unlike `010` as a PHP literal). Confirm in tests if your team is unsure.

---

## Cursor-based pagination

```php
// Fetch one extra row to determine has_more — no COUNT query needed
$rows = $this->db->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $limit + 1],
);

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // drop the sentinel
}

$nextCursor = $hasMore && count($rows) > 0 ? end($rows)->id : null;
```

### Cursor sentinel for "first page"

```php
private const int NO_CURSOR = PHP_INT_MAX;

// GET /articles/cursor (no ?after param) → afterId defaults to PHP_INT_MAX
// WHERE id < PHP_INT_MAX  ==>  effectively all rows
```

---

## Offset pagination — page zero guard

`page=0` produces `OFFSET = (0-1) * limit = -limit` — negative OFFSET is an SQL error
in some databases (MySQL rejects it) or silently wraps in others.

```php
$page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
// min=1 → page=0 returns null → 422
```

---

## Integer overflow guard

PHP's `(int)` cast on a 20-digit string silently wraps:

```php
(int)'99999999999999999999'  // === -1 on 64-bit PHP
```

The `strlen($raw) > 18` guard prevents this before the cast.  18 digits safely covers
`PHP_INT_MAX` (19 digits) with a margin so the cast is always safe.

---

## VULN-A through VULN-L checklist

| # | Test | Expectation |
|---|------|-------------|
| VULN-A | `limit` over MAX (100) | 422 — explicit rejection, not silent truncation |
| VULN-B | `limit=0`, `limit=-1` | 422 — `0` fails min=1; `-` fails ctype_digit |
| VULN-C | Float string `10.5`, `1e2`, `1.0` | 422 — `.` and `e` fail ctype_digit |
| VULN-D | Padded `%2010`, `10%20`, `%2B10` | 422 — space/`+` fail ctype_digit |
| VULN-E | Overflow `9999...` (20 digits) | 422 — strlen > 18 guard |
| VULN-F | Non-numeric, hex `0x10`, SQL injection | 422 — ctype_digit rejects all |
| VULN-G | `page=0` (offset pagination) | 422 — min=1 guard |
| VULN-H | Cursor boundary: `after=0` valid, overflow cursor 422 | Mixed |
| VULN-I | `author_id=0`, `-1`, `abc`, `1.5` | 422 |
| VULN-J | Very large page (page=999999) | 200 empty — must not crash |
| VULN-K | Duplicate param `?limit=5&limit=1000` | 200 (safe) or 422 — never > MAX |
| VULN-L | ReDoS payload `111...1x` (50 digits + x) | 422 in < 100ms |

---

## Testing note: VULN-J vs VULN-A

These look contradictory but serve different goals:

- **VULN-A**: `limit=999999` → **422** — reject unreasonably large row count
- **VULN-J**: `page=999999&limit=10` → **200 empty** — a valid page that happens to have no data

The server must not crash or error on a semantically valid but practically empty page.
`OFFSET = (999999-1) * 10 = 9999980` is a legal SQL OFFSET; the result is simply empty.
