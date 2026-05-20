# Use SQLite FTS5 Full-Text Search

SQLite's FTS5 extension provides full-text search using an inverted index. This guide covers the schema pattern, trigger-based sync, and the query syntax gotchas you will encounter when accepting user input.

---

## 1. Schema: virtual table + external content + triggers

Use `content=<table>` to keep your data in a normal table and let FTS5 maintain only the search index:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    body,
    author,
    content=articles,   -- FTS5 reads content from the articles table
    content_rowid=id    -- maps FTS5 rowid to articles.id
);

-- Keep FTS index in sync with the base table
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    -- Delete old row from index, then insert updated row
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
END;
```

> **Why triggers?** FTS5 with `content=` does not automatically track changes — you must maintain the index with triggers. The `INSERT INTO fts(fts, rowid, ...) VALUES ('delete', ...)` pattern is FTS5's way to remove a row from the index.

---

## 2. Search query

```php
$rows = $this->executor->fetchAll(
    "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
     FROM articles_fts
     JOIN articles a ON a.id = articles_fts.rowid
     WHERE articles_fts MATCH ?
     ORDER BY rank
     LIMIT ? OFFSET ?",
    [$query, $limit, $offset],
);
```

- `rank` is a virtual column exposed by FTS5. Lower (more negative) values are more relevant. `ORDER BY rank` puts best matches first.
- `snippet(table, column_index, open, close, ellipsis, token_count)` returns a highlighted fragment. Column index is 0-based: `0` = title, `1` = body.

---

## 3. Query syntax gotchas

### 3.1 Hyphen is a column filter prefix — not a phrase separator

**This is the most common source of bugs when accepting user input.**

FTS5 interprets `word-other` as a column filter: it searches the column named `word` for the term `other`. If `word` is not a column in the FTS5 table, SQLite throws an error:

```
General error: 1 no such column: text
```

```
full-text   ← ERROR: "search column 'full' for 'text'" — but 'full' is not a column
full text   ← OK: AND query (matches docs with both "full" AND "text")
"full text" ← OK: phrase query (exact consecutive order)
```

This error propagates as a `DatabaseConnectionException` and results in a 500 response unless you sanitise the input first.

**Sanitise user input before passing to FTS5:**

```php
private function sanitizeFtsQuery(string $query): string
{
    // Replace hyphens with spaces so "full-text" becomes "full text" (AND logic)
    return str_replace('-', ' ', $query);
}
```

Or escape with double-quotes for a phrase match:

```php
private function sanitizeFtsQuery(string $query): string
{
    // Wrap the entire query in double quotes to force phrase matching
    $escaped = str_replace('"', '""', $query);
    return '"' . $escaped . '"';
}
```

### 3.2 No stemming by default

The default `unicode61` tokenizer does not stem. `framework` does not match `frameworks`, and `run` does not match `running`.

Options:

| Approach | How |
|---|---|
| Exact match | Use exact word forms in both documents and queries |
| Prefix match | Append `*` to the query term: `framework*` matches `framework`, `frameworks`, `framework-agnostic` |
| Porter stemmer | Declare `tokenize='porter ascii'` in the `CREATE VIRTUAL TABLE` statement |

**Prefix match example:**

```php
// User types "frame" → append * to match "framework", "frameworks", etc.
$query = trim($userInput) . '*';
```

**Porter stemmer:**

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id,
    tokenize='porter ascii'  -- English stemming
);
```

> The `porter` tokenizer is only available when SQLite is compiled with FTS5 support (standard builds include it). It is useful for English text; for other languages, consider external stemming before indexing.

### 3.3 AND / OR / NOT operators

FTS5 query syntax:

| Syntax | Meaning |
|---|---|
| `one two` | AND: both must be present |
| `one OR two` | OR: either must be present |
| `one NOT two` | NOT: first present, second absent |
| `"one two"` | Phrase: exact consecutive order |
| `one*` | Prefix: matches `one`, `ones`, `only` (wait — prefix match on `one`) |
| `title:query` | Column filter: restrict match to the `title` column |

> **Note**: `NOT` must be uppercase. Lowercase `not` is treated as a search term.

---

## 4. Count search results

Count with a separate query — `COUNT(*)` on the FTS5 match:

```php
$count = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
    [$query],
);
```

---

## 5. Complete repository example

```php
final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $userQuery, int $limit, int $offset): array
    {
        $query = $this->sanitizeFtsQuery($userQuery);

        return $this->executor->fetchAll(
            "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
             FROM articles_fts
             JOIN articles a ON a.id = articles_fts.rowid
             WHERE articles_fts MATCH ?
             ORDER BY rank
             LIMIT ? OFFSET ?",
            [$query, $limit, $offset],
        );
    }

    public function countSearch(string $userQuery): int
    {
        $query = $this->sanitizeFtsQuery($userQuery);
        $row   = $this->executor->fetchOne(
            'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
            [$query],
        );

        return (int) ($row['cnt'] ?? 0);
    }

    private function sanitizeFtsQuery(string $query): string
    {
        // Replace hyphens with spaces: "full-text" → "full text" (AND logic)
        return str_replace('-', ' ', trim($query));
    }
}
```
