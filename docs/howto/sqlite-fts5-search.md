# How-to: SQLite FTS5 Full-Text Search

> **FT reference**: FT254 (`NENE2-FT/ftslog`) — Full-text search with SQLite FTS5

Demonstrates full-text search (FTS) using SQLite's built-in FTS5 extension. A virtual
`posts_fts` table mirrors the `posts` table and is kept in sync via triggers.
Searching uses `MATCH` with relevance-ranked results via `fts.rank`.

---

## Routes

| Method | Path             | Description                          |
|--------|------------------|--------------------------------------|
| `POST` | `/posts`         | Create a post (indexed automatically) |
| `GET`  | `/posts`         | List all posts                        |
| `GET`  | `/posts/search`  | Full-text search (`?q=`)              |

> **Route order**: `/posts/search` must be registered **before** `/posts/{id}` so that
> the literal segment `search` is not captured as a path parameter.

---

## Schema: FTS5 virtual table + triggers

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '',  -- space-separated tag string
    created_at TEXT    NOT NULL
);

-- FTS5 virtual table: shadows posts for full-text search
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title,
    body,
    tags,
    content='posts',      -- external content table
    content_rowid='id'    -- rowid column in the content table
);

-- Keep FTS index in sync with posts
CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_ad AFTER DELETE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_au AFTER UPDATE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;
```

**`content='posts'`** declares `posts_fts` as a content table — it stores FTS tokens
but delegates actual text storage to `posts`. This avoids duplicating the full text.

**`content_rowid='id'`** tells FTS5 which column in `posts` is the rowid to use for joining.

**Triggers** keep the FTS index in sync. Without them, inserts and updates to `posts`
would not be reflected in `posts_fts`. The delete trigger uses the special
`'delete'` command syntax to remove a row from the FTS index.

---

## Tags as a space-separated string

```php
$tags = isset($body['tags']) && is_string($body['tags']) ? trim($body['tags']) : '';
// e.g. "php api backend"
$post = $this->repo->create($title, $postBody, $tags, $now);
```

Tags are stored as a space-separated string (e.g. `"php api backend"`) rather than a
JSON array or M:N join table. This makes tags searchable by FTS5 without any JOIN —
a search for `kubernetes` matches a post tagged `"docker kubernetes devops"`.

**Trade-off**:

| Approach | FTS searchable | Exact tag filter | Canonical tag entity |
|---|---|---|---|
| Space-separated string | ✅ | ❌ (LIKE needed) | ❌ |
| M:N join table | ❌ (JOIN required) | ✅ (IN clause) | ✅ |
| JSON array column | Limited (`json_each`) | Limited | ❌ |

Use the M:N join table approach (see [`multi-value-tag-filter.md`](multi-value-tag-filter.md))
when exact tag filtering is the primary use case.

---

## Full-text search query: `MATCH` + `rank`

```php
public function search(string $query): array
{
    if (trim($query) === '') {
        return [];
    }

    $rows = $this->executor->fetchAll(
        'SELECT p.*, fts.rank
         FROM posts_fts fts
         JOIN posts p ON p.id = fts.rowid
         WHERE posts_fts MATCH ?
         ORDER BY fts.rank',
        [$query],
    );

    return array_map(
        static fn (array $row): SearchResult => new SearchResult(
            new Post(...),
            (float) $row['rank'],
        ),
        $rows,
    );
}
```

`WHERE posts_fts MATCH ?` searches across all indexed columns (`title`, `body`, `tags`).
The `?` placeholder is a parameterized value — the query string is not interpolated
into SQL, so it cannot alter the query structure.

`fts.rank` is a negative float — lower (more negative) values indicate higher relevance.
`ORDER BY fts.rank` sorts best matches first (ascending, which is most-relevant first).

---

## FTS5 query syntax

FTS5 supports a rich query language passed as the MATCH value:

| Query | Matches |
|-------|---------|
| `php` | Any post containing "php" |
| `php api` | Posts containing "php" OR "api" (default: implicit OR) |
| `php AND api` | Posts containing both "php" and "api" |
| `"quick brown"` | Posts containing the exact phrase "quick brown" |
| `php*` | Posts where any token starts with "php" (prefix search) |
| `title:php` | Posts where the title column contains "php" |
| `php NOT python` | Posts with "php" but not "python" |

Phrase search (`"..."`) matches exact token sequences.
Column-scoped search (`title:php`) limits matching to one column.

---

## Invalid query handling: try-catch → 400

FTS5 throws a `PDOException` (or wraps it) when the query syntax is invalid:

```php
private function searchPosts(ServerRequestInterface $request): ResponseInterface
{
    $query = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

    if ($query === '') {
        return $this->json->create(['error' => 'q query parameter is required'], 422);
    }

    try {
        $results = $this->repo->search($query);
    } catch (\Exception $e) {
        // FTS5 throws on syntax errors (e.g. unclosed quotes: '"unclosed')
        return $this->json->create(['error' => 'invalid search query'], 400);
    }

    return $this->json->create([...]);
}
```

Invalid FTS queries (unclosed quotes, malformed operators) result in a DB exception.
Catching it and returning `400 Bad Request` prevents a `500` from leaking to the client.

---

## Case insensitivity

FTS5 is case-insensitive by default for ASCII characters. A search for `php` matches
posts containing `PHP`, `Php`, or `php`. Non-ASCII case folding requires a custom
tokenizer (`unicode61` or `ascii`). The default `porter` tokenizer applies stemming
for English words.

---

## Search response

```json
GET /posts/search?q=php

{
    "query": "php",
    "total": 2,
    "items": [
        {
            "id": 1,
            "title": "PHP Framework",
            "body": "Building APIs with PHP",
            "tags": "php backend",
            "created_at": "2026-05-27T10:00:00Z",
            "rank": -1.234
        }
    ]
}
```

`rank` is included in each result for client-side display or sorting purposes.
Lower (more negative) `rank` = higher relevance.

---

## Comparison: FTS5 vs LIKE search

| Feature | FTS5 MATCH | LIKE `%term%` |
|---|---|---|
| Indexed | ✅ | ❌ (full scan) |
| Relevance ranking | ✅ (`rank`) | ❌ |
| Multi-word search | ✅ (natural) | ❌ (requires multiple LIKE) |
| Phrase search | ✅ (`"..."`) | Partial (`%quick brown%`) |
| Case insensitive | ✅ (ASCII) | ✅ (with NOCASE) |
| Prefix search | ✅ (`php*`) | ✅ (`php%`) |
| Column-scoped | ✅ (`title:php`) | ❌ |
| Setup cost | FTS virtual table + triggers | None |

FTS5 is preferred for large datasets where search is a primary feature. LIKE is
sufficient for small tables or simple prefix autocomplete.

---

## Related howtos

- [`use-fts5-search.md`](use-fts5-search.md) — adding FTS5 to an existing table
- [`search-autocomplete.md`](search-autocomplete.md) — LIKE-based prefix autocomplete (searchlog FT157)
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — M:N tag filtering with AND/OR semantics
- [`event-analytics-api.md`](event-analytics-api.md) — `json_extract()` for JSON property search
