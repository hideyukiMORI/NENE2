# Field Trial 63 — Cursor-Based Pagination (cursorlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/cursorlog/`
**NENE2 version**: 1.5.21
**Theme**: Cursor-based pagination — opaque integer cursor (last-seen ID) for feed traversal without offset drift

## Overview

Built a posts feed API to validate cursor-based pagination as an alternative to the offset pagination tested in FT21:
- **Cursor**: the ID of the last-seen post. `GET /posts?cursor=<id>` returns posts with `id < cursor`, descending.
- **Response shape**: `{ items: [...], next_cursor: <int|null>, has_more: <bool> }`.
- **Limit clamping**: `max(1, min(100, $limit))` to prevent unbounded fetches.
- **Invalid cursor handling**: non-numeric cursor values are silently ignored (treated as no cursor), so the first page is returned rather than a 400.
- **`createList()` validation**: first FT to use `JsonResponseFactory::createList()` added in v1.5.21 — but this FT used the envelope-object shape (`{ items, next_cursor, has_more }`) via `create()` since the response needs metadata alongside the array.

## Endpoints Implemented

- `POST /posts` — create a post (title + author required)
- `GET /posts/{id}` — fetch a single post
- `GET /posts?limit=N&cursor=<id>` — cursor-paginated feed

## Test Results

14 tests, 31 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — No "fetch one extra" helper in NENE2 (Low)

**Context**: Cursor pagination's canonical implementation fetches `limit + 1` rows to detect `has_more` without a second COUNT query. This is a well-known pattern but requires manual discipline:

```php
$fetch = $limit + 1;
// ... fetchAll with LIMIT $fetch
$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // discard the extra row
}
```

**NENE2 status**: No built-in helper for this. The pattern is simple enough that it doesn't warrant a framework primitive, but it's worth documenting in a "cursor pagination" howto.

---

## Patterns Validated

### Fetch-one-extra for `has_more` detection

```php
$fetch = $limit + 1;
$rows = $this->executor->fetchAll(
    'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterCursor, $fetch],
);
$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);
}
```

Avoids a separate COUNT(*) query. The extra row is the "peek" that proves there are more items.

### `next_cursor` = last item's ID

```php
$nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;
```

The cursor is the minimum ID in the page (since descending order). Passing this as `?cursor=<id>` on the next request returns posts strictly before that ID.

### Non-numeric cursor ignored gracefully

```php
$afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;
```

Non-numeric cursor falls back to `null` (first page), preventing a 400 on stale or malformed cursor values. This is appropriate for feed UIs where an outdated cursor should gracefully restart rather than error.

### Limit clamping

```php
$limit = max(1, min(100, $limit));
```

---

## NENE2 Changes Required

None. Cursor pagination is a pure application-layer pattern. All required primitives available in v1.5.21. A howto doc could be added but no framework changes needed.
