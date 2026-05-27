# 操作指南：评论线程 API

本指南演示资源范围内的评论线程，包含分页、仅作者可删除以及管理员覆盖权限。

## 模式概述

- 评论属于某个资源（通过整数 ID 标识）。
- 任何已认证用户都可以在任意资源上发表评论。
- 评论可公开读取（列表不需要认证）。
- 作者可以删除自己的评论；管理员可以删除任意评论。
- 通过 `limit` 和 `offset` 查询参数实现分页。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comments_resource ON comments (resource_id, id ASC);
```

## 分页模式

```php
$stmt = $this->pdo->prepare(
    'SELECT * FROM comments WHERE resource_id = :rid ORDER BY id ASC LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':rid', $resourceId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

响应包含 `total`（该资源的所有评论数）、`limit` 和 `offset`，方便客户端构建分页控件：

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## 限制值裁剪

无效或超出范围的 limit/offset 值会被静默裁剪为安全默认值：

```php
private function clampInt(string $raw, int $default, int $min, int $max): int
{
    if (!ctype_digit($raw) && $raw !== '') {
        return $default;
    }
    $val = $raw !== '' ? (int) $raw : $default;
    return min(max($val, $min), $max);
}
```

使用 `ctype_digit()` 是为了避免查询字符串上的 ReDoS 攻击。

## IDOR：仅作者可删除

非管理员用户只能删除自己的评论。尝试删除其他用户的评论返回 404（而非 403）：

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## 资源隔离

所有查询都包含 `WHERE resource_id = :rid`，确保资源 1 的评论不会与资源 2 混淆。

## 校验规则

| 字段 | 规则 |
|------|------|
| `X-User-Id` | POST/DELETE 必填；`ctype_digit`，>0 |
| `body` | 非空，最多 2000 个字符 |
| `{resourceId}` 路径 | `ctype_digit`，最多 18 个字符，>0；否则返回 404 |
| `limit` 查询 | 整数 1–100；默认 20 |
| `offset` 查询 | 非负整数；默认 0 |

## 路由

```
POST   /resources/{resourceId}/comments  发表评论（需要 X-User-Id）
GET    /resources/{resourceId}/comments  列出评论（分页，公开）
DELETE /comments/{id}                   删除评论（作者或管理员）
```

## 参见

- FT211 源码：`../NENE2-FT/commentlog/`
- 相关：`docs/howto/note-taking.md`（FT202，笔记 CRUD）
- 相关：`docs/howto/leaderboard-ranking.md`（FT206，资源范围数据）
