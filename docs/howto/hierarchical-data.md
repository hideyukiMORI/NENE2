# Hierarchical Data — Self-Referential FK + Materialized Path

Store a tree of categories (or any hierarchy) in a single SQL table using a
**self-referential foreign key** (`parent_id`) plus a **materialized path**
(`/1/3/7/`) to enable O(1) subtree queries.

**Reference implementation:** `FT171 hierarchylog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## When to Use This Pattern

| Use this when… | Consider alternatives when… |
|---|---|
| Depth is bounded (≤ 5–10 levels) | Unlimited depth with frequent re-parenting |
| Subtree reads are common | Tree is write-heavy with many moves |
| Single-database solution is preferred | Graph relationships (multiple parents) |

---

## Schema Design

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,                      -- NULL = root node
    path       TEXT    NOT NULL UNIQUE,      -- materialized path: "/1/", "/1/3/", "/1/3/7/"
    depth      INTEGER NOT NULL DEFAULT 0,  -- 0 = root
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

### Path Convention

- Root node: `/1/` (matches `/{id}/`)
- Level-1 child of root: `/1/3/`
- Level-2 grandchild: `/1/3/7/`
- Always starts and ends with `/`.
- After INSERT, compute `path = parentPath . newId . '/'` and UPDATE the row.

---

## Core Operations

### Create (with path calculation)

```php
// 1. INSERT with a temporary placeholder
$id = $this->db->insert(
    'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
    [$name, $parentId, '__tmp__', $depth, $now],
);
// 2. Fix path now that we know the id
$path = $parentPath . $id . '/';
$this->db->execute('UPDATE categories SET path = ? WHERE id = ?', [$path, $id]);
```

### Subtree Query (O(1) on indexed path column)

```php
// All descendants of node with path "/1/3/"
$rows = $this->db->fetchAll(
    "SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path",
    [$root->path . '%', $rootId],
);
```

### Ancestors

```php
// Path "/1/3/7/" → ancestor ids [1, 3]
$parts = array_filter(explode('/', $node->path));
$ancestorIds = array_filter(
    array_map('intval', $parts),
    fn(int $pid) => $pid !== $node->id,
);
```

### Move (cascade to descendants)

```php
$oldPath = $node->path;
$newPath = $newParentPath . $id . '/';

// Update the node itself
$this->db->execute(
    'UPDATE categories SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
    [$newParentId, $newPath, $newDepth, $id],
);

// Cascade to all descendants
foreach ($this->subtree($id) as $desc) {
    $updatedPath  = $newPath . substr($desc->path, strlen($oldPath));
    $updatedDepth = $desc->depth - $node->depth + $newDepth;
    $this->db->execute(
        'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
        [$updatedPath, $updatedDepth, $desc->id],
    );
}
```

---

## Validation Rules

| Rule | Implementation |
|---|---|
| Max depth | `if ($parent->depth >= MAX_DEPTH - 1) throw CategoryDepthException` |
| Circular (self-move) | `if ($newParentId === $id) throw CategoryCircularException` |
| Circular (descendant) | `if (str_starts_with($newParent->path, $node->path)) throw CategoryCircularException` |
| Delete leaf only | `if ($children !== []) throw CategoryHasChildrenException` |
| Move depth overflow | Check `$newDepth + maxSubtreeRelativeDepth >= MAX_DEPTH` before moving |

---

## Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/categories` | List root categories (`?parent_id=N` for children) |
| `POST` | `/categories` | Create a category |
| `GET` | `/categories/{id}` | Get one category with its ancestor chain |
| `GET` | `/categories/{id}/subtree` | Get all descendants |
| `PUT` | `/categories/{id}` | Rename a category |
| `PATCH` | `/categories/{id}/move` | Move to a new parent (`parent_id: null` for root) |
| `DELETE` | `/categories/{id}` | Delete a leaf (reject if has children → 409) |

---

## Response Shapes

### Category object

```json
{
  "id": 7,
  "name": "PHP Frameworks",
  "parent_id": 3,
  "path": "/1/3/7/",
  "depth": 2,
  "created_at": "2026-01-01T00:00:00+00:00"
}
```

### GET /categories/{id} with ancestors

```json
{
  "data": { ... },
  "ancestors": [
    { "id": 1, "name": "Technology", "depth": 0, ... },
    { "id": 3, "name": "Programming", "depth": 1, ... }
  ]
}
```

---

## Domain Layer Structure

```
src/Category/
├── Category.php                    # readonly entity
├── CategoryRepository.php          # tree operations (create / list / subtree / ancestors / move / delete)
├── RouteRegistrar.php              # wires HTTP handlers to Router
├── CategoryNotFoundException.php
├── CategoryDepthException.php
├── CategoryCircularException.php
└── CategoryHasChildrenException.php
```

---

## Trade-offs vs. Nested Sets / Closure Tables

| Approach | Subtree read | Insert | Move |
|---|---|---|---|
| **Materialized path** (this guide) | Fast (`LIKE`) | O(1) | O(subtree size) |
| Closure table | Fast (join) | O(ancestors) | O(subtree × ancestors) |
| Nested sets | Fast (`BETWEEN`) | O(table) | O(table) |

Materialized path is the sweet spot for bounded-depth trees where moves are
infrequent. Use a closure table when ancestor queries must be index-only and
moves are frequent.

---

## See Also

- [Add a database-backed endpoint](./add-database-endpoint.md)
- [Add pagination](./add-pagination.md)
- [Soft delete](./soft-delete.md)
