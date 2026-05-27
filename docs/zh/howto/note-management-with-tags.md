# 操作指南：带标签的笔记管理

## 概述

本指南介绍如何使用 NENE2 构建带标签的笔记管理 API。功能包括：按用户隔离、基于标签过滤、全文关键词搜索，以及强制所有权的 CRUD 操作。

**参考实现**：`../NENE2-FT/notelog/`

---

## 数据库结构设计

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS note_tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    tag     TEXT    NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    UNIQUE (note_id, tag)
);
```

`ON DELETE CASCADE` 在删除笔记时自动移除其标签。

---

## 路由表

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `POST` | `/notes` | 用户 | 创建笔记 |
| `GET` | `/notes` | 用户 | 列出自己的笔记（可选 `?tag=` 或 `?q=`） |
| `GET` | `/notes/{id}` | 用户 | 获取单篇笔记 |
| `PUT` | `/notes/{id}` | 用户 | 更新笔记字段 |
| `DELETE` | `/notes/{id}` | 用户 | 删除笔记 |

---

## 标签过滤

通过 `JOIN` 按标签过滤：

```sql
SELECT n.* FROM notes n
JOIN note_tags t ON t.note_id = n.id
WHERE n.user_id = :uid AND t.tag = :tag
ORDER BY n.id DESC
```

---

## 关键词搜索

使用 `LIKE` 对标题和正文进行全文搜索：

```sql
SELECT * FROM notes
WHERE user_id = :uid AND (title LIKE :kw OR body LIKE :kw)
ORDER BY id DESC
```

`:kw` 占位符值为 `'%' . $keyword . '%'`。参数化查询防止 SQL 注入。

---

## 标签解析

标签必须是字符串数组；规范化为小写：

```php
private function parseTags(mixed $raw): ?array
{
    if (!is_array($raw)) return [];
    $tags = [];
    foreach ($raw as $tag) {
        if (!is_string($tag)) return null;   // 拒绝非字符串 → 422
        $t = trim($tag);
        if ($t !== '') $tags[] = strtolower($t);
    }
    return $tags;
}
```

---

## IDOR / 所有权模式

所有读写操作都限定到 `user_id`。读取时返回 404（非 403）以避免暴露资源是否存在；写入时返回 403 以便用户知道资源存在但没有权限：

```php
// 读取：404 防止信息泄露
if ((int) $note['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Note not found.');
}

// 写入：资源存在但无所有权时返回 403
if ((int) $note['user_id'] !== $userId) {
    return 'forbidden';
}
```

---

## 局部更新（PUT）

接受任意字段为 `null` 表示"不修改"：

```php
$title    = isset($body['title']) ? trim((string) $body['title']) : null;
$noteBody = isset($body['body']) ? (string) $body['body'] : null;
$tags     = (isset($body['tags'])) ? $this->parseTags($body['tags']) : null;
```

在数据仓库中，只更新非 null 的字段。

---

## HTTP 状态码

| 情况 | 状态码 |
|-----------|--------|
| 笔记已创建 | 201 |
| 笔记检索 / 列表 | 200 |
| 笔记更新 / 删除 | 200 |
| 无 X-User-Id | 400 |
| 标题为空 | 422 |
| 标签值非字符串 | 422 |
| 笔记未找到（或 IDOR） | 404 |
| 更新/删除他人笔记 | 403 |
