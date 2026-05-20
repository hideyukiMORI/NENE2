# Field Trial 75 — Threaded Comments with Nested Replies and Soft Delete (threadlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/threadlog/`
**Theme**: Hierarchical comment system with parent-child relationships, one-level-deep reply nesting, soft delete (content redacted, record retained), and tree assembly in PHP.

---

## What was built

A threaded comment API where comments on a post can receive direct replies (one level deep). Deleted comments are soft-deleted — they remain visible in the thread so that replies stay contextually anchored, but their content and author are replaced with `[deleted]`.

### Domain

- `Comment` — `post_id`, `parent_id` (nullable), `author`, `content`, `deleted` flag, `replies` (list of `Comment`)
- `Comment::toArray(includeReplies)` — when `deleted` is true, `author` and `content` are masked to `[deleted]`
- Reply nesting is limited to **one level**: replies to replies are rejected with 409

### Schema

```sql
CREATE TABLE IF NOT EXISTS comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT NOT NULL,
    parent_id  INTEGER REFERENCES comments(id),
    author     TEXT NOT NULL,
    content    TEXT NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

Self-referential FK: `parent_id REFERENCES comments(id)`.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/posts/{postId}/comments` | Create top-level comment |
| GET | `/posts/{postId}/comments` | List threaded comments for post |
| GET | `/posts/{postId}/comments/{id}` | Get comment with its direct replies |
| POST | `/posts/{postId}/comments/{id}/replies` | Add reply to a comment |
| DELETE | `/posts/{postId}/comments/{id}` | Soft-delete a comment |

### Key design decisions

**Tree assembly in PHP**: `listForPost()` fetches all comments for the post in one query, then builds a `childrenByParent` lookup map and assembles root-level `Comment` objects with their direct replies. No recursive SQL or N+1 queries for the two-level case.

**One-level depth enforcement**: `reply()` checks `$parent->parentId !== null` → 409. This keeps the data model flat while still supporting threaded discussion.

**Soft delete content masking in `toArray()`**: The domain object's `toArray()` method masks `author` and `content` to `[deleted]` when `deleted === true`. The underlying data is preserved in the DB for moderation purposes. Replies to a deleted comment remain visible.

**Reply blocked on deleted parent**: `reply()` checks `$parent->deleted` → 409 "Cannot reply to a deleted comment." This prevents new replies anchored to redacted content.

**Post isolation via path prefix**: `/posts/{postId}/comments` scopes all operations to one post. Different posts have independent comment trees even if they share comment IDs.

**named argument `includeReplies: false`** for reply leaf nodes: When assembling root comments, each direct reply is represented without its own (always-empty) replies array, reducing response payload.

### Test results

```
OK (17 tests, 34 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.22 handled all patterns cleanly:

- Two path parameters (`{postId}/{id}`): ✅
- Action sub-path (`{id}/replies`): ✅
- Self-referential schema with FK: ✅ (SQLite FK is advisory; integrity enforced in PHP)
- Named argument in `toArray(includeReplies: false)`: ✅ (PHP 8 named args, no framework involvement)
- Soft delete pattern: ✅

---

## Summary

Threaded comments with soft-delete work cleanly with NENE2 v1.5.22. Tree assembly in PHP from a single SQL query is straightforward for the two-level case. No framework changes needed.
