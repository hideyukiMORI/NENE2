# Threaded Comments

Implement self-referencing comment threads with depth limits and soft delete.

## Overview

A threaded comment system has one table that references itself. Each comment knows its `parent_id` (null for top-level), its `depth` (0-based), and its `status`. Replies nest inside their parent in the response tree.

## Database Schema

```sql
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
```

`depth` is denormalized into the row to avoid recursive ancestor queries on each insert.

## Maximum Depth

Enforce a depth limit at write time:

```php
public const int MAX_DEPTH = 3;

public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

In the route handler, check before inserting:

```php
if (!$parent->canHaveReplies()) {
    return $this->problems->create($request, 'unprocessable-entity', 'Maximum comment depth reached.', 422, '');
}

$comment = $this->repo->addComment(
    $parent->postId, $parentId, $authorName, $body, $parent->depth + 1, $now,
);
```

## Soft Delete

Soft delete replaces the body with `[deleted]` and sets `status = 'deleted'`. Children are preserved:

```php
public function softDelete(int $id): void
{
    $this->executor->execute(
        "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
        [$id],
    );
}
```

The tree fetch returns deleted comments with `[deleted]` bodies so the thread structure remains coherent — readers see a placeholder where the deleted comment was, and its children are still visible.

Attempting to reply to a deleted comment returns 409:

```php
if ($parent->isDeleted()) {
    return $this->problems->create($request, 'conflict', 'Cannot reply to a deleted comment.', 409, '');
}
```

## Building the Comment Tree Without N+1

Load all comments for a post in a single query ordered by ID (parents always have lower IDs than their children). Then assemble the tree in PHP using two passes:

```php
// Pass 1: build raw row map and child-ID adjacency list
foreach ($rows as $row) {
    $rowMap[(int) $row['id']]   = $row;
    $childIds[(int) $row['id']] = [];
}

foreach ($rowMap as $id => $row) {
    $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $roots[] = $id;
    } elseif (isset($childIds[$parentId])) {
        $childIds[$parentId][] = $id;
    }
}

// Pass 2: recursively build Comment value objects from roots
return $this->buildTree($roots, $rowMap, $childIds);
```

Keeping raw rows and `int[]` child-ID lists separate from `Comment` value objects avoids PHPStan type confusion when working with `readonly` classes.

## Separating Row Data from Value Objects

When assembling a tree of readonly value objects recursively, PHPStan needs clean type boundaries. The pattern that works:

1. **Pass 1** — build `array<int, array<string, mixed>> $rowMap` and `array<int, int[]> $childIds` from raw rows. No value objects yet.
2. **Pass 2** — `buildTree()` takes only `int[]` IDs and the two maps, recurses, and hydrates `Comment` objects with fully-assembled children arrays.

This avoids mixing `Comment` objects and `int` IDs in the same array, which would cause a union type that PHPStan cannot narrow.

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/posts` | Create a post |
| `GET` | `/posts/{id}` | Get a post |
| `POST` | `/posts/{id}/comments` | Add top-level comment |
| `GET` | `/posts/{id}/comments` | Get comment tree |
| `POST` | `/comments/{id}/replies` | Reply to a comment |
| `DELETE` | `/comments/{id}` | Soft-delete a comment |

## Design Notes

- `depth` is stored in the row (denormalized) to avoid recursive ancestor queries on each insert.
- `ORDER BY id ASC` guarantees parents appear before their children when loading the flat list.
- Soft delete preserves thread structure — hard delete would orphan child comments.
- Replying to a deleted comment is blocked (409) to prevent ghost threads.
