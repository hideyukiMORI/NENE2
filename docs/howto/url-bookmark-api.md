# How-to: URL Bookmark API with Tag Filtering

> **FT reference**: FT265 (`NENE2-FT/linklog`) — URL Bookmark API: UNIQUE URL constraint, comma-separated tag storage, LIKE-based tag matching

Demonstrates a bookmark API that stores URLs with tags as a comma-separated TEXT column.
Duplicate URLs are detected via a `UNIQUE` constraint and surfaced as a `DuplicateUrlException`
mapped to 409 Conflict. Tag filtering uses four LIKE patterns to match a tag regardless of
its position in the comma-separated string.

---

## Routes

| Method   | Path          | Description                                     |
|----------|---------------|-------------------------------------------------|
| `POST`   | `/links`      | Create a bookmark                               |
| `GET`    | `/links`      | List bookmarks (search + tag filter, paginated) |
| `GET`    | `/links/{id}` | Get a single bookmark                           |
| `DELETE` | `/links/{id}` | Delete a bookmark                               |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL UNIQUE,
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    tags        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);
```

`url TEXT NOT NULL UNIQUE` enforces one bookmark per URL at the DB level.
`tags TEXT` stores a comma-separated list (e.g., `"php,api,rest"`). This avoids a separate
`link_tags` join table for small-scale use cases.

---

## Tags: comma-separated TEXT vs M:N join table

| Approach | Query complexity | When to use |
|---|---|---|
| Comma-separated TEXT | LIKE patterns (4 per tag) | Small datasets; rare tag queries |
| M:N join table (`link_tags`) | JOIN + GROUP BY or IN | Large datasets; frequent AND/OR filtering |
| FTS5 with tags column | `WHERE fts MATCH ?` | Full-text search across multiple columns |

Comma-separated TEXT is simpler to implement and suitable when the number of links and tags
is modest. For datasets with thousands of links and complex tag queries (AND filter, exact
counts), a join table (see [`multi-value-tag-filter.md`](multi-value-tag-filter.md)) is preferable.

---

## Tag LIKE matching: four patterns

A tag stored in a comma-separated column can appear in four positions:
1. **Exact match**: `tags = 'php'` (only tag)
2. **At the start**: `tags LIKE 'php,%'` (first of multiple)
3. **In the middle**: `tags LIKE '%,php,%'` (not first, not last)
4. **At the end**: `tags LIKE '%,php'` (last of multiple)

```php
if ($tags !== null) {
    foreach ($tags as $tag) {
        $sql      .= ' AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
        $params[]  = $tag;            // exact: "php"
        $params[]  = $tag . ',%';     // prefix: "php,..."
        $params[]  = '%,' . $tag . ',%';  // middle: "...,php,..."
        $params[]  = '%,' . $tag;     // suffix: "...,php"
    }
}
```

All four patterns are ANDed per tag: a link must match all requested tags. This implements
an AND filter across tags. Each `?` is a parameterized binding — no injection risk.

**Limitation**: A query for tag `ph` would NOT match a stored tag of `php` because the
patterns check for exact delimiters (`,` or string boundaries). Tags are matched by exact
string value, not substring.

---

## Comma-separated tag serialization and deserialization

**Storing**: `implode(',', $tags)` — `['php', 'api', 'rest']` → `'php,api,rest'`

**Reading**: 
```php
$tagsStr = (string) $row['tags'];
$tags    = $tagsStr === '' ? [] : array_values(array_filter(explode(',', $tagsStr)));
```

`array_filter()` removes any empty strings created by leading/trailing commas or double commas.
`array_values()` reindexes to a `list<string>`.

**Tag query parsing**: `?tags=php,api` → split on comma → `['php', 'api']`

```php
$rawTags = QueryStringParser::string($request, 'tags');
$tags    = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

---

## Duplicate URL: custom exception + handler

```php
public function create(string $url, ...): Link
{
    try {
        $this->executor->execute(
            'INSERT INTO links (url, title, description, tags, created_at) VALUES (?, ?, ?, ?, ?)',
            [$url, $title, $description, implode(',', $tags), $createdAt],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new DuplicateUrlException($url);
        }
        throw $e;
    }

    return new Link($this->executor->lastInsertId(), ...);
}
```

The repository catches the generic `DatabaseConnectionException` (thrown by the framework when
a PDO exception occurs), inspects the previous exception's message for `UNIQUE constraint failed`,
and re-throws as a domain-specific `DuplicateUrlException`. This keeps the domain language
(`DuplicateUrlException`) separate from the infrastructure detail (`PDOException`).

The `DuplicateUrlExceptionHandler` middleware catches `DuplicateUrlException` and returns
409 Conflict Problem Details:

```php
return $this->problems->create($request, 'duplicate-url', 'URL already exists.', 409, $e->url);
```

---

## Search: LIKE on title and URL

```php
if ($search !== null) {
    $sql      .= ' AND (title LIKE ? OR url LIKE ?)';
    $params[]  = '%' . $search . '%';
    $params[]  = '%' . $search . '%';
}
```

The search query is applied to both `title` and `url` columns. A single `$search` binding
is repeated for both columns. As with tag filtering, the wildcard `%` is a SQL literal in
the query string, not from user input — the user's search term is bound as a parameter.

---

## Example: tag AND filter

**Request**: `GET /links?tags=php,api`

Matches links that have BOTH `php` AND `api` in their `tags` column:
- `"php,api"` ✓ (php: prefix match, api: suffix match)
- `"rest,php,api"` ✓ (php: middle match, api: suffix match)
- `"php"` ✗ (missing `api`)

---

## Related howtos

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — M:N join table with AND/OR tag filtering (for larger datasets)
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 full-text search as an alternative to LIKE
- [`sql-injection-defence.md`](sql-injection-defence.md) — parameterized LIKE patterns and injection defence
