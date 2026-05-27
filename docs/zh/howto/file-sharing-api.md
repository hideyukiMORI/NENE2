# 操作指南：文件共享 API

> **FT 参考**：FT303（`NENE2-FT/filelog`）——文件共享 API：私有文件对非所有者返回 404（而非 403），仅所有者可删除/更改可见性，view 共享与 edit 共享两级权限，请求体中的 `user_id` 被忽略（所有权来自请求头），名称长度限制 255，`is_int()` 严格校验 size，VULN-A 至 VULN-L 全部通过，59 个测试 / 82 个断言全部通过。

本指南展示如何构建文件元数据 API，支持用户拥有文件、控制可见性，并以 view 或 edit 级别与其他用户共享访问权限。

## 数据库结构

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type   TEXT    NOT NULL,
    description TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'private'
                        CHECK (visibility IN ('private', 'public')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id             INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit            INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at          TEXT    NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

两级共享：`can_edit = 0`（只读）和 `can_edit = 1`（编辑访问）。`UNIQUE(file_id, shared_with_user_id)` 防止重复共享记录。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/files` | `X-User-Id` | 上传文件元数据 |
| `GET` | `/files` | `X-User-Id` | 列出自己的文件 |
| `GET` | `/files/{fileId}` | `X-User-Id` | 获取文件（可见性检查） |
| `PUT` | `/files/{fileId}` | `X-User-Id` | 更新文件（所有者或 edit 共享对象） |
| `DELETE` | `/files/{fileId}` | `X-User-Id` | 删除文件（仅所有者） |
| `POST` | `/files/{fileId}/shares` | `X-User-Id`（所有者） | 添加共享 |
| `DELETE` | `/files/{fileId}/shares/{userId}` | `X-User-Id`（所有者） | 删除共享 |

## 私有文件返回 404（而非 403）

```php
// 非所有者无法查看私有文件——404 隐藏文件存在
if ($file['visibility'] === 'private') {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->problems->create($request, 'not-found', 'File not found', 404);
    }
}
```

私有文件对非所有者和非共享对象返回 404。返回 403 会暴露文件存在。公开文件对所有已认证用户返回 200。

## 所有权来自请求头——忽略请求体中的 user_id

```php
$userId = $this->requireUserId($request);
// ... 校验 ...
$id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
```

文件的 `user_id` 始终从 `X-User-Id` 请求头获取。请求体中的任何 `user_id` 都会被静默忽略。这可防止所有权注入攻击（VULN-E）。

## View 共享 vs Edit 共享——两级权限

```php
// 所有者始终可编辑
$isOwner = ((int) $file['user_id']) === $userId;

if (!$isOwner) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null || !(bool) $share['can_edit']) {
        return $this->problems->create($request, 'forbidden', 'Edit access required', 403);
    }
}
```

- **所有者**：所有操作（读、写、删除、共享管理、可见性）
- **Edit 共享**（`can_edit=1`）：可更新名称/大小/MIME/描述——但**不能**更改 visibility
- **View 共享**（`can_edit=0`）：仅读——任何写入尝试 → 403

只有所有者可以更改 `visibility`：

```php
// 只有所有者可以更改 visibility
if (!$isOwner && isset($body['visibility'])) {
    $visibility = (string) $file['visibility']; // 静默忽略请求
}
```

## 严格输入校验

```php
$size = $body['size'] ?? null;
if (!is_int($size) || $size < 0) {
    $errors[] = ['field' => 'size', 'code' => 'invalid', 'message' => 'size must be a non-negative integer'];
}

if (!is_string($name) || strlen($name) > 255 || $name === '') {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name required, max 255 chars'];
}
```

- `size`：`is_int()` 拒绝 `1.5` 这样的浮点数（VULN-I）
- `name`：最多 255 字符——防止超大输入崩溃（VULN-H）
- `visibility`：`in_array($value, ['private', 'public'], true)` 严格白名单

## 共享删除——仅所有者

```php
// 只有文件所有者可以删除共享
if ((int) $file['user_id'] !== $userId) {
    return $this->problems->create($request, 'not-found', 'File not found', 404);
}
```

共享对象不能将自己从共享列表中删除——只有所有者才能管理共享。非所有者返回 404（而非 403），以隐藏文件存在（VULN-F）。

## 用户 ID 校验——拒绝零值和负值

```php
$raw = $request->getHeaderLine('X-User-Id');
$userId = ctype_digit($raw) ? (int) $raw : 0;
if ($userId <= 0) {
    return $this->problems->create($request, 'unauthorized', 'Authentication required', 401);
}
```

`X-User-Id: 0` 和 `X-User-Id: -1` 返回 401（VULN-L）。只有正整数才是有效的用户 ID。

---

## 漏洞评估

### V-01 — IDOR：其他用户可访问私有文件 ✅ SAFE

**风险**：用户 B 读取用户 A 的私有文件。
**结论**：SAFE — 私有文件对没有共享记录的非所有者返回 404。

---

### V-02 — IDOR：删除其他用户的文件 ✅ SAFE

**风险**：用户 B 删除用户 A 的文件。
**结论**：SAFE — 删除检查所有权；非所有者返回 404。文件在失败尝试后仍然存在。

---

### V-03 — IDOR：更新其他用户的文件 ✅ SAFE

**风险**：用户 B 更新用户 A 的文件名/元数据。
**结论**：SAFE — 更新检查所有权；无 edit 共享的非所有者返回 404。

---

### V-04 — 权限提升：view 共享对象尝试编辑 ✅ SAFE

**风险**：只读共享对象调用 PUT 修改文件。
**结论**：SAFE — 编辑检查要求 `can_edit = 1`；view 共享返回 403。

---

### V-05 — 所有权注入：请求体中的 user_id ✅ SAFE

**风险**：`{ "user_id": 99, "name": "..." }` 将文件分配给用户 99。
**结论**：SAFE — 请求体中的 `user_id` 被静默忽略；所有权始终来自 `X-User-Id` 请求头。

---

### V-06 — 非所有者删除共享 ✅ SAFE

**风险**：共享对象将自己从共享列表中删除。
**结论**：SAFE — 共享删除端点检查文件所有权；非所有者返回 404。

---

### V-07 — name 字段 SQL 注入 ✅ SAFE

**风险**：`"name": "test'; DROP TABLE files; --"` 破坏数据。
**结论**：SAFE — 参数化查询将注入字符串作为字面数据存储。files 表完好无损。

---

### V-08 — 超长 name 导致崩溃 ✅ SAFE

**风险**：300 字符的 name 导致 DB 错误或内存耗尽。
**结论**：SAFE — `strlen($name) > 255` 校验在插入前返回 422。

---

### V-09 — 浮点 size 类型混淆 ✅ SAFE

**风险**：`"size": 1.5` 通过校验并破坏大小追踪。
**结论**：SAFE — `is_int($size)` 拒绝浮点数 → 422。

---

### V-10 — Edit 共享将 visibility 提升为 public ✅ SAFE

**风险**：Edit 共享用户设置 `"visibility": "public"` 以暴露私有文件。
**结论**：SAFE — visibility 更改仅限所有者；edit 共享在 PUT 请求体中的 visibility 字段被静默忽略。

---

### V-11 — 通过 403 暴露私有文件存在 ✅ SAFE

**风险**：403 响应向未授权用户揭示文件存在。
**结论**：SAFE — 非所有者收到 404，而非 403。文件存在信息不被泄露。

---

### V-12 — 通过 X-User-Id: 0 或负值绕过认证 ✅ SAFE

**风险**：`X-User-Id: 0` 或 `X-User-Id: -1` 绕过用户检查。
**结论**：SAFE — `ctype_digit()` + `$userId <= 0` 检查对零值和负值返回 401。

---

### VULN 汇总

| ID | 漏洞 | 结论 |
|----|------|------|
| V-01 | IDOR：私有文件访问 | ✅ SAFE |
| V-02 | IDOR：删除其他用户文件 | ✅ SAFE |
| V-03 | IDOR：更新其他用户文件 | ✅ SAFE |
| V-04 | View 共享权限提升 | ✅ SAFE |
| V-05 | 通过请求体注入所有权 | ✅ SAFE |
| V-06 | 非所有者删除共享 | ✅ SAFE |
| V-07 | name 字段 SQL 注入 | ✅ SAFE |
| V-08 | 超长 name 导致崩溃 | ✅ SAFE |
| V-09 | 浮点 size 类型混淆 | ✅ SAFE |
| V-10 | Edit 共享 visibility 提升 | ✅ SAFE |
| V-11 | 私有文件存在信息泄露 | ✅ SAFE |
| V-12 | 无效用户 ID 绕过认证 | ✅ SAFE |

**12 SAFE，0 EXPOSED**
私有文件 404 模式、仅限请求头的所有权机制、两级共享权限、严格类型校验以及仅所有者可更改 visibility，共同防御了所有 IDOR 和权限提升攻击向量。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 对非所有者的私有文件返回 403 | 向未授权用户暴露文件存在 |
| 从请求体接受 `user_id` 作为所有权 | 任何已认证用户都能声索对任何文件的所有权 |
| 允许 view 共享对象调用 PUT | 共享观看者可以修改文件元数据 |
| 允许 edit 共享对象更改 visibility | 共享编辑者可将私有文件暴露为公开 |
| 允许共享对象删除自己的共享 | 用户可以从所有者处撤销访问管理权 |
| 接受 `size: 1.5`（浮点数） | 类型混淆；非整数文件大小破坏大小追踪 |
| 无 `name` 长度限制 | 超长文件名可能导致 DB 列溢出或内存问题 |
| 接受 `X-User-Id: 0` 为有效值 | 用户 ID 0 可能匹配未初始化行或绕过所有权检查 |
| `ctype_digit()` 后不检查 `> 0` | `"0"` 通过 `ctype_digit` 但不是有效用户 ID |
