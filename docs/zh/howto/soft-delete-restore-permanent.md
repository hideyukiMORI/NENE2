# 操作指南：软删除、恢复和永久删除

> **FT 参考**：`NENE2-FT/softdelete` — 通过 `deleted_at` 时间戳实现软删除，恢复（只有软删除的笔记才能恢复），永久硬删除（只有软删除的笔记才能永久删除），14 tests 全部 PASS。

本指南展示如何实现三种删除状态：活跃、软删除（可恢复）和永久删除（不可恢复）。比较 `docs/howto/soft-delete-trash-restore.md`（FT340 softdeletelog），该文档添加了专用回收站视图和批量清除功能。

## 数据库结构

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    deleted_at TEXT             -- NULL = 活跃；时间戳 = 软删除
);

CREATE INDEX idx_notes_deleted ON notes(deleted_at);
```

`deleted_at IS NULL` → 活跃。`deleted_at IS NOT NULL` → 软删除。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/notes` | 创建笔记 |
| `GET` | `/notes` | 仅列出活跃笔记 |
| `GET` | `/notes/{id}` | 获取笔记（已删除返回 404） |
| `DELETE` | `/notes/{id}` | 软删除（设置 deleted_at） |
| `POST` | `/notes/{id}/restore` | 恢复软删除的笔记 |
| `DELETE` | `/notes/{id}/permanent` | 永久删除软删除的笔记 |

## 创建笔记

```php
POST /notes  {"title": "My Note", "body": "Some content"}

→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Some content",
  "deleted_at": null,    // ← null = 活跃
  "created_at": "..."
}
```

## 列出活跃笔记

```php
GET /notes
→ 200  {"items": [{...活跃笔记...}], "total": 2}
```

只返回 `deleted_at IS NULL` 的笔记。软删除的笔记在此不可见。

## 软删除

```php
DELETE /notes/1
→ 200  // 设置 deleted_at = now

// 软删除的笔记从活跃列表消失
GET /notes
→ 200  {"items": [], "total": 0}

// 直接 GET 也消失
GET /notes/1
→ 404
```

```sql
UPDATE notes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL
```

## 恢复

```php
// 恢复软删除的笔记
POST /notes/1/restore
→ 200  {"id": 1, "title": "My Note", "deleted_at": null, ...}  // 恢复为活跃

// 恢复后的笔记重新出现在活跃列表
GET /notes
→ 200  {"items": [{...}], "total": 1}
```

### 恢复活跃笔记 → 404

```php
// 尝试恢复活跃（未软删除）的笔记 → 404
POST /notes/2/restore   // 笔记 2 从未被删除
→ 404
```

只有软删除的笔记才能被恢复。活跃笔记在恢复时返回 404。

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL
-- 如果影响 0 行 → 笔记是活跃的或不存在 → 404
```

## 永久删除

```php
// 必须先软删除
DELETE /notes/1   // 软删除
POST /notes/1/restore  // 恢复（可选）

// 永久删除软删除的笔记
DELETE /notes/1          // 先软删除
DELETE /notes/1/permanent
→ 200  {"permanent": true}

GET /notes/1
→ 404  // 永久消失
```

### 永久删除活跃笔记 → 404

```php
// 永久删除活跃笔记 → 404
// 必须先软删除，再永久删除
DELETE /notes/2/permanent   // 笔记 2 是活跃的
→ 404
```

```sql
DELETE FROM notes WHERE id = ? AND deleted_at IS NOT NULL
-- 如果影响 0 行 → 笔记是活跃的或不存在 → 404
```

## 状态图

```
活跃
  │
  │ DELETE /notes/{id}     （软删除）
  ▼
软删除
  │           │
  │ POST      │ DELETE
  │ /restore  │ /permanent
  ▼           ▼
活跃      彻底删除（硬删除）
```

**关键不变量**：永久删除需要先进行软删除。这防止了从活跃状态意外硬删除。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 允许永久删除活跃笔记 | 跳过软删除安全网；数据消失无恢复窗口 |
| 恢复活跃笔记时返回 200 | 调用者无法判断恢复是否必要；用 404 表示"不在回收站" |
| `deleted_at` 无索引 | 每次列表查询全表扫描；无索引时 `WHERE deleted_at IS NULL` 很慢 |
| `DELETE /notes/{id}` 立即硬删除 | 无法恢复；先使用软删除 |
| 在活跃列表中暴露 `deleted_at` | 客户端看到该字段；响应视觉上杂乱；过滤掉或使用 `null` |
