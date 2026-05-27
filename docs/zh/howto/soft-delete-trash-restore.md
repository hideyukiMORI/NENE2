# 操作指南：软删除、回收站与恢复 API

> **FT 参考**：FT340（`NENE2-FT/softlog`）— 带软删除（deleted_at）、回收站视图、恢复、永久硬删除、批量清除、置顶优先排序和 ATK 攻击者思维攻击评估的笔记 API，26 tests / 60+ assertions 全部 PASS。

本指南展示如何实现两阶段删除生命周期：条目先被软删除（移入回收站）可以恢复，再通过显式硬删除或批量清除永久删除。

## 数据库结构

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_pinned  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,               -- NULL = 活跃；软删除时为 ISO 8601
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`deleted_at IS NULL` = 活跃；`deleted_at IS NOT NULL` = 软删除（在回收站）。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/notes` | 创建笔记 |
| `GET` | `/notes` | 列出活跃笔记（置顶优先） |
| `GET` | `/notes/{id}` | 获取活跃笔记 |
| `PUT` | `/notes/{id}` | 更新活跃笔记 |
| `DELETE` | `/notes/{id}` | 软删除（→ 回收站） |
| `GET` | `/notes/trash` | 列出回收站中的笔记 |
| `POST` | `/notes/{id}/restore` | 从回收站恢复 |
| `DELETE` | `/notes/{id}/permanent` | 硬删除（永久） |
| `POST` | `/notes/trash/purge` | 清除所有回收站 |

## 创建笔记

```php
POST /notes
{"title": "My Note", "body": "Content", "is_pinned": false}
→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Content",
  "is_pinned": false,
  "deleted_at": null,
  "created_at": "..."
}

POST /notes  {"body": "No title"}  → 422  // title 必填
```

## 列出活跃笔记（置顶优先）

```php
GET /notes
→ 200
{
  "total": 3,
  "items": [
    {"id": 2, "title": "Pinned", "is_pinned": true, ...},
    {"id": 1, "title": "Normal A", ...},
    {"id": 3, "title": "Normal B", ...}
  ]
}
```

```sql
SELECT * FROM notes WHERE deleted_at IS NULL
ORDER BY is_pinned DESC, created_at DESC
```

活跃列表中永远不返回软删除的笔记。

## 获取笔记

```php
GET /notes/1
→ 200  {"id": 1, "title": "My Note", ...}

// 软删除或未知 → 相同的 404
GET /notes/9999    → 404
GET /notes/1 (DELETE /notes/1 之后)  → 404
```

## 更新笔记

```php
PUT /notes/1
{"title": "Updated", "body": "New body", "is_pinned": true}
→ 200  {"title": "Updated", "is_pinned": true, ...}

// 软删除的笔记不可更新
PUT /notes/1  (DELETE /notes/1 之后)  → 404
```

## 软删除

```php
DELETE /notes/1
→ 204  （无响应体）

// 笔记从 GET /notes 和 GET /notes/1 消失
// 但出现在 GET /notes/trash 中

DELETE /notes/9999  → 404  // 未找到
```

## 回收站视图

```php
GET /notes/trash
→ 200
{
  "total": 1,
  "items": [
    {"id": 1, "title": "Gone", "deleted_at": "2026-05-27T10:00:00Z", ...}
  ]
}

// 活跃笔记不在回收站中
```

所有回收站条目的 `deleted_at` 为非空值。

## 恢复

```php
POST /notes/1/restore
→ 200  {"id": 1, "title": "Restore Me", "deleted_at": null, ...}

// 恢复的笔记重新出现在 GET /notes 中
// POST /notes/9999/restore  → 404
```

## 硬删除（永久）

```php
DELETE /notes/1/permanent
→ 204  （无响应体；笔记从 DB 中消失）

// 回收站中也消失
// DELETE /notes/9999/permanent  → 404
```

## 清除回收站

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// 空回收站
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` 执行 `DELETE FROM notes WHERE deleted_at IS NOT NULL` 并返回行数。

---

## ATK 评估——攻击者思维攻击测试

### ATK-01 — 未先软删除就硬删除 🚫 BLOCKED

**攻击**：攻击者对活跃（尚未软删除）的笔记调用 `DELETE /notes/1/permanent`。
**结果**：BLOCKED — `DELETE /notes/{id}/permanent` 在继续之前检查 `deleted_at IS NOT NULL`。活跃笔记对永久删除端点返回 404；只有回收站中的条目才能被硬删除。

---

### ATK-02 — 通过直接 GET 访问软删除的笔记 ✅ SAFE

**攻击**：攻击者知道笔记 ID 5 已被软删除，调用 `GET /notes/5` 希望读取受保护内容。
**结果**：SAFE — `GET /notes/{id}` 查询 `WHERE id = ? AND deleted_at IS NULL`。软删除的笔记返回 404，与未知笔记完全相同——不提供存在性提示。

---

### ATK-03 — 无认证清除回收站（大规模破坏） ⚠️ EXPOSED

**攻击**：任意客户端调用 `POST /notes/trash/purge` 永久销毁所有用户的所有回收站笔记。
**结果**：EXPOSED — `POST /notes/trash/purge` 上没有认证检查。没有按用户作用域，未认证客户端可以不可逆地删除所有用户的所有回收站数据。缓解方案：要求认证；将清除作用域限定为已认证用户自己的回收站；全局清除要求管理员角色。

---

### ATK-04 — 双重软删除破坏 deleted_at ✅ SAFE

**攻击**：攻击者发送两次 `DELETE /notes/1`，希望第二次调用将 `deleted_at` 重置为更晚的时间戳。
**结果**：SAFE — 第一次删除设置 `deleted_at`。第二次删除发现 `deleted_at IS NULL = false`，所以查找返回 0 行 → 404。时间戳不会被修改。

---

### ATK-05 — 恢复活跃笔记（状态损坏） 🚫 BLOCKED

**攻击**：攻击者对活跃（未删除）笔记调用 `POST /notes/1/restore`，强制无条件设置 `deleted_at = null`。
**结果**：BLOCKED — `restore` 查询 `WHERE id = ? AND deleted_at IS NOT NULL`。活跃笔记不匹配 → 404。幂等性：恢复已活跃的笔记是无操作的 404。

---

### ATK-06 — 创建时通过标题的 SQL 注入 ✅ SAFE

**攻击**：攻击者提交 `{"title": "'; DROP TABLE notes; --"}` 破坏数据库。
**结果**：SAFE — 所有写操作使用参数化语句。标题以字面字符串存储。

---

### ATK-07 — 笔记 ID 溢出跳过校验 🚫 BLOCKED

**攻击**：攻击者发送 `GET /notes/99999999999999999999`（20 位数字）使 PHP 整数溢出并到达意外的 ID。
**结果**：BLOCKED — 笔记 ID 在转换前用 `ctype_digit` + `strlen <= 18` 校验。溢出值 → 422。

---

### ATK-08 — 更新已删除的笔记（向幽灵写入） 🚫 BLOCKED

**攻击**：攻击者持有已删除笔记的过期 session 引用，提交 PUT 修改它。
**结果**：BLOCKED — `PUT /notes/{id}` 查询 `WHERE id = ? AND deleted_at IS NULL`。软删除的笔记此检查失败 → 404。更新被拒绝。

---

### ATK-09 — 竞争：恢复后立即清除 🚫 BLOCKED

**攻击**：攻击者竞争 `POST /notes/1/restore` 和 `POST /notes/trash/purge`，在恢复过程中销毁笔记。
**结果**：BLOCKED — 每个操作都是单个原子 DB 事务。清除执行 `DELETE WHERE deleted_at IS NOT NULL`；恢复设置 `deleted_at = NULL`。一个获胜，笔记处于一致状态。

---

### ATK-10 — 并发软删除留下孤儿 ✅ SAFE

**攻击**：两个请求同时调用 `DELETE /notes/1`。两者都检查 `deleted_at IS NULL`，都看到 null，都尝试设置 `deleted_at`。
**结果**：SAFE — 第一次更新成功。第二次发现 `deleted_at IS NOT NULL`（或 0 行被更新）→ 404。SQLite 串行化写入；第二次调用在 DB 层是幂等的。

---

### ATK-11 — 标题过长（存储滥用） ⚠️ EXPOSED

**攻击**：攻击者提交 10 MB 的标题字符串耗尽数据库存储。
**结果**：EXPOSED — `title` 和 `body` 上没有强制最大长度。缓解方案：添加 `MAX_TITLE_LENGTH`（例如 500 字符）和 `MAX_BODY_LENGTH`（例如 10 万字符），超出时返回 422。请求大小中间件提供二级守护。

---

### ATK-12 — 置顶溢出（大量置顶笔记） ⚠️ EXPOSED

**攻击**：攻击者创建数千个置顶笔记，将所有真实笔记推离活跃列表顶部。
**结果**：EXPOSED — 置顶笔记数量没有限制。任何笔记都可以用 `is_pinned: true` 创建。缓解方案：限制每用户的最大置顶笔记数（例如 10）；超出时返回 422。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 未软删除就硬删除 | 🚫 BLOCKED |
| ATK-02 | 通过 GET 访问软删除的笔记 | ✅ SAFE |
| ATK-03 | 无认证清除回收站 | ⚠️ EXPOSED |
| ATK-04 | 双重软删除 | ✅ SAFE |
| ATK-05 | 恢复活跃笔记 | 🚫 BLOCKED |
| ATK-06 | 通过标题的 SQL 注入 | ✅ SAFE |
| ATK-07 | 笔记 ID 溢出 | 🚫 BLOCKED |
| ATK-08 | 更新软删除的笔记 | 🚫 BLOCKED |
| ATK-09 | 竞争：恢复 + 清除 | 🚫 BLOCKED |
| ATK-10 | 并发软删除 | ✅ SAFE |
| ATK-11 | 标题过长 | ⚠️ EXPOSED |
| ATK-12 | 置顶溢出 | ⚠️ EXPOSED |

**7 BLOCKED，2 SAFE，3 EXPOSED** — 关键：对清除要求认证并作用域限定到操作者自己的数据；添加标题/正文长度上限；限制每用户置顶笔记数量。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 第一次 DELETE 时硬删除 | 没有恢复路径；意外删除是永久的 |
| 列表/获取查询中缺少 `deleted_at IS NULL` 过滤 | 软删除的条目重新出现，好像仍然活跃 |
| 允许对软删除笔记执行 `PUT` | 幽灵写入——用户在编辑他们以为已删除的数据 |
| `POST /trash/purge` 无认证 | 任何客户端不可逆地销毁所有回收站数据 |
| 软删除笔记 GET 返回 403 | 揭示笔记存在；404 防止存在性枚举 |
| 软删除后不检查行数 | 笔记未找到时静默返回 200；始终检查受影响行数 |
