# 操作指南：PATCH 局部更新（JSON Merge Patch）

> **FT 参考**：FT326（`NENE2-FT/patchlog`）——JSON Merge Patch（RFC 7396）局部更新：null 字段重置、不可变字段拒绝、ETag/If-Match、仅所有者修改，42 个测试 / 141 个断言全部通过。

本指南展示如何实现遵循 JSON Merge Patch 语义的 `PATCH` 端点：只有提供的字段被更新，`null` 重置为默认值，不可变字段被拒绝。

## 数据库结构

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST`  | `/documents` | 创建（需要 `X-User-Id`） |
| `GET`   | `/documents` | 列表 |
| `GET`   | `/documents/{id}` | 获取文档（带 ETag 请求头） |
| `PATCH` | `/documents/{id}` | 局部更新（需要 `X-User-Id`） |
| `DELETE`| `/documents/{id}` | 删除（仅所有者） |

## 创建

```php
POST /documents  X-User-Id: 1
{"title": "My Doc", "body": "Content"}
→ 201  {"id": 1, "owner_id": 1, "title": "My Doc", "status": "draft", "version": 1}

// 无 X-User-Id → 401
// 缺少 title → 422
// title 为空 → 422
// body 是可选的 → 默认为 ""
```

## GET 带 ETag

```php
GET /documents/1
→ 200  ETag: "doc-1-1"
{"id": 1, "title": "My Doc", "version": 1, ...}
```

ETag 格式：`"doc-{id}-{version}"`。

## PATCH——JSON Merge Patch 语义

```php
// 只更新 title——body 不变
PATCH /documents/1  X-User-Id: 1
{"title": "Updated"}
→ 200  {"title": "Updated", "body": "Content", ...}

// 只更新 body
PATCH /documents/1  X-User-Id: 1
{"body": "New content"}
→ 200  {"title": "Updated", "body": "New content", ...}

// 空 {}——无操作（RFC 7396 §3 允许）
PATCH /documents/1  X-User-Id: 1
{}
→ 200  （文档不变）

// null 将字段重置为默认值
PATCH /documents/1  X-User-Id: 1
{"status": null}
→ 200  {"status": "draft"}   // 重置为默认值
```

## 不可变字段——被拒绝

某些字段永远不能通过 PATCH 更改：

```php
PATCH /documents/1  {"id": 999}         → 422  // 不可变
PATCH /documents/1  {"owner_id": 99}    → 422  // 不可变
PATCH /documents/1  {"version": 999}    → 422  // 不可变
PATCH /documents/1  {"created_at": "…"} → 422  // 不可变
```

## 仅所有者授权

```php
// 用户 2 尝试修改用户 1 的文档 → 404（而非 403，以防止枚举）
PATCH /documents/1  X-User-Id: 2  {"title": "Stolen"}  → 404

// 所有者始终可以修改自己的文档
PATCH /documents/1  X-User-Id: 1  {"title": "Mine"}    → 200
```

## ETag / If-Match

```php
// 条件 PATCH——版本已更改时返回 412
PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Updated"}
→ 200  // 如果 version 仍为 1

PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Stale"}
→ 412  // 如果 version 现在为 2
```

## 类型校验

```php
PATCH /documents/1  {"title": 123}   → 422  // int 而非 string
PATCH /documents/1  {"body": [1,2]}  → 422  // array 而非 string
```

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 将缺失字段视为 `null` | 调用者无法清除字段；Merge Patch 中 `undefined` ≠ `null` |
| 允许通过 PATCH 修改 `owner_id` | 无授权流程的所有权转移 |
| 跨所有者访问时返回 403 | 暴露文档是否存在；应返回 404 |
| 在 PATCH 时替换整个文档 | 覆盖客户端不打算更改的字段 |
| 静默接受不可变字段（无操作） | 客户端误认为改变了 `id`；静默失败导致混乱 |
