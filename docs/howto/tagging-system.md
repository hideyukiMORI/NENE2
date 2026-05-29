---
title: "Tagging System (M:N)"
category: product
tags: [tagging, many-to-many, atomic-update, n-plus-one]
difficulty: intermediate
related: [tag-label-api, note-management-with-tags, multi-value-tag-filter]
---

# Tagging System (M:N)

Attach tags to posts using a many-to-many join table, with atomic tag replacement and N+1-free tag fetching.

## Overview

A tagging system has three tables: `posts`, `tags`, and `post_tags` (the join table). Posts and tags have an M:N relationship — one post has many tags, one tag belongs to many posts.

## Database Schema

```sql
CREATE TABLE posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);

CREATE TABLE tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE post_tags (
    post_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (tag_id)  REFERENCES tags(id)
);
```

The composite primary key on `(post_id, tag_id)` enforces uniqueness at the DB level.

## Setting Tags Atomically

The `PUT /posts/{id}/tags` endpoint replaces all tags for a post in one operation. Delete first, then insert:

```php
public function setPostTags(int $postId, array $tagNames): array
{
    $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

    foreach ($tagNames as $name) {
        $tag = $this->findTagByName($name);
        if ($tag === null) {
            continue; // Silently skip unknown tag names
        }

        $this->executor->execute(
            'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
            [$postId, $tag->id],
        );
    }

    return $this->findTagsByPostId($postId);
}
```

- Delete-then-insert makes the operation idempotent: calling it twice with the same payload has the same result.
- `INSERT OR IGNORE` prevents a DB error if the same tag name appears twice in the request body.
- Unknown tag names are silently skipped — the client must create tags before assigning them.
- To clear all tags, send `{"tags": []}`.

## Avoiding N+1 Queries

When loading a list of posts (e.g., for tag-based search), fetch all tags in a single `IN` query rather than one query per post:

```php
private function findTagsByPostIds(array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $rows = $this->executor->fetchAll(
        "SELECT t.*, pt.post_id FROM tags t
         INNER JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id IN ({$placeholders})
         ORDER BY t.name ASC",
        $postIds,
    );

    $map = [];
    foreach ($rows as $row) {
        $postId         = (int) $row['post_id'];
        $map[$postId][] = $this->hydrateTag($row);
    }

    return $map;
}
```

This returns `array<int, Tag[]>` keyed by post ID. Two queries total regardless of the number of posts.

## Tag Uniqueness

Tags have a `UNIQUE` constraint on `name`. Duplicate creation returns 409:

```php
public function createTag(string $name, string $now): ?Tag
{
    try {
        $this->executor->execute(
            'INSERT INTO tags (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    } catch (\RuntimeException) {
        return null; // null → handler returns 409
    }

    return $this->findTagByName($name);
}
```

## Tag-Based Search

Filter posts by tag using a JOIN:

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
INNER JOIN tags t ON t.id = pt.tag_id
WHERE t.name = ?
ORDER BY p.id DESC
```

Then batch-load tags for the result set with the `IN` query above.

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/posts` | Create a post |
| `GET` | `/posts/{id}` | Get a post with its tags |
| `POST` | `/tags` | Create a tag (duplicate → 409) |
| `GET` | `/tags` | List all tags (alphabetically) |
| `PUT` | `/posts/{id}/tags` | Replace all tags on a post |
| `GET` | `/tags/{name}/posts` | List posts tagged with a tag |

## Design Notes

- Tags are application-managed entities, not free text. Clients create tags first, then assign them.
- Unknown tag names in `PUT /posts/{id}/tags` are silently ignored. This avoids a round-trip to pre-validate names.
- Tag names are sorted alphabetically in responses for deterministic output.
- `GET /tags/{name}/posts` returns 404 if the tag does not exist, distinguishing "tag unknown" from "tag exists but has no posts" (which returns 200 with an empty array).
