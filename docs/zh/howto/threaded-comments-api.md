# 操作指南：嵌套评论 API

> **FT 参考**：FT343（`NENE2-FT/threadlog`）— 两级嵌套评论系统，带墓碑式删除（内容替换为 `[deleted]`）、回复深度限制、文章范围隔离以及禁止回复已删除评论，14 tests / 40+ assertions 全部 PASS。

本指南展示如何构建一级回复的评论系统：根评论可以接收回复，但回复不能再被回复（最大深度 = 1）。删除的评论使用墓碑处理，保留线程结构。

## 数据库结构

```sql
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT    NOT NULL,           -- 不透明的文章标识符
    parent_id  INTEGER REFERENCES comments(id),  -- NULL = 根评论
    author     TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL
);
```

`parent_id IS NULL` = 根评论；`parent_id IS NOT NULL` = 回复。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/posts/{postId}/comments` | 创建根评论 |
| `GET` | `/posts/{postId}/comments` | 列出评论及回复 |
| `GET` | `/posts/{postId}/comments/{id}` | 获取单条评论 |
| `POST` | `/posts/{postId}/comments/{id}/replies` | 添加回复 |
| `DELETE` | `/posts/{postId}/comments/{id}` | 软删除（墓碑） |

## 创建根评论

```php
POST /posts/post-1/comments
{"author": "alice", "content": "Great post!"}
→ 201
{
  "id": 1,
  "author": "alice",
  "content": "Great post!",
  "parent_id": null,
  "replies": [],
  "deleted": false,
  "created_at": "..."
}

// 缺少字段
POST /posts/post-1/comments  {"author": "alice"}
→ 422  // content 必填
```

## 列出评论

```php
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "alice",
      "content": "Root comment",
      "replies": [
        {"id": 2, "author": "bob", "content": "My reply", "parent_id": 1}
      ]
    }
  ]
}
```

评论限定在 `post_id` 范围内。`post-1` 的评论永远不会出现在 `post-2` 的列表中。

## 获取单条评论

```php
GET /posts/post-1/comments/1
→ 200
{
  "id": 1,
  "author": "alice",
  "content": "Root comment",
  "reply_count": 2,
  "replies": [...]
}

GET /posts/post-1/comments/999
→ 404
```

## 添加回复（最大深度 = 1）

```php
POST /posts/post-1/comments/1/replies
{"author": "bob", "content": "My reply"}
→ 201
{
  "id": 2,
  "parent_id": 1,
  "author": "bob",
  "content": "My reply"
}

// 回复的回复被拒绝（深度将达到 2）
POST /posts/post-1/comments/2/replies  {"author": "charlie", "content": "Deep reply"}
→ 409  // 超过深度限制

// 回复不存在的评论
POST /posts/post-1/comments/999/replies  {"author": "bob", "content": "X"}
→ 404

// 回复已删除的评论
// （评论 1 已被删除）
POST /posts/post-1/comments/1/replies  {"author": "bob", "content": "X"}
→ 409  // 不能回复已删除的评论

// 缺少字段 → 422
```

### 深度检查实现

```php
public function canReceiveReply(int $commentId): bool
{
    $row = $this->findById($commentId);
    if ($row === null) {
        throw new CommentNotFoundException($commentId);
    }
    if ($row['deleted']) {
        throw new CommentDeletedException($commentId);
    }
    // 只有根评论（parent_id = null）可以有回复
    return $row['parent_id'] === null;
}
```

当 `canReceiveReply()` 返回 false 时，返回 409。

## 墓碑删除

```php
DELETE /posts/post-1/comments/1
→ 200
{
  "deleted": true,
  "author": "[deleted]",
  "content": "[deleted]"
}

// 已删除评论仍出现在列表中（墓碑）
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "[deleted]",
      "content": "[deleted]",
      "deleted": true,
      "replies": [{"id": 2, "author": "bob", ...}]  // 回复仍可见
    }
  ]
}
```

墓碑处理保留了线程结构。即使父评论被删除，回复仍然可见。

```php
// 删除已删除的评论 → 404
DELETE /posts/post-1/comments/1  （已删除）
→ 404

// 未知评论 → 404
DELETE /posts/post-1/comments/999
→ 404
```

### 墓碑 SQL

```sql
UPDATE comments
SET deleted = 1, author = '[deleted]', content = '[deleted]', deleted_at = ?
WHERE id = ? AND deleted = 0
-- 只匹配未删除的行
-- 更新 0 行 → 404
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 硬删除父评论 | 回复成为孤儿；线程结构被破坏 |
| 允许无限嵌套深度 | 深层链会产生递归 SQL 查询或栈溢出 |
| 回复已删除评论时返回 404 | 隐藏父评论状态会混淆客户端；带清晰 `detail` 的 409 更好 |
| 查询中无 `post_id` 限定 | 其他文章的评论出现在列表中 |
| 仅在客户端检查深度 | 攻击者通过直接发送 API 请求绕过检查 |
| 显示已删除评论的作者/内容 | 违背删除的目的；始终使用墓碑处理 |
