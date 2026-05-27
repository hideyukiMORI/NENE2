# 操作指南：联系人管理 API

> **FT 参考**：FT238（`NENE2-FT/contactlog`）——联系人管理 API

演示一个联系人管理 API，具有所有者范围的 CRUD、多对多联系人分组系统、`LIKE` 全文搜索结合 `EXISTS` 分组过滤，以及由 `DatabaseConstraintException` 处理支持的幂等分组成员操作。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/owners/{ownerId}/contacts` | 创建联系人 |
| `GET` | `/owners/{ownerId}/contacts` | 搜索联系人（可选 `?q=`、`?group_id=`） |
| `GET` | `/owners/{ownerId}/contacts/{id}` | 获取单个联系人 |
| `PUT` | `/owners/{ownerId}/contacts/{id}` | 更新联系人（完全替换） |
| `DELETE` | `/owners/{ownerId}/contacts/{id}` | 删除联系人 |
| `POST` | `/owners/{ownerId}/groups` | 创建分组 |
| `PUT` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | 将联系人添加到分组 |
| `DELETE` | `/owners/{ownerId}/contacts/{contactId}/groups/{groupId}` | 从分组中移除联系人 |

`{ownerId}` 将所有操作范围限定到一个所有者——一个所有者创建的联系人和分组对其他人不可见。

---

## 数据库结构：contacts、groups、contact_groups

```sql
CREATE TABLE IF NOT EXISTS contacts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    email      TEXT    NOT NULL DEFAULT '',
    phone      TEXT    NOT NULL DEFAULT '',
    notes      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contacts_owner ON contacts (owner_id);

CREATE TABLE IF NOT EXISTS groups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(owner_id, name)
);

CREATE TABLE IF NOT EXISTS contact_groups (
    contact_id INTEGER NOT NULL,
    group_id   INTEGER NOT NULL,
    PRIMARY KEY (contact_id, group_id)
);
```

关键设计选择：
- `contact_groups` 使用复合 `PRIMARY KEY (contact_id, group_id)`——每个（联系人，分组）对最多只有一行。尝试插入重复记录会引发约束错误。
- `groups.UNIQUE(owner_id, name)` 防止同一所有者内出现重复的分组名称。
- `email`、`phone`、`notes` 默认为 `''`——可选字段无需处理 NULL。

---

## IDOR 防护：所有查询都包含 owner_id

所有读写操作的 `WHERE` 子句中都包含 `owner_id`：

```php
public function findById(int $id, string $ownerId): ?Contact
{
    $rows = $this->db->fetchAll(
        'SELECT * FROM contacts WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $rows !== [] ? $this->hydrateWithGroups($rows[0]) : null;
}
```

请求 `/owners/alice/contacts/5`，而联系人 5 属于 `bob` 时，返回 `null` → `404 Not Found`。调用者无法区分"不存在"和"不是你的"——这防止了 ID 存在性的确认。

---

## 搜索：动态 LIKE + EXISTS 过滤

列表端点根据可选查询参数构建动态 `WHERE` 子句：

```php
public function search(string $ownerId, ?string $query, ?string $groupId): array
{
    $conditions = ['c.owner_id = ?'];
    $bindings   = [$ownerId];

    if ($query !== null) {
        $conditions[] = '(c.name LIKE ? OR c.email LIKE ?)';
        $bindings[]   = "%{$query}%";
        $bindings[]   = "%{$query}%";
    }

    if ($groupId !== null) {
        $conditions[] = 'EXISTS (SELECT 1 FROM contact_groups cg WHERE cg.contact_id = c.id AND cg.group_id = ?)';
        $bindings[]   = (int) $groupId;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $rows  = $this->db->fetchAll(
        "SELECT c.* FROM contacts c {$where} ORDER BY c.name ASC",
        $bindings,
    );

    return array_map(fn (array $row) => $this->hydrateWithGroups($row), $rows);
}
```

使用的模式：
- **动态条件累积**：从必填条件（`owner_id`）开始，追加可选条件。`implode(' AND ', $conditions)` 安全地连接它们。
- **`LIKE ? OR LIKE ?`**：参数化 LIKE——无 SQL 注入风险。`%` 通配符在 PHP 字符串中，而非用户输入中。但如果 `$query` 本身包含 `%` 或 `_`，这些字符会被 SQLite 解释为 LIKE 通配符——如果需要字面匹配，请用 `str_replace(['%', '_'], ['\\%', '\\_'], $query)` 转义。
- **`EXISTS (SELECT 1 ...)`**：关联子查询过滤属于给定分组的联系人，无需 JOIN（避免一个联系人属于多个分组时出现重复行）。

---

## 创建分组：重复名称 → 409

`groups` 上的 `UNIQUE(owner_id, name)` 使同一所有者内的重复分组名称成为约束错误。仓库捕获该错误并返回 `null`：

```php
public function createGroup(string $ownerId, string $name): ?array
{
    try {
        $id = $this->db->insert(
            'INSERT INTO groups (owner_id, name, created_at) VALUES (?, ?, ?)',
            [$ownerId, $name, $now],
        );
    } catch (DatabaseConstraintException) {
        return null;  // 此所有者的分组名称已存在
    }
    // ...
}
```

控制器将 `null` 映射为 `409 Conflict`：

```php
$group = $this->repo->createGroup($ownerId, $name);

if ($group === null) {
    return $this->problems->create($request, 'conflict', 'Group Already Exists', 409,
        "Group {$name} already exists.");
}
```

`409` 是正确的状态码——请求有效，但与已有资源冲突。

---

## 分组成员管理：通过捕获约束的幂等添加

将联系人添加到分组是幂等的——重复调用不会报错：

```php
public function addToGroup(int $contactId, int $groupId, string $ownerId): bool
{
    // 验证联系人和分组都属于此所有者
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    $group   = $this->db->fetchOne('SELECT id FROM groups WHERE id = ? AND owner_id = ?', [$groupId, $ownerId]);

    if ($contact === null || $group === null) {
        return false;  // → 404 Not Found
    }

    try {
        $this->db->execute(
            'INSERT INTO contact_groups (contact_id, group_id) VALUES (?, ?)',
            [$contactId, $groupId],
        );
    } catch (DatabaseConstraintException) {
        // 主键冲突——联系人已在分组中。视为成功（幂等）。
    }

    return true;
}
```

复合 `PRIMARY KEY (contact_id, group_id)` 在数据库层面强制唯一性。捕获并忽略的模式使操作可以多次安全调用——从调用者角度看，已存在的成员关系不是错误。

`contact` 和 `group` 在插入成员关系前都会验证属于 `$ownerId`。跨所有者的成员关系（将 alice 的联系人添加到 bob 的分组）是被禁止的。

---

## 分组成员删除

删除操作验证联系人所有权，如果成员关系存在则删除：

```php
public function removeFromGroup(int $contactId, int $groupId, string $ownerId): bool
{
    $contact = $this->db->fetchOne('SELECT id FROM contacts WHERE id = ? AND owner_id = ?', [$contactId, $ownerId]);
    if ($contact === null) {
        return false;  // → 404
    }

    $count = $this->db->execute(
        'DELETE FROM contact_groups WHERE contact_id = ? AND group_id = ?',
        [$contactId, $groupId],
    );

    return $count > 0;  // 成员关系不存在时返回 false → 404
}
```

成员关系不存在时返回 `false` 导致 `404`，这是正确的：调用者尝试删除一个不存在的东西。

---

## 相关操作指南

- [`group-membership-management.md`](group-membership-management.md) ——基于角色的分组成员管理模式
- [`tagging-system.md`](tagging-system.md) ——多对多标签关系
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) ——IDOR 防护模式
- [`use-fts5-search.md`](use-fts5-search.md) ——大数据集的全文搜索
