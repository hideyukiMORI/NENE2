# How-to: Multi-value Tag Filter API

> **FT reference**: FT250 (`NENE2-FT/tagfilterlog`) — Multi-value query parameter tag filtering

Demonstrates multi-tag filtering on a posts API using a normalized M:N join table.
Supports AND semantics (posts that have **all** given tags) and OR semantics
(posts that have **any** given tag), with two client-side query formats:
comma-separated (`?tags=php,api`) and PHP-style array (`?tags[]=php&tags[]=api`).

---

## Routes

| Method | Path          | Description                                       |
|--------|---------------|---------------------------------------------------|
| `POST` | `/posts`      | Create a post with optional tags array            |
| `GET`  | `/posts`      | List posts (filterable by tags, AND or OR)        |
| `GET`  | `/posts/{id}` | Get a single post with its tags                   |

---

## Schema: M:N join table

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag     TEXT    NOT NULL,
    PRIMARY KEY (post_id, tag)
);

CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag);
```

`PRIMARY KEY(post_id, tag)` is a composite primary key — it both enforces uniqueness and
serves as an index on `(post_id, tag)`. A separate index on `tag` alone enables efficient
`WHERE tag IN (...)` lookups regardless of `post_id`.

**Alternative: JSON column approach**

Tags can be stored as a JSON array in a TEXT column on the `posts` table:
`tags TEXT NOT NULL DEFAULT '[]'`. This is simpler (no JOIN), but does not support
indexed tag lookups and requires `json_each()` or `json_extract()` for filtering.
The M:N join table is preferred when tag search performance matters.

---

## Create: tag deduplication and alphabetic sort

```php
$rawTags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags    = array_values(array_filter(array_map(
    static fn (mixed $t): string => is_string($t) ? trim($t) : '',
    $rawTags,
), static fn (string $s): bool => $s !== ''));
```

Tags are extracted from the request, trimmed, and filtered to non-empty strings.
Non-string values (numbers, nulls) are coerced to empty strings and dropped.

Inside a transaction:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($title, $body, $tags, $now): Post {
    $id = $tx->insert(
        'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$title, $body, $now],
    );

    // Deduplicate and sort tags
    $uniqueTags = array_values(array_unique($tags));
    sort($uniqueTags);

    foreach ($uniqueTags as $tag) {
        $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$id, $tag]);
    }

    return new Post($id, $title, $body, $uniqueTags, $now);
});
```

`array_unique()` + `sort()` deduplicates and alphabetizes in PHP before writing.
`INSERT OR IGNORE` is a second-layer defence — if the composite PK constraint fires
(e.g., a concurrent write), the insert is skipped rather than throwing.

The response returns tags in sorted order so callers always see a stable list.

---

## AND filter: `HAVING COUNT(DISTINCT tag) = N`

```php
public function findByAllTags(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         GROUP BY p.id
         HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
         ORDER BY p.created_at DESC",
        [...$tags, count($tags)],
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`WHERE pt.tag IN (...)` narrows to rows that have at least one matching tag.
`GROUP BY p.id HAVING COUNT(DISTINCT pt.tag) = N` selects only posts that matched
**all** N tags.

**`CAST(? AS INTEGER)` is required**: PDO binds all parameters as strings by default.
In SQLite, comparing `COUNT(...)` (an integer) to `'2'` (a string) works at runtime
for simple cases, but the explicit cast is safer and documents the intent.

---

## OR filter: `SELECT DISTINCT`

```php
public function findByAnyTag(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT DISTINCT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         ORDER BY p.created_at DESC",
        $tags,
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`SELECT DISTINCT` prevents duplicate rows when a post matches multiple tags from the IN list.
No `HAVING` clause is needed — any single matching tag qualifies the post.

---

## Dual query parameter format

The list endpoint accepts tags in two formats to accommodate different clients:

| Format | Example | Source |
|--------|---------|--------|
| Comma-separated | `?tags=php,api` | `QueryStringParser::commaSeparated()` |
| PHP array style | `?tags[]=php&tags[]=api` | PSR-7 `getQueryParams()` |

```php
private function extractTags(ServerRequestInterface $request): array
{
    // Strategy 1: comma-separated (NENE2 native)
    $csv = QueryStringParser::commaSeparated($request, 'tags');
    if ($csv !== null) {
        return $csv;
    }

    // Strategy 2: PHP-style array params (?tags[]=php&tags[]=api)
    // PSR-7 getQueryParams() parses PHP array syntax natively.
    // NENE2's QueryStringParser has no helper for this — use raw access.
    $params   = $request->getQueryParams();
    $arrayVal = $params['tags'] ?? null;

    if (is_array($arrayVal)) {
        /** @var list<string> $filtered */
        $filtered = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $arrayVal),
            static fn (string $s): bool => $s !== '',
        ));

        return $filtered;
    }

    return [];
}
```

`QueryStringParser::commaSeparated()` handles `?tags=php,api` and returns `null` if
the parameter is absent. When `null`, the fallback checks `getQueryParams()['tags']`,
which PSR-7 implementations parse from `?tags[]=php&tags[]=api` as a PHP array.

The mode parameter selects AND vs OR:

```php
$mode  = QueryStringParser::string($request, 'mode') ?? 'all'; // 'all' = AND, 'any' = OR
$posts = $mode === 'any'
    ? $this->repository->findByAnyTag($tags)
    : $this->repository->findByAllTags($tags);
```

Unknown `mode` values fall through to AND (the safer default — fewer results).

---

## Hydration: N+1 query per post

```php
private function hydrateWithTags(array $row): Post
{
    $tagRows = $this->executor->fetchAll(
        'SELECT tag FROM post_tags WHERE post_id = ? ORDER BY tag ASC',
        [(int) $row['id']],
    );

    $tags = array_map(static fn (array $r): string => (string) $r['tag'], $tagRows);

    return new Post(
        id:        (int) $row['id'],
        title:     (string) $row['title'],
        body:      (string) $row['body'],
        tags:      $tags,
        createdAt: (string) $row['created_at'],
    );
}
```

This performs one extra query per post to load its tags. For small datasets this is
acceptable. For large result sets, replace with a single `GROUP_CONCAT` or
`json_group_array` query:

```sql
SELECT p.*, GROUP_CONCAT(pt.tag ORDER BY pt.tag) AS tags_csv
FROM posts p
LEFT JOIN post_tags pt ON pt.post_id = p.id
GROUP BY p.id
ORDER BY p.created_at DESC
```

Then split `tags_csv` with `explode(',', ...)` in PHP. Note that SQLite's
`GROUP_CONCAT` does not guarantee order without `ORDER BY` inside the aggregate
(SQLite 3.39+ supports `ORDER BY` in `GROUP_CONCAT`).

---

## AND vs OR comparison

| Mode | SQL pattern | Posts with `[php, api]` vs `[php]` vs `[js]` |
|------|-------------|-----------------------------------------------|
| AND (`mode=all`) | `HAVING COUNT(DISTINCT tag) = N` | Only `[php, api]` matches `?tags=php,api` |
| OR (`mode=any`) | `SELECT DISTINCT` | Both `[php, api]` and `[php]` match `?tags=php,api` |
| No tags | No filter | All posts returned |

Empty tag list (`tags=[]` or absent `tags`) always returns all posts in both modes.

---

## Related howtos

- [`tagging-system.md`](tagging-system.md) — tag/label management with entity-scoped M:N relationships
- [`tag-label-api.md`](tag-label-api.md) — tag taxonomy with tag entity CRUD and list filtering
- [`note-management-with-tags.md`](note-management-with-tags.md) — note tags with owner scoping
- [`cursor-pagination.md`](cursor-pagination.md) — combining cursor pagination with tag filter
