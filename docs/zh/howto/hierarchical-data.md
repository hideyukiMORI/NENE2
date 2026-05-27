# 层级数据——自引用 FK + 物化路径

> **FT 参考**：FT171（`NENE2-FT/hierarchylog`）——使用自引用 FK 和物化路径实现 O(1) 子树查询的层级分类。

使用**自引用外键**（`parent_id`）加**物化路径**（`/1/3/7/`）在单个 SQL 表中存储分类树（或任何层级结构），以实现 O(1) 子树查询。

---

## 使用此模式的场景

| 适用场景 | 考虑其他方案 |
|----------|-------------|
| 深度有限（≤ 5–10 层） | 无限深度且频繁重新挂载 |
| 子树读取频繁 | 树是写入密集型，经常移动节点 |
| 优先使用单数据库解决方案 | 图关系（多个父节点） |

---

## 数据库结构设计

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,                      -- NULL = 根节点
    path       TEXT    NOT NULL UNIQUE,      -- 物化路径："/1/"、"/1/3/"、"/1/3/7/"
    depth      INTEGER NOT NULL DEFAULT 0,  -- 0 = 根
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

### 路径约定

- 根节点：`/1/`（匹配 `/{id}/`）
- 根的一级子节点：`/1/3/`
- 二级孙节点：`/1/3/7/`
- 始终以 `/` 开头和结尾。
- INSERT 后，计算 `path = parentPath . newId . '/'` 并 UPDATE 该行。

---

## 核心操作

### 创建（含路径计算）

```php
// 1. 使用临时占位符 INSERT
$id = $this->db->insert(
    'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
    [$name, $parentId, '__tmp__', $depth, $now],
);
// 2. 现在已知 id，修正路径
$path = $parentPath . $id . '/';
$this->db->execute('UPDATE categories SET path = ? WHERE id = ?', [$path, $id]);
```

### 子树查询（在已索引的 path 列上 O(1)）

```php
// 路径为 "/1/3/" 节点的所有后代
$rows = $this->db->fetchAll(
    "SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path",
    [$root->path . '%', $rootId],
);
```

### 祖先

```php
// 路径 "/1/3/7/" → 祖先 id [1, 3]
$parts = array_filter(explode('/', $node->path));
$ancestorIds = array_filter(
    array_map('intval', $parts),
    fn(int $pid) => $pid !== $node->id,
);
```

### 移动（级联到后代）

```php
$oldPath = $node->path;
$newPath = $newParentPath . $id . '/';

// 更新节点本身
$this->db->execute(
    'UPDATE categories SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
    [$newParentId, $newPath, $newDepth, $id],
);

// 级联到所有后代
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

## 校验规则

| 规则 | 实现 |
|------|------|
| 最大深度 | `if ($parent->depth >= MAX_DEPTH - 1) throw CategoryDepthException` |
| 循环（自移动） | `if ($newParentId === $id) throw CategoryCircularException` |
| 循环（后代） | `if (str_starts_with($newParent->path, $node->path)) throw CategoryCircularException` |
| 仅删除叶节点 | `if ($children !== []) throw CategoryHasChildrenException` |
| 移动深度溢出 | 移动前检查 `$newDepth + maxSubtreeRelativeDepth >= MAX_DEPTH` |

---

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `GET` | `/categories` | 列出根分类（`?parent_id=N` 获取子分类） |
| `POST` | `/categories` | 创建分类 |
| `GET` | `/categories/{id}` | 获取单个分类及其祖先链 |
| `GET` | `/categories/{id}/subtree` | 获取所有后代 |
| `PUT` | `/categories/{id}` | 重命名分类 |
| `PATCH` | `/categories/{id}/move` | 移动到新父节点（`parent_id: null` 表示根） |
| `DELETE` | `/categories/{id}` | 删除叶节点（有子节点则拒绝 → 409） |

---

## 响应格式

### 分类对象

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

### GET /categories/{id} 含祖先

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

## 领域层结构

```
src/Category/
├── Category.php                    # readonly 实体
├── CategoryRepository.php          # 树操作（创建 / 列出 / 子树 / 祖先 / 移动 / 删除）
├── RouteRegistrar.php              # 将 HTTP 处理程序连接到 Router
├── CategoryNotFoundException.php
├── CategoryDepthException.php
├── CategoryCircularException.php
└── CategoryHasChildrenException.php
```

---

## 与嵌套集 / 闭包表的权衡

| 方案 | 子树读取 | 插入 | 移动 |
|------|----------|------|------|
| **物化路径**（本指南） | 快速（`LIKE`） | O(1) | O(子树大小) |
| 闭包表 | 快速（连接） | O(祖先数) | O(子树 × 祖先数) |
| 嵌套集 | 快速（`BETWEEN`） | O(表大小) | O(表大小) |

物化路径是深度有限、移动不频繁的树的最佳平衡点。当祖先查询必须仅使用索引且移动频繁时，使用闭包表。

---

## 参见

- [添加数据库支持的端点](./add-database-endpoint.md)
- [添加分页](./add-pagination.md)
- [软删除](./soft-delete.md)
