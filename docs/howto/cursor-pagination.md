# How-to: Cursor-Based Pagination

> **FT reference**: FT242 (`NENE2-FT/cursorlog`) — Cursor-Based Pagination API

Demonstrates cursor-based (keyset) pagination as an alternative to offset pagination.
Items are fetched using an ID-based cursor (`WHERE id < ?`), a `limit+1` trick detects
`has_more` without a COUNT query, and the response carries a `next_cursor` value the
caller passes in the next request.

---

## Routes

| Method | Path       | Description                                  |
|--------|------------|----------------------------------------------|
| `POST` | `/posts`   | Create a post                                |
| `GET`  | `/posts`   | List posts with cursor pagination            |
| `GET`  | `/posts/{id}` | Get a single post                         |

---

## Offset vs. cursor pagination

| Concern                   | Offset (`LIMIT ? OFFSET ?`)              | Cursor (`WHERE id < ? ORDER BY id`)        |
|---------------------------|------------------------------------------|--------------------------------------------|
| Performance on large sets | Degrades — DB must skip N rows           | Constant — index seek to cursor position   |
| Stable results            | New rows shift subsequent pages          | Stable — anchored to a specific row        |
| Random access             | Supported (`?page=5`)                    | Not supported (forward-only)               |
| Total count               | Needs a separate `COUNT(*)` query        | No total needed (use `has_more` flag)      |
| Cursor type               | Integer offset (position-based)          | Row-identity value (ID-based)              |

Cursor pagination is preferred for high-volume, real-time feeds where offset skew
(new items inserted between pages) causes duplicated or missing rows.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_id ON posts(id DESC);
```

A descending index on `id` supports `ORDER BY id DESC` efficiently. SQLite's
`INTEGER PRIMARY KEY` is already an alias for `rowid`, so the explicit index speeds up
range queries beyond what the primary key alone provides.

---

## Cursor logic: `WHERE id < ? ORDER BY id DESC LIMIT ?`

The repository fetches one extra row (`limit + 1`) to detect whether more pages exist:

```php
/**
 * Fetch a page of posts in descending ID order.
 *
 * @param int|null $afterCursor  ID of the last-seen post; return posts with id < afterCursor
 * @param int      $limit        Maximum items to return (capped at 100)
 */
public function paginate(?int $afterCursor, int $limit): CursorPage
{
    $limit = max(1, min(100, $limit));
    // Fetch one extra to detect whether there is a next page
    $fetch = $limit + 1;

    if ($afterCursor !== null) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterCursor, $fetch],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);   // discard the extra row
    }

    $items      = array_map(fn (array $row): Post => $this->hydrate($row), $rows);
    $nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;

    return new CursorPage($items, $nextCursor, $hasMore);
}
```

Key steps:
1. **Clamp limit**: `max(1, min(100, $limit))` — prevents 0-row or runaway queries.
2. **Fetch `limit + 1`**: If more than `$limit` rows come back, a next page exists.
3. **Pop the extra**: `array_pop($rows)` discards the (limit+1)-th row used only for detection.
4. **Compute `nextCursor`**: The last item's `id` becomes the cursor the caller sends next.
5. **`$hasMore = false`** when `$nextCursor === null` — no further pages.

The first page has no cursor (`$afterCursor === null`), returning the most-recent posts.
Each subsequent request sends `?cursor=<nextCursor>` to continue from where it left off.

---

## `CursorPage` value object

```php
final readonly class CursorPage
{
    /** @param list<Post> $items */
    public function __construct(
        public array $items,
        public ?int  $nextCursor,
        public bool  $hasMore,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'       => array_map(static fn (Post $p): array => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_more'    => $this->hasMore,
        ];
    }
}
```

`next_cursor` is `null` on the last page (no more items). `has_more` mirrors this:
`true` when `next_cursor` is set, `false` on the last page. Callers stop when
`has_more === false` or `next_cursor === null`.

Response shape:
```json
{
    "items": [...],
    "next_cursor": 42,
    "has_more": true
}
```

---

## Controller: reading and validating the cursor

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $query       = $request->getQueryParams();
    $limit       = isset($query['limit']) ? (int) $query['limit'] : 10;
    $cursorRaw   = isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null;
    $afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

    $page = $this->repo->paginate($afterCursor, $limit);

    return $this->json->create($page->toArray());
}
```

`ctype_digit($cursorRaw)` validates the cursor string before casting to `int`:
- `ctype_digit()` returns `false` for empty strings, negative signs, floats, and
  non-numeric strings — all treated as "no cursor" (first page).
- An invalid cursor falls back to the first page rather than returning an error —
  callers that pass a stale or garbage cursor see the first page, not a `400`.

This is a pragmatic choice: invalid cursors are silently treated as absent. For stricter
APIs, return `422 Unprocessable Entity` when `$cursorRaw` is non-null but fails
`ctype_digit()`.

---

## Limit clamping

```php
$limit = max(1, min(100, $limit));
```

- Minimum `1`: prevents zero-row queries.
- Maximum `100`: caps page size to avoid runaway fetches.

The clamping happens in the repository rather than the controller, ensuring no caller
of `paginate()` can bypass the bounds. The controller reads `$query['limit']` with
a default of `10` when absent.

---

## Pagination contract summary

| Query param | Type | Default | Behaviour |
|---|---|---|---|
| `?limit=N` | integer | 10 | Items per page (clamped 1–100) |
| `?cursor=ID` | integer string | absent | Fetch items with `id < ID`; absent = first page |

| Response field | Type | Meaning |
|---|---|---|
| `items` | array | Serialized items for this page |
| `next_cursor` | int \| null | Pass as `?cursor=` in the next request; `null` = last page |
| `has_more` | bool | `true` if more pages exist |

---

## Comparison with offset pagination

NENE2's `PaginationQueryParser` / `PaginationResponse` built-ins use `LIMIT ? OFFSET ?`.
Use them when:
- Random page access is required (`?page=5`).
- Total item count is displayed to the user.
- Data set is small and rarely grows during traversal.

Use cursor pagination when:
- Feed data grows continuously (chat, activity streams, logs).
- Stable traversal under insertion load is required.
- The data set is large enough that `OFFSET N` becomes slow.

---

## Related howtos

- [`pagination.md`](pagination.md) — offset-based pagination with `PaginationQueryParser` and `PaginationResponse`
- [`activity-feed.md`](activity-feed.md) — real-time feed pattern
- [`add-pagination.md`](add-pagination.md) — adding pagination to an existing endpoint
