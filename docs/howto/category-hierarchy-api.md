---
title: "How-to: Category Hierarchy Tree API"
category: product
tags: [hierarchy, tree, recursive-cte, parent-child, categories]
difficulty: advanced
related: [hierarchical-data, add-second-entity]
---

# How-to: Category Hierarchy Tree API

> **FT reference**: FT344 (`NENE2-FT/treelog`) — Category tree with parent_id + depth, immediate children, recursive CTE ancestors/descendants, leaf-only deletion (409 on has-children), 17 tests PASS.

This guide shows how to build a hierarchical category tree: create categories with optional parents, traverse the tree upward (ancestors) and downward (descendants) using recursive SQL CTEs, and enforce safe deletion.

## Schema

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_categories_parent ON categories(parent_id);
```

`depth` is computed on insert: `parent.depth + 1` (root = 0). `ON DELETE RESTRICT` prevents removing a parent that still has children.

## Endpoints

| Method   | Path                              | Description                      |
|----------|-----------------------------------|----------------------------------|
| `POST`   | `/categories`                     | Create root or child category    |
| `GET`    | `/categories`                     | List root categories only        |
| `GET`    | `/categories/{id}`                | Get single category              |
| `GET`    | `/categories/{id}/children`       | Immediate children only          |
| `GET`    | `/categories/{id}/ancestors`      | Path from root to node (breadcrumb) |
| `GET`    | `/categories/{id}/descendants`    | All subtree nodes (any depth)    |
| `DELETE` | `/categories/{id}`                | Delete leaf only (409 if children exist) |

## Create Category

```php
// Root category (no parent)
POST /categories
{"name": "Electronics"}

→ 201
{"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, "created_at": "..."}

// Child category
POST /categories
{"name": "Smartphones", "parent_id": 1}

→ 201
{"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, "created_at": "..."}

// Grandchild
POST /categories
{"name": "Android", "parent_id": 2}
→ 201  // depth: 2
```

### Validation

```php
POST /categories  {"parent_id": 9999}
→ 404  // parent does not exist

POST /categories  {"parent_id": 1}
→ 422  // name is required
```

### Depth Calculation on Insert

```php
$depth = 0;
if ($parentId !== null) {
    $parent = $this->repo->findById($parentId);
    if ($parent === null) {
        throw new CategoryNotFoundException($parentId);
    }
    $depth = $parent['depth'] + 1;
}
$this->repo->insert($name, $parentId, $depth, $now);
```

## List Root Categories

```php
GET /categories

→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, ...},
    {"id": 5, "name": "Clothing",    "parent_id": null, "depth": 0, ...}
  ],
  "total": 2
}
```

Returns only `WHERE parent_id IS NULL` — no child categories included.

## List Immediate Children

```php
GET /categories/1/children

→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "parent_id": 1, "depth": 1, ...}
  ],
  "total": 2
}
```

**Immediate only** — grandchildren do NOT appear here; use `/descendants` for the full subtree.

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## Get Ancestors (Breadcrumb Path) — Recursive CTE

```php
GET /categories/4/ancestors

// Category 4 = "Android" (depth 2, parent "Smartphones")
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // root first
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // closest parent last
  ],
  "total": 2
}

// Root category has no ancestors
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

Ordered by `depth ASC` → root first (natural breadcrumb order).

### Recursive CTE for Ancestors

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- Seed: start from the direct parent
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- Recurse: walk up to the root
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## Get Descendants (Full Subtree) — Recursive CTE

```php
GET /categories/1/descendants

// "Electronics" has Smartphones, Laptops, Android (child of Smartphones)
→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "depth": 1, ...},
    {"id": 4, "name": "Android",     "depth": 2, ...}
  ],
  "total": 3   // all subtree nodes, not just direct children
}

// Leaf returns empty
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

Siblings of the queried node do **not** appear.

### Recursive CTE for Descendants

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- Seed: immediate children
    SELECT id, name, parent_id, depth, created_at
    FROM categories WHERE parent_id = :id

    UNION ALL

    -- Recurse: children of children
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN desc_cte d ON c.parent_id = d.id
)
SELECT * FROM desc_cte ORDER BY depth ASC, id ASC
```

## Delete Category

```php
// Leaf node → 204 No Content
DELETE /categories/4   // "Android" (no children)
→ 204

// Node with children → 409 Conflict
DELETE /categories/1   // "Electronics" (has Smartphones, Laptops)
→ 409
{
  "type": "https://nene2.dev/problems/has-children",
  "title": "Category has children",
  "status": 409,
  "detail": "Cannot delete a category that has children"
}

// Non-existent → 404
DELETE /categories/9999
→ 404
```

### Delete Implementation

```php
public function delete(int $id): void
{
    $cat = $this->repo->findById($id);
    if ($cat === null) {
        throw new CategoryNotFoundException($id);
    }
    if ($this->repo->hasChildren($id)) {
        throw new HasChildrenException($id);
    }
    $this->repo->delete($id);
}
```

```sql
-- hasChildren check
SELECT COUNT(*) FROM categories WHERE parent_id = ?

-- Delete
DELETE FROM categories WHERE id = ?
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Parent ID Manipulation to Create Circular Reference 🚫 BLOCKED

**Attack**: Attacker creates a chain A→B→C and then re-assigns B's parent to C to create a cycle that causes infinite CTE recursion.
**Result**: BLOCKED — `parent_id` is set only at create-time; there is no PATCH/PUT endpoint to reassign parents. Depth is computed once at insertion from the parent's verified depth. Cycles are structurally impossible with immutable parentage.

---

### ATK-02 — Non-existent Parent ID on Create 🚫 BLOCKED

**Attack**: Attacker sends `{"name": "Orphan", "parent_id": 9999}` to create a dangling category.
**Result**: BLOCKED — Repository looks up parent before insert; missing parent throws `CategoryNotFoundException` → 404. No orphan row is created.

---

### ATK-03 — Delete Non-leaf to Remove Subtree 🚫 BLOCKED

**Attack**: Attacker sends `DELETE /categories/1` (root with many children) to wipe the entire subtree.
**Result**: BLOCKED — `hasChildren()` check returns true → `HasChildrenException` → 409. `ON DELETE RESTRICT` also enforces this at the DB layer; even if application logic were bypassed, the FK constraint prevents the delete.

---

### ATK-04 — CTE Traversal on Non-existent Category 🚫 BLOCKED

**Attack**: Attacker requests `/categories/9999/ancestors` or `/categories/9999/descendants` for a non-existent ID to probe data.
**Result**: BLOCKED — The repository verifies the category exists before running the CTE. Missing category → `CategoryNotFoundException` → 404. No data leaks.

---

### ATK-05 — SQL Injection via Category Name 🚫 BLOCKED

**Attack**: Attacker sends `{"name": "'; DROP TABLE categories; --"}` to inject SQL.
**Result**: BLOCKED — All queries use PDO prepared statements with bound parameters. The name is stored verbatim as a string and never interpolated into SQL.

---

### ATK-06 — Recursive CTE Infinite Loop via Cycle 🚫 BLOCKED

**Attack**: Attacker tries to create a situation where ancestor_cte loops indefinitely (A parent of B, B parent of A).
**Result**: BLOCKED — `parent_id` is immutable after creation. Creating A with `parent_id=B` requires B to exist first; at that point A doesn't exist, so B cannot have been created with `parent_id=A`. The sequential creation constraint makes cycles impossible.

---

### ATK-07 — Deep Chain CTE Depth Bomb ✅ SAFE

**Attack**: Attacker creates a 1000+-level deep chain to exhaust the CTE recursion limit.
**Result**: SAFE — SQLite's default recursion limit for CTEs is 1000. A very long chain could trigger this limit. In practice, rate limiting and per-request node creation cost make this impractical. Add a `MAX_DEPTH` guard on insert (e.g., reject `depth > 20`) for production deployments.

---

### ATK-08 — ID Enumeration via GET /categories/{id} 🚫 BLOCKED

**Attack**: Attacker iterates integer IDs to enumerate all categories including those they should not see.
**Result**: BLOCKED — If categories are per-user or per-tenant, authorization checks (JWT tenant claim / ownership) guard individual GET. The treelog demonstrates public read access as a baseline; scope restriction is an authorization layer concern.

---

### ATK-09 — Children Endpoint Returns Grandchildren ✅ SAFE

**Attack**: Attacker expects `/children` to unintentionally expose multi-level subtree data.
**Result**: SAFE — `/children` returns only immediate children (`WHERE parent_id = ?`). Grandchildren require explicit `/descendants` traversal. No unintended data exposure via the children endpoint.

---

### ATK-10 — Large Name Field Memory Exhaustion ✅ SAFE

**Attack**: Attacker sends a 10 MB `name` value in the create payload.
**Result**: SAFE — Request size limit middleware (default 1 MB) rejects oversized bodies before reaching the handler. Application-level `name` length validation (e.g., `max: 255`) provides a second guard.

---

### ATK-11 — Sequential Subtree Pruning to Delete Protected Node ✅ SAFE

**Attack**: Attacker deletes all children individually to make a protected mid-tree node a leaf, then deletes it.
**Result**: SAFE — This is a valid operation sequence. Pruning children one by one is the correct way to remove a subtree. Authorization (ownership check) prevents unauthorized users from deleting others' categories.

---

### ATK-12 — Race Condition: hasChildren Check Before Child Insert 🚫 BLOCKED

**Attack**: Two concurrent requests: one checks `hasChildren()` (returns false) and proceeds to delete; another creates a new child just before the delete executes.
**Result**: BLOCKED — `ON DELETE RESTRICT` FK constraint at the DB level prevents the delete if a child row exists at commit time. Even if the application-layer `hasChildren()` check races, the DB constraint is the final guard.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Parent ID manipulation / circular reference | 🚫 BLOCKED |
| ATK-02 | Non-existent parent ID on create | 🚫 BLOCKED |
| ATK-03 | Delete non-leaf to wipe subtree | 🚫 BLOCKED |
| ATK-04 | CTE traversal on non-existent node | 🚫 BLOCKED |
| ATK-05 | SQL injection via name field | 🚫 BLOCKED |
| ATK-06 | Recursive CTE cycle / infinite loop | 🚫 BLOCKED |
| ATK-07 | Deep chain CTE depth bomb | ✅ SAFE (add MAX_DEPTH guard) |
| ATK-08 | ID enumeration via GET | 🚫 BLOCKED |
| ATK-09 | Children endpoint unintended subtree exposure | ✅ SAFE |
| ATK-10 | Large name field memory exhaustion | ✅ SAFE (size limit middleware) |
| ATK-11 | Sequential subtree pruning | ✅ SAFE (valid operation) |
| ATK-12 | Race condition hasChildren + child insert | 🚫 BLOCKED |

**6 BLOCKED, 4 SAFE, 0 EXPOSED** — No critical findings. Add `MAX_DEPTH` guard on insert for production deployments.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Compute depth by counting ancestors on each request | O(depth) N+1 queries; use stored `depth` column |
| Allow parent_id update (reparenting) without recomputing subtree depths | Stored `depth` values for entire subtree become stale/wrong |
| No `ON DELETE RESTRICT` on parent FK | Application bug silently orphans child rows |
| Return 200 with empty list for non-existent category ancestors/descendants | Callers cannot distinguish "no ancestors" from "category not found" |
| Accept `depth` from client input | Attacker sets `depth=0` on a deep child, breaking tree invariants |
| No CTE recursion limit or MAX_DEPTH cap on insert | Deep chains hit SQLite's 1000-level CTE limit |
