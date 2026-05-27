# 文件元数据管理与共享 API 实现指南

## 概述

本指南说明如何使用 NENE2 实现文件元数据管理 API。
不进行实际的文件存储，而是管理元数据（名称、大小、MIME 类型、描述、可见性），
并支持用户间的共享（view/edit 权限）。

---

## 数据库结构

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type TEXT NOT NULL,
    description TEXT,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at TEXT NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

**设计要点**

- `visibility CHECK (visibility IN ('private', 'public'))` — DB 层面约束有效值
- `can_edit CHECK (can_edit IN (0, 1))` — SQLite 的布尔值为 INTEGER 0/1
- `UNIQUE (file_id, shared_with_user_id)` — 防止对同一用户重复共享

---

## 端点设计

| 方法 | 路径 | 描述 |
|------|------|------|
| `GET` | `/files` | 可访问文件列表（自己的 + 共享给自己的） |
| `POST` | `/files` | 创建文件元数据 |
| `GET` | `/files/{fileId}` | 获取文件（仅所有者、公开文件、共享对象可访问） |
| `PUT` | `/files/{fileId}` | 更新（所有者或 edit 共享对象） |
| `DELETE` | `/files/{fileId}` | 删除（仅所有者） |
| `POST` | `/files/{fileId}/shares` | 与用户共享 |
| `DELETE` | `/files/{fileId}/shares/{userId}` | 取消共享（仅所有者） |

---

## 访问控制设计

### 三级访问级别

```
所有者 (user_id = X-User-Id)
  → 所有操作可用
  
edit 共享 (file_shares.can_edit = 1)
  → GET / PUT 可用
  → 不可更改 visibility（仅所有者）
  → 不可 DELETE
  
view 共享 (file_shares.can_edit = 0) 或 public 文件
  → 仅 GET 可用
```

### 存在信息隐藏（IDOR 防护）

对其他用户的私有文件返回 **404**（而非 403）。
403 意味着"文件存在但无访问权限"，会助长 ID 猜测攻击。

```php
if ((int) $file['user_id'] !== $userId) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->json->create(['error' => 'File not found'], 404); // 返回 404 而非 403
    }
}
```

---

## 可访问文件列表查询

```php
return $this->db->fetchAll(
    'SELECT f.id, f.user_id, f.name, f.size, f.mime_type, f.description,
            f.visibility, f.created_at, f.updated_at,
            u.name AS owner_name,
            CASE WHEN f.user_id = ? THEN 1 ELSE fs.can_edit END AS can_edit,
            CASE WHEN f.user_id = ? THEN 1 ELSE 0 END AS is_owner
     FROM files f
     JOIN users u ON u.id = f.user_id
     LEFT JOIN file_shares fs ON fs.file_id = f.id AND fs.shared_with_user_id = ?
     WHERE f.user_id = ? OR fs.shared_with_user_id = ?
     ORDER BY f.created_at DESC, f.id DESC',
    [$userId, $userId, $userId, $userId, $userId]
);
```

- `LEFT JOIN` 连接共享表，`WHERE` 获取"自己的 OR 共享给我的"
- 公开文件不包含在列表中（可通过 GET 单独查看）
- `CASE WHEN` 计算所有者标志和编辑权限

---

## 防止 visibility 权限提升

即使是 edit 权限的共享对象也不能更改 `visibility`，只有所有者才能更改。

```php
// 只有所有者才能更改 visibility
if ($ownerId !== $userId) {
    $visibility = (string) $file['visibility']; // 覆盖为当前值
}

$this->repo->update($fileId, $name, $size, $mimeType, $description, $visibility, $now);
```

---

## 删除文件时清理共享记录

```php
public function delete(int $id): void
{
    $this->db->execute('DELETE FROM file_shares WHERE file_id = ?', [$id]);
    $this->db->execute('DELETE FROM files WHERE id = ?', [$id]);
}
```

由于存在 FK 约束，必须先删除 `file_shares`，再删除 `files`。

---

## 校验设计

```php
// name：必填，255 字符以内
if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
    $errors[] = new ValidationError('name', 'name is required', 'required');
} elseif (mb_strlen($body['name']) > 255) {
    $errors[] = new ValidationError('name', 'name is too long', 'too_long');
}

// size：必须为整数类型，不小于 0
if (!isset($body['size']) || !is_int($body['size'])) {
    $errors[] = new ValidationError('size', 'size must be an integer', 'invalid_type');
}

// visibility：枚举值检查
if (!in_array($body['visibility'], ['private', 'public'], true)) {
    $errors[] = new ValidationError('visibility', 'visibility must be private or public', 'invalid_value');
}
```

---

## 漏洞诊断结果（FT156）

| ID | 漏洞 | 结果 |
|----|------|------|
| VULN-A | IDOR：直接访问其他用户的私有文件 | 通过（返回 404） |
| VULN-B | IDOR：删除其他用户的文件 | 通过（返回 404） |
| VULN-C | IDOR：更新其他用户的文件 | 通过（返回 404） |
| VULN-D | 权限提升：view 共享对象执行 edit 操作 | 通过（返回 403） |
| VULN-E | 所有权注入：请求体中的 user_id | 通过（被忽略） |
| VULN-F | 共享删除伪造：共享对象删除自己的共享 | 通过（返回 404） |
| VULN-G | SQL 注入：文件名字段 | 通过（参数化查询） |
| VULN-H | 过长 name：300 字符 | 通过（返回 422） |
| VULN-I | 类型混淆：size 传入浮点数 | 通过（返回 422） |
| VULN-J | 可见性提升：编辑共享对象更改 visibility | 通过（被忽略） |
| VULN-K | 存在探测：403 vs 404 | 通过（返回 404） |
| VULN-L | 认证绕过：X-User-Id=0 / 负值 | 通过（返回 401） |

---

## 攻击测试结果（FT156）

| ID | 攻击场景 | 结果 |
|----|----------|------|
| ATK-01 | 伪造：GET 其他用户的文件 | 通过（返回 404） |
| ATK-02 | 伪造：DELETE 其他用户的文件 | 通过（返回 404） |
| ATK-03 | view 共享对象尝试 PUT 编辑 | 通过（返回 403） |
| ATK-04 | 在请求体中注入 user_id 伪造所有权 | 通过（被忽略） |
| ATK-05 | 路径遍历：`../../etc/passwd` | 通过（返回 404） |
| ATK-06 | 以字符串 ID 尝试访问 | 通过（返回 404） |
| ATK-07 | 发送空字符串 X-User-Id 头 | 通过（返回 401） |
| ATK-08 | SQL 注入：mime_type 字段 | 通过（参数化查询） |
| ATK-09 | 超长 description（10000 字符） | 通过（存储但不截断；name 超 255 返回 422） |
| ATK-10 | edit 共享对象将 visibility 提升为 public | 通过（被忽略） |
| ATK-11 | 共享对象尝试删除对自己的共享 | 通过（返回 404） |
| ATK-12 | 存在探测：猜测他人文件 ID | 通过（返回 404） |

---

## 测试要点

```php
// 其他用户的私有文件返回 404（而非 403）
$res = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '2']);
$this->assertSame(404, $res->getStatusCode());

// 编辑共享对象不能更改 visibility
$this->req('PUT', "/files/{$fileId}", ['X-User-Id' => '2'], [
    'name' => 'a.txt', 'size' => 1, 'mime_type' => 'text/plain', 'visibility' => 'public',
]);
$check = $this->req('GET', "/files/{$fileId}", ['X-User-Id' => '1']);
$this->assertSame('private', $this->json($check)['visibility']);

// 请求体中的 user_id 被忽略（从 X-User-Id 获取）
$res = $this->req('POST', '/files', ['X-User-Id' => '1'], ['name' => 'test.txt', 'size' => 1, 'mime_type' => 'text/plain', 'user_id' => 2]);
$this->assertSame(1, $this->json($res)['user_id']);
```
