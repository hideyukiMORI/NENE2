# 用户邀请系统

通过邮件邀请新用户，强制执行过期机制，并使用基于令牌的邀请防止滥用。

## 概述

邀请系统让现有用户为新账户注册提供担保。核心不变量为：

- 令牌是加密随机的，不可猜测。
- 在读取和写入时都会检查过期。
- 只有原始邀请者才能取消邀请。
- 已接受和已取消的令牌不能重复使用。

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    inviter_id  INTEGER NOT NULL,
    email       TEXT    NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    accepted_at TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (inviter_id) REFERENCES users(id)
);
```

## 令牌生成

始终使用 `bin2hex(random_bytes(32))`——64 个十六进制字符，256 位熵：

```php
$token = bin2hex(random_bytes(32));
```

永远不要使用顺序 ID、UUID 或短字符串作为邀请令牌。可猜测的令牌让攻击者可以接受任何待处理的邀请。

## 发送邀请

创建邀请前，验证目标邮件尚未注册：

```php
// 防止邀请已注册的用户
if ($this->repo->findUserByEmail($email) !== null) {
    return $this->problems->create($request, 'conflict', 'Email already registered.', 409, '');
}

$expiresAt = (new \DateTimeImmutable())->modify('+24 hours')->format('Y-m-d H:i:s');
$token     = bin2hex(random_bytes(32));
$invite    = $this->repo->createInvitation($inviterId, $email, $token, $expiresAt, $now);
```

当邀请已注册邮件时返回 409 会向邀请者泄露注册状态。这在邀请制系统中是可接受的，因为邀请者是受信任的用户。在完全公开的系统中，可以考虑将响应统一为 202。

## 接受邀请

在检查状态**之前**检查过期——待处理但已过期的邀请必须返回 410，而不是 409：

```php
$invite = $this->repo->findByTokenOrNull($token);

if ($invite === null) {
    return $this->problems->create($request, 'not-found', 'Invitation not found.', 404, '');
}

$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

if ($invite->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Invitation has expired.', 410, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is no longer valid.', 409, '');
}
```

`isExpired` 直接比较当前时间戳字符串——以 `Y-m-d H:i:s` 格式存储的 SQLite 日期时间字符串按字典顺序排序：

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}

public function isPending(): bool
{
    return $this->status === 'pending';
}
```

## 取消邀请

使用请求体中的 `inviter_id` 来执行所有权验证（因为这个简化示例中没有 session/JWT 中间件）。在生产环境中，应从已认证的令牌中获取操作者：

```php
if ($invite->inviterId !== $inviterId) {
    return $this->problems->create($request, 'forbidden', 'Only the inviter may cancel this invitation.', 403, '');
}

if (!$invite->isPending()) {
    return $this->problems->create($request, 'conflict', 'Invitation is already ' . $invite->status . '.', 409, '');
}
```

所有权检查失败时返回 403（而非 404）——隐藏邀请的存在会掩盖攻击者找到真实令牌的事实，但 403 是正确的语义，因为资源已被找到，只是操作被禁止。

## 状态机

```
pending ──accept──► accepted
pending ──cancel──► cancelled
```

一旦邀请离开 `pending` 状态，就不允许进一步转换。尝试接受 `accepted` 或 `cancelled` 的邀请返回 409。

## 安全属性

| 属性 | 实现方式 |
|---|---|
| 令牌熵 | `bin2hex(random_bytes(32))` — 256 位 |
| 令牌唯一性 | `invitations.token` 上的 UNIQUE 约束 |
| 读取时检查过期 | 在处理器中任何写入之前检查 |
| 防止重用 | 接受/取消前的 `isPending()` 守卫 |
| 所有者强制 | `inviter_id` 等值检查 → 403 |
| 无邮件 PII 泄露 | 409 响应体不暴露被邀请邮件 |
| SQL 注入 | 全程使用 PDO 参数化查询 |

## 路由概览

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/users` | 创建用户账户 |
| `POST` | `/users/{id}/invitations` | 发送邀请 |
| `GET` | `/invitations/{token}` | 查看邀请 |
| `POST` | `/invitations/{token}/accept` | 接受邀请 |
| `DELETE` | `/invitations/{token}` | 取消邀请 |
