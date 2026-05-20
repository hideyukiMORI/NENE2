# Field Trial 31 — Feed Aggregator API with FTS5 Full-Text Search (feedlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/feedlog/`
**NENE2 version**: 1.5.14
**Theme**: SQLite FTS5 full-text search (`MATCH`), multiple combined filters (source + keyword + date range + is_read), read/unread state toggle, bulk mark-all-read

## What was built

An RSS feed aggregator API with full-text search:

- `GET /feed` — list items with `?source=`, `?q=` (FTS5 search), `?is_read=true/false`, `?from=`, `?to=` date filters, pagination
- `POST /feed` — add a feed item (source, title, url, summary, published_at); 409 if URL already exists
- `GET /feed/unread-count` — count of unread items
- `POST /feed/mark-all-read` — mark all unread items as read; returns `{updated: N}`
- `GET /feed/{id}` — show item
- `DELETE /feed/{id}` — delete item
- `POST /feed/{id}/mark-read` — mark single item as read; returns updated item
- `POST /feed/{id}/mark-unread` — mark single item as unread; returns updated item

**FTS5 implementation**: Content table with triggers to keep the virtual FTS table in sync:

```sql
CREATE VIRTUAL TABLE feed_items_fts USING fts5(
    title, summary,
    content='feed_items',
    content_rowid='id'
);
CREATE TRIGGER feed_items_ai AFTER INSERT ON feed_items BEGIN
    INSERT INTO feed_items_fts(rowid, title, summary) VALUES (new.id, new.title, new.summary);
END;
```

Search query uses prefix matching: `fts.feed_items_fts MATCH ?` with `$query . '*'`.

28/28 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. FTS5 content table + trigger setup — no documentation or howto (Medium)

**Description**: Integrating SQLite FTS5 requires:
1. A `CREATE VIRTUAL TABLE ... USING fts5(content='main_table', content_rowid='id')` definition
2. Three triggers (AFTER INSERT / AFTER DELETE / AFTER UPDATE) to keep the FTS index in sync
3. A join query (`JOIN feed_items_fts fts ON f.id = fts.rowid WHERE fts.feed_items_fts MATCH ?`)

None of this is documented in NENE2's howtos. Developers encounter it for the first time here and must know the SQLite FTS5 content table protocol.

**Severity**: Medium — powerful feature, non-obvious setup.

**Pattern used**:
```sql
-- FTS virtual table
CREATE VIRTUAL TABLE feed_items_fts USING fts5(
    title, summary, content='feed_items', content_rowid='id'
);
-- Sync triggers
CREATE TRIGGER feed_items_ai AFTER INSERT ON feed_items BEGIN
    INSERT INTO feed_items_fts(rowid, title, summary) VALUES (new.id, new.title, new.summary);
END;
```

```php
// Search query
'SELECT f.* FROM feed_items f
 JOIN feed_items_fts fts ON f.id = fts.rowid
 WHERE fts.feed_items_fts MATCH ?'
// Param: $query . '*'  (prefix match)
```

**Potential fix**: Add a "Full-text search with SQLite FTS5" section to `add-database-endpoint.md`.

---

### 2. `DatabaseQueryExecutorInterface::execute()` returns row count — useful for bulk updates (Positive)

**Description**: `execute()` returns the number of affected rows, which is exactly what's needed for a "mark all read" endpoint that returns how many items were updated.

```php
public function markAllRead(): int
{
    return $this->executor->execute('UPDATE feed_items SET is_read = 1 WHERE is_read = 0', []);
}
```

No friction — this design is clean and sufficient.

---

### 3. Combining FTS5 JOIN with other WHERE filters — no framework help (Low)

**Description**: When combining FTS5 search with other filters (source, date range, is_read), the query builder must conditionally switch between two SELECT forms: one with the FTS join and one without.

```php
if ($query !== null) {
    $select = 'SELECT f.* FROM feed_items f JOIN feed_items_fts fts ON f.id = fts.rowid';
    $where  = ['fts.feed_items_fts MATCH ?'];
} else {
    $select = 'SELECT f.* FROM feed_items f';
    $where  = [];
}
```

Manageable but slightly awkward. A QueryBuilder (even simple) would help here — but this is a deliberate NENE2 non-goal (thin SQL design).

**Severity**: Low — the pattern is clear, just verbose.

---

### 4. `is_read` boolean query param requires custom parsing — no `QueryStringParser::bool()` shortcut from valid values (Low)

**Description**: `QueryStringParser::bool()` exists but treats any non-`'1'/'true'` string as `false`. For `?is_read=` which should reject invalid values (e.g., `?is_read=maybe` → 422), a manual check is needed:

```php
$isReadRaw = QueryStringParser::string($request, 'is_read');
$isRead    = null;
if ($isReadRaw !== null) {
    if (!in_array($isReadRaw, ['true', 'false', '1', '0'], true)) {
        throw new ValidationException([...]);
    }
    $isRead = in_array($isReadRaw, ['true', '1'], true);
}
```

If `QueryStringParser::bool()` were used directly, `?is_read=maybe` would silently produce `false` instead of 422. This is the same behaviour as documented but worth noting.

**Severity**: Low.

## Tests

28 tests, 49 assertions — all pass.

Coverage: create (201, missing source/title/url 422, invalid date 422, duplicate URL 409), list (empty, returns items, source filter, is_read=false filter, is_read=true filter, date from filter, FTS5 title search, FTS5 summary search, pagination, invalid is_read 422), show (200, 404), mark-read (200, 404), mark-unread (200), unread-count (initially 0, updates on mark-read), mark-all-read (updated count, unread becomes 0), delete (204, 404), infrastructure (security headers + X-Request-Id, unknown route 404).
