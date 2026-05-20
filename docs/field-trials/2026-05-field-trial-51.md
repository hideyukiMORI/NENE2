# Field Trial 51 — Threaded Comments (commentlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/commentlog/`
**NENE2 version**: 1.5.19
**Theme**: Threaded comments with parent-child nesting (depth-limited to 3), soft-delete with content redaction, `?include_deleted=true` moderation view, recursive in-memory tree building

## Overview

Built a threaded comment system for blog posts. Comments can be top-level or replies (up to 3 levels deep). Soft-deleted comments remain in the thread but display `[deleted]` for author and body. A `?include_deleted=true` query param reveals the actual content for moderation use. The tree structure is built in-memory from a flat DB result using a recursive resolver.

## Endpoints Implemented

- `POST /posts/{postId}/comments` — create comment (author, body, optional parent_id)
- `GET /posts/{postId}/comments` — list threaded comments (optional `?include_deleted=true`)
- `GET /comments/{id}` — show single comment
- `DELETE /comments/{id}` — soft delete comment

## Test Results

18 tests, 36 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

None. Zero frictions in this field trial.

---

## Patterns Validated

### Depth tracking on insert

```php
if ($parentId !== null) {
    $parentRow = $executor->fetchOne('SELECT depth FROM comments WHERE id = ? AND deleted_at IS NULL', [$parentId]);
    $depth = ((int) $parentRow['depth']) + 1;
    if ($depth > self::MAX_DEPTH) {
        throw new MaxDepthExceededException(self::MAX_DEPTH);
    }
}
$executor->insert('INSERT INTO comments (..., depth, ...) VALUES (?, ?, ...)', [..., $depth, ...]);
```

Depth is stored in the DB to avoid recursive queries on insert. `MAX_DEPTH = 3` means 4 levels (0–3).

### Flat fetch → in-memory tree

All comments for a post are fetched in one query (`ORDER BY created_at ASC`), then built into a tree by grouping child IDs and recursively resolving replies. No recursive CTE needed because the data volume is bounded.

```php
private function resolveReplies(array $ids, array $childIds, array $byId): array {
    $result = [];
    foreach ($ids as $id) {
        $children = $this->resolveReplies($childIds[$id] ?? [], $childIds, $byId);
        $result[] = new Comment(..., replies: $children);
    }
    return $result;
}
```

### Soft-delete with content redaction

```php
public function toArray(bool $includeDeleted = false): array {
    $body = $this->isDeleted() && !$includeDeleted ? '[deleted]' : $this->body;
    return [
        'author'     => $this->isDeleted() && !$includeDeleted ? '[deleted]' : $this->author,
        'body'       => $body,
        'deleted_at' => $includeDeleted ? $this->deletedAt : null,
        // ...
    ];
}
```

Soft-deleted comments are structurally preserved (so replies remain visible) but their content is redacted in default view. The `include_deleted` flag is threaded through `toArray()` into nested replies.

### `?include_deleted=true` moderation param via `QueryStringParser::bool()`

```php
$includeDeleted = QueryStringParser::bool($request, 'include_deleted') ?? false;
```

Absent = `null` → `false` (normal view). `?include_deleted=true` → `true` (moderation view). `?include_deleted=false` → explicit `false`.

### Replies remain after parent soft-delete

The soft-delete only sets `deleted_at` on the target comment — it does NOT cascade to children. This is intentional: replies remain visible, which preserves conversation context. Hard delete (with `ON DELETE CASCADE` in schema) would remove the whole subtree.

---

## NENE2 Changes Required

None. This field trial validated existing patterns without discovering new frictions.
