# Field Trial 47 — Multi-Value Tag Filtering (tagfilterlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/tagfilterlog/`
**NENE2 version**: 1.5.17
**Theme**: Multi-value query parameter tag filtering, `?tags=php,api` (comma-separated) vs `?tags[]=php&tags[]=api` (PHP-style array), AND/OR filter semantics, HAVING COUNT with CAST

## Overview

Built a blog post API with tag filtering. Filtering supports both comma-separated format (`?tags=php,api`) and PHP-style array format (`?tags[]=php&tags[]=api`). AND mode requires posts to have ALL specified tags; OR mode requires ANY. Discovered two frictions during implementation.

## Endpoints Implemented

- `POST /posts` — create (title, body, tags[]); tags deduped and sorted
- `GET /posts` — list with optional tag filter: `?tags=php,api` or `?tags[]=php&tags[]=api`; `?mode=all` (AND, default) or `?mode=any` (OR)
- `GET /posts/{id}` — show

## Test Results

16 tests, 26 assertions — pass after 2 logic fixes (HAVING CAST, tag dedup in create).

---

## Frictions Found

### Friction 1 — `HAVING COUNT(DISTINCT ...) = ?` fails without `CAST` [MEDIUM]

**Symptom**: AND-mode tag filter with `HAVING COUNT(DISTINCT pt.tag) = ?` returned 0 results for all queries.

**Root cause**: Same as FT32. `PDOStatement::execute(array)` binds all values as `PDO::PARAM_STR` by default. SQLite compares integer `COUNT()` result against string `'1'` — this fails due to type affinity mismatch (integer < text in SQLite type ordering).

**Fix**: `HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)`

**NENE2 impact**: This is a re-occurrence of the FT32 pattern (documented in FT32 report). The `howto/use-database-queries.md` should note: **always use `CAST(? AS INTEGER)` in `HAVING` clauses when comparing with a bound integer placeholder**.

### Friction 2 — `QueryStringParser` has no helper for PHP-style array params [LOW]

**Symptom**: `?tags[]=php&tags[]=api` is not handled by `QueryStringParser::commaSeparated()` or any other method. The `commaSeparated()` method returns `null` for this input because the query value is an array, not a string.

**Root cause**: `QueryStringParser` covers `string`, `int`, `bool`, and `commaSeparated` (CSV) patterns, but has no method for PHP-style repeated keys (`?key[]=v1&key[]=v2`).

**Workaround**: Access `$request->getQueryParams()` directly and check if the value is an array:
```php
$params   = $request->getQueryParams();
$arrayVal = $params['tags'] ?? null;
if (is_array($arrayVal)) {
    $tags = array_values(array_filter(...));
}
```

**NENE2 impact**: `QueryStringParser::array(string $request, string $key): ?list<string>` would be a useful addition. Low priority — the workaround is clean.

---

## Patterns Validated

### AND-mode tag filter with HAVING COUNT + CAST

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
WHERE pt.tag IN (?, ?, ...)
GROUP BY p.id
HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
ORDER BY p.created_at DESC
```

`CAST(? AS INTEGER)` forces SQLite to compare integers correctly regardless of PDO binding type.

### OR-mode tag filter with DISTINCT

```sql
SELECT DISTINCT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
WHERE pt.tag IN (?, ?, ...)
ORDER BY p.created_at DESC
```

`DISTINCT` prevents duplicate post rows when a post has multiple matching tags.

### Dual query-param format extraction

```php
// Strategy 1: ?tags=php,api (NENE2 native)
$csv = QueryStringParser::commaSeparated($request, 'tags');
if ($csv !== null) {
    return $csv;
}

// Strategy 2: ?tags[]=php&tags[]=api (PSR-7 raw access)
$params   = $request->getQueryParams();
$arrayVal = $params['tags'] ?? null;
if (is_array($arrayVal)) {
    return array_values(array_filter(...));
}

return [];
```

### Tag dedup and sort in create

```php
$uniqueTags = array_values(array_unique($tags));
sort($uniqueTags);
foreach ($uniqueTags as $tag) { ... }
return new Post($id, $title, $body, $uniqueTags, $now);
```

Without dedup + sort in `create()`, the returned `Post` would have unsorted or duplicate tags from the input, while `hydrateWithTags()` (re-fetched from DB) would have the sorted/deduped version — inconsistency between create response and subsequent read responses.

---

## NENE2 Changes Required

- **Candidate**: Add `QueryStringParser::array()` for PHP-style array params (LOW, not urgent — clean workaround exists)
- **Howto update**: Document `CAST(? AS INTEGER)` pattern for HAVING clauses (this is the second time it appeared; FT32 was first)

No version bump needed for this FT (no NENE2 code changes made; candidate is low priority).
