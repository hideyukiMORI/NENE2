# How-to: Offset & Cursor Pagination

> **FT reference**: FT325 (`NENE2-FT/pagelog`) — Dual pagination strategy (offset-based and cursor-based) with `next_offset`/`next_cursor`, `has_more`, category filter, 15 tests / 47 assertions PASS.

This guide shows how to implement both offset-based and cursor-based pagination endpoints for the same resource, allowing clients to choose the strategy that fits their use case.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/articles` | Create article |
| `GET`  | `/articles/offset` | Offset pagination |
| `GET`  | `/articles/cursor` | Cursor pagination |
| `GET`  | `/articles/by-category` | Category filter |

## Offset Pagination

```
GET /articles/offset?limit=10&offset=0
→ 200
{
  "items": [...],     // 10 items
  "total": 25,
  "limit": 10,
  "offset": 0,
  "has_more": true,
  "next_offset": 10   // null on last page
}

// Page 2
GET /articles/offset?limit=10&offset=10
→ {"items": [...], "has_more": true, "next_offset": 20}

// Last page
GET /articles/offset?limit=10&offset=20
→ {"items": [...], "has_more": false, "next_offset": null}

// Beyond end
GET /articles/offset?limit=10&offset=100
→ {"items": [], "has_more": false}
```

`next_offset = offset + limit` when `has_more`, else `null`.

## Cursor Pagination

```
GET /articles/cursor?limit=10
→ 200
{
  "items": [...],        // newest first
  "has_more": true,
  "next_cursor": 15      // id of last item returned
}

// Next page using cursor
GET /articles/cursor?limit=10&after=15
→ {"items": [...], "has_more": true, "next_cursor": 5}

// Last page
GET /articles/cursor?limit=10&after=5
→ {"items": [...], "has_more": false, "next_cursor": null}
```

Cursor is the `id` of the last returned item: `WHERE id < $after ORDER BY id DESC LIMIT $limit + 1` (peek one extra to determine `has_more`).

## Category Filter

```
GET /articles/by-category?category=tech&limit=5
→ {"items": [...], "total": N}
```

## Offset vs Cursor — When to Use

| Criterion | Offset | Cursor |
|-----------|--------|--------|
| Random page jump | ✅ `?offset=50` | ❌ Must traverse |
| Total count needed | ✅ Always included | ❌ Expensive |
| Consistent results during inserts | ❌ New row shifts page | ✅ Stable |
| Large dataset performance | ❌ `OFFSET N` scans N rows | ✅ `WHERE id < X` uses index |
| Infinite scroll / feed | ❌ | ✅ |

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return `next_offset` even on last page | Client makes extra empty request |
| Use `OFFSET N` on tables with millions of rows | DB scans N rows before returning results; use cursor for large data |
| Omit `has_more` from cursor response | Client cannot know whether to fetch next page |
| Use timestamp as cursor | Duplicate timestamps cause skipped or repeated rows; use unique integer id |
