# 操作指南：通知队列 API

本指南演示一个通知队列，管理员向用户发送定向通知，用户可以列出、阅读和删除它们。

## 模式概述

- 管理员通过 `POST /notifications`（仅管理员）向指定用户发送通知。
- 用户通过 `GET`、`POST /read`、`DELETE` 接收和管理自己的通知。
- 每次列表响应都返回 `unread_count`。
- `?unread=1` 过滤为仅未读通知。
- 标记为已读是幂等的（已读通知返回 200，而非错误）。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'info',
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);
```

## 类型允许列表

```php
private const array ALLOWED_TYPES = ['info', 'warning', 'error', 'success'];
```

未知类型返回 422。绝不在没有校验的情况下使用自由文本字段作为类型。

## 幂等的标记为已读

```php
public function markRead(int $id, int $userId): bool
{
    $notif = $this->findById($id);
    if ($notif === null || (int) $notif['user_id'] !== $userId) {
        return false;
    }
    if ((int) $notif['is_read'] === 1) {
        return true;  // 已读——幂等，返回成功
    }
    $this->pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id'
    )->execute([':now' => $this->now(), ':id' => $id]);
    return true;
}
```

## 未读过滤器

```php
if ($unreadOnly === true) {
    $stmt = $this->pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY id DESC'
    );
}
```

查询参数 `?unread=1` 激活此路径；任何其他值列出所有通知。

## IDOR：用户范围限制

所有读取/删除/列出操作都检查 `user_id`：

```php
if (!$isAdmin && (int) $notif['user_id'] !== $userId) {
    return false;  // → 404
}
```

非管理员用户无法读取、标记或删除其他用户的通知。

## 仅管理员发送

```php
private function send(ServerRequestInterface $req): ResponseInterface
{
    if (!$this->isAdmin($req)) {
        return $this->problem(403, 'forbidden', 'Admin access required.');
    }
    ...
}
```

目标 `user_id` 在请求体中指定，校验为 `is_int() && >= 1`。

## 校验摘要

| 字段 | 规则 |
|---|---|
| 请求体 `user_id` | 整数 >= 1（非字符串/浮点数） |
| 请求体 `type` | 以下之一：info、warning、error、success |
| 请求体 `title` | 非空，最多 200 字符 |
| `X-User-Id` 请求头 | 读取/删除必填；`ctype_digit`，> 0 |
| `X-Admin-Key` 请求头 | 发送必填；为空时故障关闭 |

## 路由

```
POST   /notifications                  发送通知（仅管理员）
GET    /users/{userId}/notifications   列出通知（所有者或管理员）
POST   /notifications/{id}/read        标记为已读（仅所有者）
DELETE /notifications/{id}             删除通知（所有者或管理员）
```

## 参见

- FT214 源码：`../NENE2-FT/notiflog/`
- 相关：`docs/howto/session-token-management.md`（FT208，管理员密钥模式）
