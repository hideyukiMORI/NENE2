# Field Trial 41 — Hierarchical Categories with Recursive CTE (treelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/treelog/`
**NENE2 version**: 1.5.17
**Theme**: Self-referential FK, computed depth column, recursive CTE for ancestor/descendant traversal, 409 on delete-with-children

## Overview

Built a category tree API with a `parent_id` self-referential FK. Each category stores a pre-computed `depth` field (root=0, children=1, etc.) for ordering. Ancestor and descendant traversal use recursive CTEs with `WITH RECURSIVE`.

## Endpoints Implemented

- `POST /categories` — create (name, parent_id?); depth computed from parent
- `GET /categories` — list root categories (parent_id IS NULL)
- `GET /categories/{id}` — show
- `GET /categories/{id}/children` — immediate children only
- `GET /categories/{id}/ancestors` — path from root to parent (breadcrumb order, depth ASC)
- `GET /categories/{id}/descendants` — full subtree (breadth-first, depth ASC)
- `DELETE /categories/{id}` — 409 if has children, 404 if not found

## Test Results

21 tests, 41 assertions — pass after 1 logic fix (ORDER BY direction).

---

## Frictions Found

### Friction 1 — Recursive CTE ORDER BY direction: depth DESC ≠ root-first [LOW]

**Symptom**: `GET /categories/{id}/ancestors` returned `[Child, Root]` instead of `[Root, Child]`.

**Root cause**: Initial code used `ORDER BY depth DESC`. Since Root has `depth=0` and Child has `depth=1`, descending order returns Child (1) before Root (0). Correct order for breadcrumb traversal is `ORDER BY depth ASC`.

**Fix**: `SELECT * FROM ancestors ORDER BY depth ASC`

**NENE2 impact**: None — this is a SQL logic error in the application, not a framework issue.

---

## Patterns Validated

### Recursive CTE for ancestors

```sql
WITH RECURSIVE ancestors(id, name, parent_id, depth, created_at) AS (
    -- Seed: start from the immediate parent
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = ?)
    UNION ALL
    -- Recurse: walk up via parent_id
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestors a ON a.parent_id = c.id
)
SELECT * FROM ancestors ORDER BY depth ASC
-- Result: [root, ..., immediate_parent] — breadcrumb order
```

### Recursive CTE for descendants

```sql
WITH RECURSIVE descendants(id, name, parent_id, depth, created_at) AS (
    -- Seed: immediate children
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.parent_id = ?
    UNION ALL
    -- Recurse: walk down via parent_id
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN descendants d ON c.parent_id = d.id
)
SELECT * FROM descendants ORDER BY depth ASC, name ASC
```

### Pre-computed depth column

Computing depth at write time (from parent's depth + 1) avoids querying depth in recursive CTEs:

```php
$depth = 0;
if ($parentId !== null) {
    $parentRow = $this->executor->fetchOne('SELECT depth FROM categories WHERE id = ?', [$parentId]);
    $depth = ((int) $parentRow['depth']) + 1;
}
```

This is correct for a simple adjacency list where categories don't move (no re-parenting). If re-parenting is needed, depth must be recomputed for the entire subtree on each move.

### DELETE with children protection

```php
$children = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM categories WHERE parent_id = ?',
    [$id],
);

if ((int) ($children['cnt'] ?? 0) > 0) {
    throw new \DomainException("Cannot delete category #{$id}: it has children.");
}
```

Uses a count query instead of relying on the `ON DELETE RESTRICT` FK constraint — because the constraint error from SQLite comes as a `PDOException` (DatabaseConnectionException), not a domain exception, making it harder to surface a clean 409.

### `HasChildrenExceptionHandler` matching on message content

```php
public function supports(Throwable $exception): bool
{
    return $exception instanceof \DomainException
        && str_contains($exception->getMessage(), 'has children');
}
```

This is a smell — matching on string content is fragile. A dedicated `HasChildrenException` class would be cleaner and is the recommended pattern.

---

## NENE2 Changes Required

None — zero NENE2 frictions; the two issues were application-level (ORDER BY direction, string-match exception handler).

No version bump needed.
