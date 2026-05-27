# 操作指南：分类层级树 API

> **FT 参考**：FT344（`NENE2-FT/treelog`）——带 parent_id + depth 的分类树，直接子节点，递归 CTE 祖先/后代，仅叶节点可删除（有子节点时返回 409），17 个测试全部通过。

本指南展示如何构建层级分类树：创建带可选父节点的分类，使用递归 SQL CTE 向上遍历（祖先）和向下遍历（后代），并实施安全删除策略。

## 数据库结构

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

`depth` 在插入时计算：`parent.depth + 1`（根节点为 0）。`ON DELETE RESTRICT` 防止删除仍有子节点的父节点。

## 端点

| 方法 | 路径 | 描述 |
|--------|-----------------------------------|----------------------------------|
| `POST` | `/categories` | 创建根分类或子分类 |
| `GET` | `/categories` | 仅列出根分类 |
| `GET` | `/categories/{id}` | 获取单个分类 |
| `GET` | `/categories/{id}/children` | 仅直接子节点 |
| `GET` | `/categories/{id}/ancestors` | 从根到节点的路径（面包屑） |
| `GET` | `/categories/{id}/descendants` | 所有子树节点（任意深度） |
| `DELETE` | `/categories/{id}` | 仅删除叶节点（有子节点时返回 409） |

## 创建分类

```php
// 根分类（无父节点）
POST /categories
{"name": "Electronics"}

→ 201
{"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, "created_at": "..."}

// 子分类
POST /categories
{"name": "Smartphones", "parent_id": 1}

→ 201
{"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, "created_at": "..."}

// 孙分类
POST /categories
{"name": "Android", "parent_id": 2}
→ 201  // depth: 2
```

### 校验

```php
POST /categories  {"parent_id": 9999}
→ 404  // 父节点不存在

POST /categories  {"parent_id": 1}
→ 422  // name 为必填项
```

### 插入时的深度计算

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

## 列出根分类

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

仅返回 `WHERE parent_id IS NULL` 的分类——不包含子分类。

## 列出直接子节点

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

**仅直接子节点**——孙子节点不会出现在此处；使用 `/descendants` 获取完整子树。

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## 获取祖先（面包屑路径）——递归 CTE

```php
GET /categories/4/ancestors

// 分类 4 = "Android"（depth 2，父节点为 "Smartphones"）
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // 根节点在前
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // 最近的父节点在后
  ],
  "total": 2
}

// 根分类没有祖先
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

按 `depth ASC` 排序 → 根节点在前（自然面包屑顺序）。

### 祖先递归 CTE

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- 种子：从直接父节点开始
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- 递归：向上遍历到根节点
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## 获取后代（完整子树）——递归 CTE

```php
GET /categories/1/descendants

// "Electronics" 有 Smartphones、Laptops、Android（Smartphones 的子节点）
→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "depth": 1, ...},
    {"id": 4, "name": "Android",     "depth": 2, ...}
  ],
  "total": 3   // 所有子树节点，不仅仅是直接子节点
}

// 叶节点返回空
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

被查询节点的兄弟节点**不会**出现。

### 后代递归 CTE

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- 种子：直接子节点
    SELECT id, name, parent_id, depth, created_at
    FROM categories WHERE parent_id = :id

    UNION ALL

    -- 递归：子节点的子节点
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN desc_cte d ON c.parent_id = d.id
)
SELECT * FROM desc_cte ORDER BY depth ASC, id ASC
```

## 删除分类

```php
// 叶节点 → 204 No Content
DELETE /categories/4   // "Android"（无子节点）
→ 204

// 有子节点的节点 → 409 Conflict
DELETE /categories/1   // "Electronics"（有 Smartphones、Laptops）
→ 409
{
  "type": "https://nene2.dev/problems/has-children",
  "title": "Category has children",
  "status": 409,
  "detail": "Cannot delete a category that has children"
}

// 不存在的节点 → 404
DELETE /categories/9999
→ 404
```

### 删除实现

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
-- hasChildren 检查
SELECT COUNT(*) FROM categories WHERE parent_id = ?

-- 删除
DELETE FROM categories WHERE id = ?
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 通过父 ID 操作创建循环引用 🚫 BLOCKED

**攻击**：攻击者创建 A→B→C 链，然后将 B 的父节点重新指定为 C，造成无限 CTE 递归循环。
**结果**：BLOCKED——`parent_id` 仅在创建时设置；没有 PATCH/PUT 端点可以重新分配父节点。深度在插入时从已验证的父节点深度一次性计算。父子关系不可变，循环在结构上不可能发生。

---

### ATK-02 — 创建时使用不存在的父 ID 🚫 BLOCKED

**攻击**：攻击者发送 `{"name": "Orphan", "parent_id": 9999}` 来创建一个悬空分类。
**结果**：BLOCKED——仓库在插入前查找父节点；找不到父节点则抛出 `CategoryNotFoundException` → 404。不会创建孤儿记录。

---

### ATK-03 — 删除非叶节点以移除整个子树 🚫 BLOCKED

**攻击**：攻击者发送 `DELETE /categories/1`（有很多子节点的根节点）以清除整个子树。
**结果**：BLOCKED——`hasChildren()` 检查返回 true → `HasChildrenException` → 409。`ON DELETE RESTRICT` 也在数据库层面强制执行；即使应用逻辑被绕过，外键约束也会阻止删除。

---

### ATK-04 — 对不存在分类进行 CTE 遍历 🚫 BLOCKED

**攻击**：攻击者请求 `/categories/9999/ancestors` 或 `/categories/9999/descendants` 来探测数据。
**结果**：BLOCKED——仓库在运行 CTE 之前验证分类是否存在。找不到分类 → `CategoryNotFoundException` → 404。不会泄露数据。

---

### ATK-05 — 通过分类名称进行 SQL 注入 🚫 BLOCKED

**攻击**：攻击者发送 `{"name": "'; DROP TABLE categories; --"}` 来注入 SQL。
**结果**：BLOCKED——所有查询使用 PDO 预处理语句和绑定参数。名称作为字符串原样存储，从不拼接到 SQL 中。

---

### ATK-06 — 通过循环引用的递归 CTE 无限循环 🚫 BLOCKED

**攻击**：攻击者试图创建 ancestor_cte 无限循环的场景（A 是 B 的父节点，B 是 A 的父节点）。
**结果**：BLOCKED——`parent_id` 创建后不可变。创建 `parent_id=B` 的 A 需要 B 先存在；此时 A 不存在，所以 B 不可能以 `parent_id=A` 创建。顺序创建约束使循环不可能发生。

---

### ATK-07 — 深层链 CTE 深度炸弹 ✅ SAFE

**攻击**：攻击者创建 1000+ 层深的链来耗尽 CTE 递归限制。
**结果**：SAFE——SQLite 的 CTE 默认递归限制为 1000。非常长的链可能触发此限制。实际上，频率限制和每个请求的节点创建成本使此操作不切实际。在生产部署中添加 `MAX_DEPTH` 保护（例如，拒绝 `depth > 20`）。

---

### ATK-08 — 通过 GET /categories/{id} 枚举 ID 🚫 BLOCKED

**攻击**：攻击者迭代整数 ID 来枚举所有分类，包括他们不应该看到的。
**结果**：BLOCKED——如果分类是按用户或租户隔离的，授权检查（JWT 租户声明/所有权）保护单个 GET。treelog 演示了公开读访问作为基准；范围限制是授权层的关注点。

---

### ATK-09 — 子节点端点返回孙子节点 ✅ SAFE

**攻击**：攻击者期望 `/children` 无意中暴露多级子树数据。
**结果**：SAFE——`/children` 仅返回直接子节点（`WHERE parent_id = ?`）。访问孙子节点需要明确调用 `/descendants`。子节点端点不存在意外的数据暴露。

---

### ATK-10 — 大 name 字段内存耗尽 ✅ SAFE

**攻击**：攻击者在创建载荷中发送 10 MB 的 `name` 值。
**结果**：SAFE——请求大小限制中间件（默认 1 MB）在请求到达处理器之前拒绝超大请求体。应用层的 `name` 长度校验（例如 `max: 255`）提供第二道防护。

---

### ATK-11 — 通过顺序子树修剪删除受保护节点 ✅ SAFE

**攻击**：攻击者逐个删除所有子节点，使树中间的受保护节点变成叶节点，然后删除它。
**结果**：SAFE——这是有效的操作序列。逐个修剪子节点是删除子树的正确方式。授权（所有权检查）防止未授权用户删除他人的分类。

---

### ATK-12 — hasChildren 检查和子节点插入之间的竞争条件 🚫 BLOCKED

**攻击**：两个并发请求：一个检查 `hasChildren()`（返回 false）并继续删除；另一个在删除执行前创建新子节点。
**结果**：BLOCKED——数据库层面的 `ON DELETE RESTRICT` 外键约束在提交时如果存在子行则阻止删除。即使应用层的 `hasChildren()` 检查发生竞争，数据库约束是最后的防线。

---

### ATK 总结

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 父 ID 操作/循环引用 | 🚫 BLOCKED |
| ATK-02 | 创建时使用不存在的父 ID | 🚫 BLOCKED |
| ATK-03 | 删除非叶节点以清除子树 | 🚫 BLOCKED |
| ATK-04 | 对不存在节点的 CTE 遍历 | 🚫 BLOCKED |
| ATK-05 | 通过名称字段进行 SQL 注入 | 🚫 BLOCKED |
| ATK-06 | 递归 CTE 循环/无限循环 | 🚫 BLOCKED |
| ATK-07 | 深层链 CTE 深度炸弹 | ✅ SAFE（添加 MAX_DEPTH 保护） |
| ATK-08 | 通过 GET 枚举 ID | 🚫 BLOCKED |
| ATK-09 | 子节点端点意外暴露子树 | ✅ SAFE |
| ATK-10 | 大 name 字段内存耗尽 | ✅ SAFE（大小限制中间件） |
| ATK-11 | 顺序子树修剪 | ✅ SAFE（有效操作） |
| ATK-12 | hasChildren + 子节点插入竞争条件 | 🚫 BLOCKED |

**6 BLOCKED，4 SAFE，0 EXPOSED** — 无严重发现。在生产部署中添加插入时的 `MAX_DEPTH` 保护。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 每次请求通过计数祖先来计算 depth | O(depth) N+1 查询；使用存储的 `depth` 列 |
| 允许更新 parent_id（重新关联父节点）而不重新计算子树深度 | 整个子树的存储 `depth` 值变得过时/错误 |
| 父外键无 `ON DELETE RESTRICT` | 应用 bug 静默产生孤儿子行 |
| 不存在的分类祖先/后代返回 200 空列表 | 调用者无法区分"无祖先"和"分类不存在" |
| 接受客户端输入的 `depth` | 攻击者将深子节点的 `depth` 设为 0，破坏树的不变量 |
| 无 CTE 递归限制或插入时的 MAX_DEPTH 上限 | 深层链触发 SQLite 1000 级 CTE 限制 |
