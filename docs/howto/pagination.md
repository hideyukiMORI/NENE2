# Pagination

Two patterns are available for paginating list endpoints: **OFFSET** and **cursor** (keyset). Choose based on your data volume and UI requirements.

## Quick comparison

| | OFFSET | Cursor |
|---|---|---|
| Implementation | Simple | Moderate (fetch+1 pattern) |
| Total count | Requires `COUNT(*)` | Not needed |
| Deep page speed | Degrades linearly | Constant (index seek) |
| Page number UI | Easy | Difficult |
| Infinite scroll / feed | Fragile (row drift) | Safe |
| Data changes during browsing | Can cause row drift | Stable |

**Rule of thumb:** Use OFFSET for admin tables with page numbers and small datasets. Use cursor for feeds, infinite scroll, and any table with more than ~10,000 rows.

## OFFSET pagination

```php
private function listByOffset(ServerRequestInterface $request): ResponseInterface
{
    $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

    $items = $repo->fetchAll(
        'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset],
    );
    $total = $repo->fetchOne('SELECT COUNT(*) AS cnt FROM articles', [])['cnt'] ?? 0;

    return $json->create([
        'items'       => $items,
        'limit'       => $limit,
        'offset'      => $offset,
        'total'       => (int) $total,
        'has_more'    => ($offset + $limit) < (int) $total,
        'next_offset' => ($offset + $limit) < (int) $total ? $offset + $limit : null,
    ]);
}
```

**Why OFFSET gets slower**: The database must scan and discard all rows before the offset. For `OFFSET 5000`, the engine reads 5001 rows and throws away the first 5000. You can verify with SQLite:

```sql
EXPLAIN QUERY PLAN
SELECT * FROM articles ORDER BY id DESC LIMIT 20 OFFSET 5000;
-- SCAN articles USING INDEX idx_articles_id_desc
-- The scan still touches 5020 rows.
```

## Cursor pagination

The cursor is the `id` of the last-seen row. Each page fetches rows "before" the cursor (for descending order) using `WHERE id < cursor`, which the index services with a seek — no rows before the cursor are touched.

```php
private function listByCursor(ServerRequestInterface $request): ResponseInterface
{
    $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $afterId = QueryStringParser::int($request, 'after'); // null = first page

    // fetch+1 pattern: detect has_more without a COUNT query
    $fetch = $limit + 1;

    if ($afterId === null) {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    } else {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // discard the extra sentinel row
    }

    $nextCursor = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;

    return $json->create([
        'items'       => $rows,
        'limit'       => $limit,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
}
```

### The fetch+1 pattern

To know whether there is a next page without issuing a `COUNT(*)`:

1. Request `limit + 1` rows.
2. If the result has more than `limit` rows, there is a next page.
3. Discard the last row (`array_pop`) before returning.
4. Use the last remaining row's `id` as `next_cursor`.

This avoids an extra query at the cost of always fetching one extra row.

### Client usage

```
GET /articles/cursor?limit=20
→ { items: [...20 items], has_more: true, next_cursor: 42 }

GET /articles/cursor?limit=20&after=42
→ { items: [...20 items], has_more: true, next_cursor: 22 }

GET /articles/cursor?limit=20&after=22
→ { items: [...2 items], has_more: false, next_cursor: null }
```

## Limit clamping

Always clamp the limit to a sensible range to prevent unbounded queries:

```php
$limit = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
```

This accepts `1–100` and defaults to `20` when the parameter is absent.

## When to switch from OFFSET to cursor

A rough guideline based on table size and typical page depth:

| Rows | Typical depth | Recommendation |
|---|---|---|
| < 10,000 | Any | Either works; OFFSET is simpler |
| 10,000–100,000 | Shallow (page 1–5) | Either; add an index on the sort column |
| 10,000–100,000 | Deep (page 10+) | Cursor preferred |
| > 100,000 | Any | Cursor strongly recommended |

Add an index on your sort column regardless of which approach you use:

```sql
CREATE INDEX idx_articles_id_desc ON articles (id DESC);
```

## Comparing results at the same position

When migrating from OFFSET to cursor, verify correctness by fetching the same "window" of rows both ways:

```php
// OFFSET: rows 11–20 (0-indexed offset=10)
$offsetPage = $get('/articles/offset?limit=10&offset=10');

// Cursor: fetch the id at position 10 (offset=9), use it as the anchor
$anchor     = $get('/articles/offset?limit=1&offset=9');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $get("/articles/cursor?limit=10&after={$anchorId}");

// These should be identical
assert(array_column($offsetPage['items'], 'id') === array_column($cursorPage['items'], 'id'));
```
