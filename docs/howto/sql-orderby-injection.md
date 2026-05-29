---
title: "How to Prevent SQL ORDER BY Injection"
category: security
tags: [sql-injection, orderby-injection, allowlist, parameterized-queries]
difficulty: beginner
related: [sql-injection-defence, dynamic-sort-order-injection, dynamic-filter-query]
---

# How to Prevent SQL ORDER BY Injection

SQL `ORDER BY` clauses cannot be parameterized with standard placeholders (`?`). This means
user-controlled sort columns and directions must never be interpolated into SQL directly.
This guide explains the only safe approach: an explicit allowlist.

---

## The Problem

Prepared statement placeholders protect column values in `WHERE` clauses, but they do **not**
work for column names or sort directions in `ORDER BY`:

```php
// ❌ WRONG — this does NOT protect against injection
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// Many database drivers treat ORDER BY arguments as literals, not identifiers.
```

An attacker sending `?sort=SLEEP(5)` or `?sort=(SELECT password FROM users LIMIT 1)` can
cause time-based attacks, information leakage, or errors that reveal schema details.

---

## The Only Safe Solution: Explicit Allowlist

```php
// ✅ SAFE — allowlist + in_array strict
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

The allowlist values are **hardcoded strings** you control. Only those values ever reach SQL.

---

## Complete Route Handler Pattern

```php
// ── Sort column — MUST be validated against allowlist ─────────────────────────
//
// SECURITY: ORDER BY does not support ? placeholders in standard SQL.
// The ONLY safe approach is an explicit allowlist checked with in_array strict.
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // Array injection: PSR-7 may give array for ?sort[]=id
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }

    // Null byte check — PSR-7 decodes %00 to the actual null byte
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }

    // Allowlist check — strict, case-sensitive.
    // PSR-7 already URL-decodes query strings once (%65 → e), so single-encoded valid column
    // names are accepted. Double-encoded values (%2565 → %65 in $rawSort) are NOT decoded a
    // second time, so they fail the allowlist and are rejected.
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // safe default
}

// ── Sort direction — allowlist only ───────────────────────────────────────────
$rawOrder = $params['order'] ?? null;

if ($rawOrder !== null) {
    if (!is_string($rawOrder)) {
        return $this->responseFactory->create(['error' => 'order must be a string.'], 422);
    }

    $dir = strtolower(trim($rawOrder));

    if (!in_array($dir, MyRepository::SORT_DIRS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('order must be one of: %s.', implode(', ', MyRepository::SORT_DIRS))],
            422,
        );
    }

    $sortDir = $dir;
} else {
    $sortDir = 'desc';  // safe default
}
```

---

## Repository Layer

The repository receives already-validated values and interpolates them directly:

```php
/**
 * $sortCol and $sortDir MUST be allowlist-verified by the caller.
 * This method trusts them and interpolates them directly into SQL.
 *
 * @return array{data: list<Article>, total: int, sort: string, order: string, limit: int}
 */
public function list(string $sortCol, string $sortDir, ?ArticleStatus $status, int $limit): array
{
    $where  = $status !== null ? 'WHERE status = ?' : '';
    $params = $status !== null ? [$status->value] : [];

    // $sortCol and $sortDir are pre-validated — safe to interpolate.
    // Never put raw user input here.
    $sql  = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $limit]);
    ...
}
```

---

## Attack Patterns Blocked by This Approach

| Attack | Input | Result |
|---|---|---|
| DROP TABLE injection | `?sort='; DROP TABLE articles--` | 422 — not in allowlist |
| UNION SELECT exfiltration | `?sort=1; SELECT password` | 422 — not in allowlist |
| Subquery extraction | `?sort=(SELECT name FROM sqlite_master)` | 422 — not in allowlist |
| Time-based blind | `?sort=SLEEP(5)` | 422 — not in allowlist |
| Column index injection | `?sort=1` | 422 — not in allowlist |
| Unknown column | `?sort=password` | 422 — not in allowlist |
| Case/comment bypass | `?sort=CREATED_AT--` | 422 — case-sensitive |
| Null byte bypass | `?sort=created_at%00` | 422 — null byte check |
| Array injection | `?sort[]=created_at` | 422 — type check |
| Double URL encoding | `?sort=cr%2565ated_at` | 422 — PSR-7 decodes once; `cr%65ated_at` not in allowlist |
| Single URL encoding (valid) | `?sort=cr%65ated_at` | 200 — PSR-7 decodes to `created_at` ✓ |
| Direction injection | `?order=asc; UNION SELECT 1--` | 422 — not in allowlist |

---

## Key Points

1. **No `rawurldecode()` after PSR-7**: PSR-7's `getQueryParams()` already decodes the query
   string once. Calling `rawurldecode()` again would allow double-encoded values to slip
   through the allowlist check.

2. **`in_array($value, $allowlist, true)`**: The third argument `true` enables strict
   (type-safe) comparison. Without it, `in_array(0, ['id', 'created_at'])` returns `true`
   because PHP coerces strings to integers.

3. **Case-sensitive check**: Column names should be lower-case and matched exactly. Never
   use `strcasecmp` or `strtolower` before the allowlist check — `CREATED_AT` is not the
   same token as `created_at` from a trust perspective.

4. **Direction: `strtolower(trim())` is safe**: Unlike column names, direction (`asc`/`desc`)
   has only two valid values. Normalizing case before the allowlist check is acceptable since
   the allowlist itself is exhaustive and lowercase.

5. **Document the contract**: The repository method must document that it trusts its inputs.
   Callers must never pass raw user input.

---

## Related

- FT180 — sortlog: SQL ORDER BY Injection & Dynamic Sort/Filter Prevention
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — URI encoding
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
