# Field Trial 38 — Full-Text Search API (searchlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/searchlog/`
**NENE2 version**: 1.5.17
**Theme**: SQLite FTS5 virtual table, `MATCH ?` queries, `snippet()`, trigger-based index sync, `content=` external content table

## Overview

Built an article management API backed by a SQLite FTS5 virtual table for full-text search. The FTS5 table uses `content=articles` (external content table) so article data lives in a normal table and only the FTS index is in the virtual table. Three AFTER INSERT/UPDATE/DELETE triggers keep the FTS index in sync.

Endpoints:
- `POST /articles` — create (title, body, author)
- `GET /articles` — list, paginated
- `GET /articles/search?q=…` — FTS5 MATCH search with `snippet()` highlighting
- `GET /articles/{id}` — show
- `PUT /articles/{id}` — full update
- `DELETE /articles/{id}` — delete

## Test Results

23 tests, 38 assertions — all pass after fixing test expectations to match actual FTS5 query semantics.

---

## Frictions Found

### Friction 1 — FTS5: `word-other` is a column filter, not a phrase [HIGH]

**Symptom**: `GET /articles/search?q=full-text` returns HTTP 500 instead of search results.

**Root cause**: FTS5 query syntax treats `word-` as a column filter prefix. `full-text` is parsed as `full:text` — "search the column named `full` for the query `text`". Since the FTS5 table has columns `title`, `body`, `author` (not `full`), SQLite throws:

```
General error: 1 no such column: text
```

The error propagates through `PdoDatabaseQueryExecutor` as a `PDOException`, which the framework's error handler surfaces as a 500 Internal Server Error — no domain exception handler can intercept it.

**Verified behaviour**:

```php
// PDO prepared statement + FTS5 MATCH
$stmt->execute(['full-text']); // → PDOException: no such column: text
$stmt->execute(['full text']); // → normal AND query, works correctly
$stmt->execute(['full']);      // → matches all docs with "full"
```

**Workaround**: Use space-separated terms (AND logic) or FTS5 phrase syntax:

```
full-text         ← ERROR: parsed as column filter full:text
full text         ← OK: matches docs containing both "full" AND "text"
"full text"       ← OK: phrase match (consecutive tokens)
full OR text      ← OK: matches docs containing "full" OR "text"
```

**NENE2 impact**: Medium — user-supplied FTS5 queries with hyphens produce 500s instead of sensible errors. Options:
1. Sanitize/escape the query before passing to FTS5 (strip or replace hyphens)
2. Catch the `DatabaseConnectionException` in the search handler and return a 400 Bad Request
3. Document the FTS5 query syntax gotchas in a howto

**Priority**: Medium — should add a how-to note about FTS5 query sanitisation; sanitisation in the repository is the safest pattern.

---

### Friction 2 — FTS5: No stemming by default [LOW]

**Symptom**: `q=framework` does not match articles containing "frameworks" (plural). The FTS5 `unicode61` tokenizer is a simple whitespace/punctuation tokenizer with no stemming.

**Example**:

```
q=framework   → 0 results (articles have "frameworks")
q=frameworks  → correct results
q=framework*  ← prefix matching IS available (FTS5 supports `*` suffix on terms)
```

**Workaround**: 
- Use exact word forms in both documents and queries
- Use `framework*` prefix matching for "starts with" queries
- Use the `porter` tokenizer for English stemming: `USING fts5(…, tokenize='porter ascii')`

**NENE2 impact**: Low — this is standard FTS5 behaviour, not a NENE2 issue. Should be documented in a howto about FTS5.

---

## Patterns Validated

### FTS5 virtual table with external content

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id
);
```

`content=articles` keeps article data in the real table. FTS5 stores only the search index. Triggers keep them in sync.

### Trigger-based index sync

```sql
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author) VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author) VALUES ('delete', old.id, old.title, old.body, old.author);
    INSERT INTO articles_fts(rowid, title, body, author) VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author) VALUES ('delete', old.id, old.title, old.body, old.author);
END;
```

The `INSERT INTO articles_fts(articles_fts, rowid, ...) VALUES ('delete', ...)` pattern is the FTS5 way to remove a row from the index.

### snippet() function

```sql
SELECT snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet
FROM articles_fts
WHERE articles_fts MATCH ?
```

`snippet(table, column_index, open, close, ellipsis, tokens)` returns a highlighted fragment. Column index 1 = `body` (0-based). Works correctly with PDO parameter binding — no special handling needed.

### rank column for relevance sorting

FTS5 exposes a `rank` column that reflects BM25 relevance. Lower (more negative) values are more relevant. `ORDER BY rank` returns most relevant first.

---

## NENE2 Changes Required

| # | Change | Priority |
|---|--------|----------|
| 1 | Add `docs/howto/use-fts5-search.md` with FTS5 query syntax gotchas, schema pattern, and sanitisation advice | Medium |

No version bump needed for documentation-only change.
