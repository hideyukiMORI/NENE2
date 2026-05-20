# Field Trial 57 — Full-Text Search with SQLite FTS5 (ftslog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/ftslog/`
**NENE2 version**: 1.5.20
**Theme**: SQLite FTS5 virtual table, trigger-based index sync, MATCH queries, rank ordering, phrase search, invalid query error handling

## Overview

Built a blog post search API to validate SQLite FTS5 full-text search:
- `posts` table with `title`, `body`, `tags` columns
- `posts_fts` FTS5 virtual table with `content='posts'` and automatic trigger-based index maintenance (INSERT/DELETE/UPDATE triggers)
- `GET /posts/search?q=<query>` — returns ranked results matching any indexed column
- FTS5 `MATCH` query with `ORDER BY rank` (best match first)
- Invalid FTS5 query syntax (e.g., unmatched quote) → 400

## Endpoints Implemented

- `POST /posts` — create post with title, body, tags
- `GET /posts` — list all posts
- `GET /posts/search?q=<query>` — FTS5 full-text search (422 if q missing)

## Test Results

17 tests, 29 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — FTS5 `rank` column returns negative floats [LOW]

**Symptom**: FTS5 `rank` values are negative floats (e.g., `-5.432`). A lower absolute value means a better match — counterintuitive without prior FTS5 knowledge.

**Root cause**: FTS5 uses BM25 scoring where lower (more negative) rank = better match. `ORDER BY rank` orders from best to worst correctly despite the unintuitive sign.

**NENE2 impact**: Documentation note useful in `add-database-endpoint.md` or a new `add-full-text-search.md` howto. Not a framework issue.

### Friction 2 — FTS5 MATCH throws `PDOException` on invalid syntax [LOW]

**Symptom**: `"unclosed` (unmatched double quote) causes FTS5 to throw `\PDOException: SQLSTATE[HY000]: General error: 1 fts5: syntax error near "unclosed"`.

**Root cause**: FTS5 query parsing happens at SQL execution time, not at PHP validation time. Catch `\Exception` around the search call.

**Fix**: Wrap the `repo->search()` call in a try/catch and return 400:
```php
try {
    $results = $this->repo->search($query);
} catch (\Exception $e) {
    return $this->json->create(['error' => 'invalid search query'], 400);
}
```

**NENE2 impact**: Document this pattern in a FTS5 howto if one is written.

---

## Patterns Validated

### FTS5 schema with trigger-based content sync

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title, body, tags,
    content='posts', content_rowid='id'
);

CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags) VALUES (new.id, new.title, new.body, new.tags);
END;

-- Similar triggers for UPDATE (delete + reinsert) and DELETE
```

The `content='posts'` option links the FTS index to the `posts` table. Triggers keep the index in sync without manual maintenance.

### MATCH query with JOIN and rank ordering

```php
$rows = $this->executor->fetchAll(
    'SELECT p.*, fts.rank
     FROM posts_fts fts
     JOIN posts p ON p.id = fts.rowid
     WHERE posts_fts MATCH ?
     ORDER BY fts.rank',
    [$query],
);
```

JOIN with `content_rowid='id'` to get the full post data alongside the FTS rank score.

### Case-insensitive search (FTS5 default)

FTS5 is case-insensitive by default. `WHERE posts_fts MATCH 'PHP'` also matches "php", "Php", etc.

### Phrase search with double quotes

FTS5 supports phrase search: `MATCH '"quick brown"'` matches only documents where "quick" immediately precedes "brown". Tested and working.

### FTS5 multi-word default (OR behavior)

`MATCH 'PHP API'` returns posts containing either "PHP" or "API" (FTS5 default is OR for space-separated terms). To require both terms, use `MATCH 'PHP AND API'` or `MATCH '"PHP" "API"'`.

---

## NENE2 Changes Required

None (documentation only). Consider adding `docs/howto/add-full-text-search.md` covering:
1. FTS5 virtual table + trigger pattern
2. MATCH + JOIN query
3. Negative rank semantics
4. Invalid query error handling
