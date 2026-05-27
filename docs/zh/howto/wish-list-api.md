# 操作指南：愿望清单 API（VULN-A~L 安全评估）

本指南演示一个具有完整 CRUD、管理员覆盖以及涵盖 VULN-A 至 VULN-L 的安全加固的个人愿望清单 API。

## 模式概述

- 用户通过 `POST /wishes`、`GET /wishes/{id}`、`PATCH /wishes/{id}`、`DELETE /wishes/{id}` 管理私人愿望清单。
- `GET /users/{userId}/wishes` 列出用户的愿望（仅所有者或管理员）。
- IDOR：非所有者始终得到 404（而非 403）以避免泄露资源是否存在。
- 管理员通过 `X-Admin-Key` 头部识别；密钥为空时 fail-closed。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS wishes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    fulfilled  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_wishes_user ON wishes (user_id, priority DESC, id DESC);
```

## VULN-A：SQL 注入

所有查询使用带命名占位符的 PDO 参数化语句。标题 `'; DROP TABLE wishes; --` 被原样存储而不造成任何破坏：

```php
$this->pdo->prepare(
    'INSERT INTO wishes (user_id, title, ...) VALUES (:uid, :title, ...)'
)->execute([':uid' => $userId, ':title' => $title, ...]);
```

## VULN-B：大量赋值

`update()` 处理器维护显式的字段允许列表。客户端发送的 `user_id`、`created_at` 或 `id` 等字段会被静默忽略：

```php
$allowed = ['title', 'url', 'priority', 'fulfilled'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $fields)) { ... }
}
```

## VULN-C：IDOR

非所有者的读取和删除返回 404（而非 403）以隐藏资源是否存在：

```php
if (!$isAdmin && (int) $wish['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

列表端点同样隐藏其他用户的列表：

```php
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## VULN-D：管理员 Fail-Closed

空 `adminKey` 永远不授予管理员权限。没有此守卫，未配置的部署会将每个 `X-Admin-Key: ` 头部视为有效：

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G：ReDoS

路径参数 ID 使用 `ctype_digit()` 而非可能受到 ReDoS 攻击的正则模式进行验证：

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

## VULN-I：负值

priority 必须为 0–100。负值和超过 100 的值返回 422：

```php
if (!is_int($priorityRaw) || $priorityRaw < 0 || $priorityRaw > 100) {
    return $this->problem(422, 'validation-failed', 'priority must be an integer 0–100.');
}
```

## VULN-J：JSON 类型混淆

`is_int()` 拒绝 `priority` 字段的字符串编码数字（`"5"`）和浮点数（`1.5`）。`is_bool()` 拒绝 `fulfilled` 的整数 `1`/`0`：

```php
$p = $body['priority'];
if (!is_int($p) || $p < 0 || $p > 100) { return 422; }

$f = $body['fulfilled'];
if (!is_bool($f)) { return 422; }
```

## 路由

```
POST   /wishes                 创建愿望（需要 X-User-Id）
GET    /wishes/{id}            按 ID 获取愿望（所有者或管理员）
PATCH  /wishes/{id}            更新愿望字段（仅所有者）
DELETE /wishes/{id}            删除愿望（所有者或管理员）
GET    /users/{userId}/wishes  列出用户的愿望（所有者或管理员）
```

## 验证摘要

| 字段 | 规则 |
|---|---|
| `X-User-Id` | POST/PATCH 必需；`ctype_digit`，>0 |
| `title` | 非空，最多 200 字符 |
| `url` | 可选，最多 500 字符 |
| `priority` | 整数 0–100（非字符串/浮点数）；默认 0 |
| `fulfilled` | 在 PATCH 中仅布尔值（非 1/0） |
| `{id}` 路径参数 | `ctype_digit`，最多 18 字符，>0；否则 404 |

## 另请参阅

- FT207 源码：`../NENE2-FT/wishlistlog/`
- 相关：`docs/howto/booking-resource.md`（FT201，也有 VULN）
- 相关：`docs/howto/coupon-redemption.md`（FT204，VULN + ATK）
