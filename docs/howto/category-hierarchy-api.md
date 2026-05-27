# How-to: Category Hierarchy API

> **FT reference**: FT315 (`NENE2-FT/hierarchylog`) — Hierarchical category tree: materialized path for O(1) subtree queries, depth limit (MAX_DEPTH=5), circular reference prevention on move, ancestor lookup via path parsing, leaf-only delete (409 if children exist), 21 tests / 43 assertions PASS.

This guide shows how to build a hierarchical category system using the materialized path pattern, with safe move operations and bounded depth.

## Schema

```sql
CREATE TABLE categories (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL,
    parent_id INTEGER REFERENCES categories(id),
    path      TEXT    NOT NULL,   -- e.g. "/1/3/7/"
    depth     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT   NOT NULL DEFAULT (datetime('now'))
);
```

`path` stores the full ancestor chain as `/id1/id2/.../selfId/`. `depth` is the level (root = 0).

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/categories` | Create category (root or child) |
| `GET` | `/categories` | List categories (optional `?parent_id=`) |
| `GET` | `/categories/{id}` | Get single category with ancestors |
| `GET` | `/categories/{id}/subtree` | Get all descendants |
| `PUT` | `/categories/{id}` | Rename category |
| `PATCH` | `/categories/{id}/move` | Move to new parent |
| `DELETE` | `/categories/{id}` | Delete leaf category |

## Materialized Path — Creation

```php
// Root: path = "/1/"
POST /categories  {"name": "Root"}
→ 201  {"id": 1, "name": "Root", "parent_id": null, "path": "/1/", "depth": 0}

// Child: path = parent_path + childId + "/"
POST /categories  {"name": "Child", "parent_id": 1}
→ 201  {"id": 3, "name": "Child", "parent_id": 1, "path": "/1/3/", "depth": 1}

// Grandchild
POST /categories  {"name": "Grand", "parent_id": 3}
→ 201  {"id": 7, "name": "Grand", "parent_id": 3, "path": "/1/3/7/", "depth": 2}
```

```php
// Calculate path and depth on create
if ($parentId === null) {
    // Temporarily insert, then update path with own ID
    $path  = '/' . $newId . '/';
    $depth = 0;
} else {
    $parent = $this->repo->findById($parentId);
    if ($parent === null) { throw new NotFoundException(); }
    if ($parent->depth >= CategoryRepository::MAX_DEPTH - 1) {
        throw new ValidationException([['field' => 'parent_id', 'message' => 'Max depth exceeded.']]);
    }
    $path  = $parent->path . $newId . '/';
    $depth = $parent->depth + 1;
}
```

## Depth Limit

```php
const MAX_DEPTH = 5; // depths 0–4 allowed (root to level 4)

// Level 4 is the deepest allowed parent
POST /categories  {"name": "Level4", "parent_id": <depth-4 node>}
→ 422  // Cannot go deeper
```

## Subtree Query with Materialized Path

```php
// Get all descendants of node with path "/1/"
SELECT * FROM categories WHERE path LIKE '/1/%' AND id != 1
```

This is O(1) index scan — no recursive CTE needed.

```php
GET /categories/1/subtree
→ 200  {"data": [<Child>, <Grandchild>, ...]}  // excludes root itself
```

## Ancestors from Path

```php
GET /categories/7  (path = "/1/3/7/")
→ 200
{
    "data": {"id": 7, "name": "Grand", "path": "/1/3/7/", "depth": 2, ...},
    "ancestors": [
        {"id": 1, "name": "Root",  ...},
        {"id": 3, "name": "Child", ...}
    ]
}
```

Parse ancestor IDs from `path`: split `/1/3/7/` by `/` → `[1, 3]` (exclude self).

## Move — Circular Reference Prevention

```php
// Self-move
PATCH /categories/1/move  {"parent_id": 1}
→ 422  // Cannot move to self

// Move to own descendant (circular)
PATCH /categories/1/move  {"parent_id": 7}  // 7 is under 1
→ 422  // Would create cycle
```

```php
// Circular check
if ($targetParentId !== null) {
    $target = $this->repo->findById($targetParentId);
    if ($target->path contains '/' . $categoryId . '/') {
        // target is a descendant of the moving node → circular
        throw new ValidationException(...);
    }
}
```

Move to root: `{"parent_id": null}` → depth becomes 0, path becomes `"/id/"`.

## Delete — Leaf Only

```php
DELETE /categories/7  // leaf (no children)
→ 200  {"deleted": true}

DELETE /categories/1  // has children
→ 409 Conflict  // cannot delete non-leaf
```

```php
$childCount = $this->repo->countChildren($categoryId);
if ($childCount > 0) {
    // Return 409 — caller must delete children first
}
```

---

## Vulnerability Assessment

### V-01 — Path Injection via Name ✅ SAFE

**Risk**: Attacker injects `/` in category name to corrupt path semantics.
**Finding**: SAFE — path is constructed from integer IDs only, never from name. `name` is stored separately and never embedded in `path`.

### V-02 — Unbounded Depth / Stack Overflow ✅ SAFE

**Risk**: Deep recursion or unbounded nesting degrades performance or crashes.
**Finding**: SAFE — `MAX_DEPTH = 5` is enforced at creation time. Any attempt to exceed depth 5 returns 422.

### V-03 — Circular Reference via Move ✅ SAFE

**Risk**: Moving a node under its own descendant creates a cycle.
**Finding**: SAFE — move handler checks if target's `path` contains the moving node's ID. Self-move and descendant-move both return 422.

### V-04 — Orphan Creation via Delete ✅ SAFE

**Risk**: Deleting a parent leaves children with broken `parent_id` or dangling path.
**Finding**: SAFE — delete returns 409 Conflict when `children_count > 0`. Children must be deleted first.

### V-05 — Path LIKE Injection ✅ SAFE

**Risk**: Attacker provides crafted `path` to escape `LIKE '/id/%'` subtree query.
**Finding**: SAFE — `path` is never user-supplied. It is computed server-side from verified integer IDs.

### V-06 — Integer Overflow in Depth ✅ SAFE

**Risk**: `depth` counter wraps around or goes negative.
**Finding**: SAFE — depth is derived from parent's depth + 1, bounded by `MAX_DEPTH`. PHP integers don't overflow at realistic depths.

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Path injection via name | ✅ SAFE |
| V-02 | Unbounded depth | ✅ SAFE |
| V-03 | Circular reference | ✅ SAFE |
| V-04 | Orphan on delete | ✅ SAFE |
| V-05 | LIKE injection via path | ✅ SAFE |
| V-06 | Integer overflow in depth | ✅ SAFE |

**6 SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store path as name-based (e.g. `/Root/Child/`) | Rename breaks all descendant paths; ID-based paths are rename-safe |
| No depth limit | Unbounded trees degrade `LIKE` subtree queries; deep nesting causes UX confusion |
| Allow delete with children | Orphaned records with dangling foreign keys; use 409 to force explicit cascade |
| Skip circular check on move | Cycles make subtree queries loop forever or return wrong results |
| Build ancestors with recursive query at read time | O(depth) queries; materialized path gives O(1) ancestor lookup |
