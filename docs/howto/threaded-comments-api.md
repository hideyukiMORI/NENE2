# How-to: Threaded Comments API

> **FT reference**: FT343 (`NENE2-FT/threadlog`) — Two-level threaded comment system with tombstone deletion (content replaced with `[deleted]`), reply depth enforcement, post-scoped isolation, and reply-to-deleted prevention, 14 tests / 40+ assertions PASS.

This guide shows how to build a comment system with one level of replies: root comments can receive replies, but replies cannot be replied to (maximum depth = 1). Deleted comments are tombstoned, preserving the thread structure.

## Schema

```sql
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT    NOT NULL,           -- opaque post identifier
    parent_id  INTEGER REFERENCES comments(id),  -- NULL = root comment
    author     TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL
);
```

`parent_id IS NULL` = root comment; `parent_id IS NOT NULL` = reply.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/posts/{postId}/comments` | Create root comment |
| `GET`  | `/posts/{postId}/comments` | List comments with replies |
| `GET`  | `/posts/{postId}/comments/{id}` | Get single comment |
| `POST` | `/posts/{postId}/comments/{id}/replies` | Add reply |
| `DELETE` | `/posts/{postId}/comments/{id}` | Soft delete (tombstone) |

## Create Root Comment

```php
POST /posts/post-1/comments
{"author": "alice", "content": "Great post!"}
→ 201
{
  "id": 1,
  "author": "alice",
  "content": "Great post!",
  "parent_id": null,
  "replies": [],
  "deleted": false,
  "created_at": "..."
}

// Missing fields
POST /posts/post-1/comments  {"author": "alice"}
→ 422  // content required
```

## List Comments

```php
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "alice",
      "content": "Root comment",
      "replies": [
        {"id": 2, "author": "bob", "content": "My reply", "parent_id": 1}
      ]
    }
  ]
}
```

Comments are scoped to `post_id`. `post-1`'s comments never appear in `post-2`'s list.

## Get Single Comment

```php
GET /posts/post-1/comments/1
→ 200
{
  "id": 1,
  "author": "alice",
  "content": "Root comment",
  "reply_count": 2,
  "replies": [...]
}

GET /posts/post-1/comments/999
→ 404
```

## Add Reply (Max Depth = 1)

```php
POST /posts/post-1/comments/1/replies
{"author": "bob", "content": "My reply"}
→ 201
{
  "id": 2,
  "parent_id": 1,
  "author": "bob",
  "content": "My reply"
}

// Reply to a reply is rejected (depth would be 2)
POST /posts/post-1/comments/2/replies  {"author": "charlie", "content": "Deep reply"}
→ 409  // depth limit exceeded

// Reply to non-existent comment
POST /posts/post-1/comments/999/replies  {"author": "bob", "content": "X"}
→ 404

// Reply to deleted comment
// (comment 1 already deleted)
POST /posts/post-1/comments/1/replies  {"author": "bob", "content": "X"}
→ 409  // cannot reply to deleted comment

// Missing fields → 422
```

### Depth Check Implementation

```php
public function canReceiveReply(int $commentId): bool
{
    $row = $this->findById($commentId);
    if ($row === null) {
        throw new CommentNotFoundException($commentId);
    }
    if ($row['deleted']) {
        throw new CommentDeletedException($commentId);
    }
    // Only root comments (parent_id = null) can have replies
    return $row['parent_id'] === null;
}
```

Return 409 when `canReceiveReply()` returns false.

## Tombstone Deletion

```php
DELETE /posts/post-1/comments/1
→ 200
{
  "deleted": true,
  "author": "[deleted]",
  "content": "[deleted]"
}

// Deleted comment still appears in list (tombstone)
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "[deleted]",
      "content": "[deleted]",
      "deleted": true,
      "replies": [{"id": 2, "author": "bob", ...}]  // replies still visible
    }
  ]
}
```

Tombstoning preserves thread structure. Replies remain visible even after the parent is deleted.

```php
// Delete already-deleted comment → 404
DELETE /posts/post-1/comments/1  (already deleted)
→ 404

// Unknown comment → 404
DELETE /posts/post-1/comments/999
→ 404
```

### Tombstone SQL

```sql
UPDATE comments
SET deleted = 1, author = '[deleted]', content = '[deleted]', deleted_at = ?
WHERE id = ? AND deleted = 0
-- Only matches non-deleted rows
-- 0 rows updated → 404
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Hard-delete parent comment | Replies become orphans; thread structure breaks |
| Allow unlimited nesting depth | Deep chains create recursive SQL queries or stack overflows |
| Return 404 for reply-to-deleted | Hiding parent state confuses clients; 409 with a clear `detail` is better |
| No `post_id` scope in queries | Comments from other posts appear in the list |
| Check depth client-side only | Attacker bypasses the check by sending direct API requests |
| Show author/content of deleted comment | Defeats the purpose of deletion; always tombstone |
