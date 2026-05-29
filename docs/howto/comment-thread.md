---
title: "How-to: Comment Thread API"
category: product
tags: [comments, threads, pagination, moderation, rbac]
difficulty: intermediate
related: [threaded-comments, threaded-comments-api, content-report-moderation]
---

# How-to: Comment Thread API

This guide demonstrates resource-scoped comment threads with pagination, author-only deletion, and admin override.

## Pattern Overview

- Comments belong to a resource (identified by integer ID).
- Any authenticated user can post a comment on any resource.
- Comments are publicly readable (no auth required to list).
- Authors can delete their own comments; admins can delete any.
- Pagination via `limit` and `offset` query parameters.

## Schema

```sql
CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comments_resource ON comments (resource_id, id ASC);
```

## Pagination Pattern

```php
$stmt = $this->pdo->prepare(
    'SELECT * FROM comments WHERE resource_id = :rid ORDER BY id ASC LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':rid', $resourceId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

The response includes `total` (count of all comments for the resource), `limit`, and `offset` so clients can build pagination controls:

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## Limit Clamping

Invalid or out-of-range limit/offset values are silently clamped to safe defaults:

```php
private function clampInt(string $raw, int $default, int $min, int $max): int
{
    if (!ctype_digit($raw) && $raw !== '') {
        return $default;
    }
    $val = $raw !== '' ? (int) $raw : $default;
    return min(max($val, $min), $max);
}
```

`ctype_digit()` is used to avoid ReDoS on the query string.

## IDOR: Author-Only Deletion

Non-admin users can only delete their own comments. Attempting to delete another user's comment returns 404 (not 403):

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## Resource Isolation

All queries include `WHERE resource_id = :rid`, ensuring comments for resource 1 are never mixed with resource 2.

## Validation Rules

| Field | Rule |
|---|---|
| `X-User-Id` | Required for POST/DELETE; `ctype_digit`, >0 |
| `body` | Non-empty, max 2000 chars |
| `{resourceId}` path | `ctype_digit`, max 18 chars, >0; else 404 |
| `limit` query | Integer 1–100; default 20 |
| `offset` query | Non-negative integer; default 0 |

## Routes

```
POST   /resources/{resourceId}/comments  Post a comment (X-User-Id required)
GET    /resources/{resourceId}/comments  List comments (paginated, public)
DELETE /comments/{id}                   Delete comment (author or admin)
```

## See Also

- FT211 source: `../NENE2-FT/commentlog/`
- Related: `docs/howto/note-taking.md` (FT202, note CRUD)
- Related: `docs/howto/leaderboard-ranking.md` (FT206, resource-scoped data)
