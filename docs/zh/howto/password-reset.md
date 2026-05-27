# 密码重置流程

实现基于令牌的安全密码重置：请求 → 验证 → 完成。

## 概述

密码重置流程分三步：
1. 用户请求重置——生成一个有时间限制的令牌并发送（例如通过邮件）。
2. 用户在展示重置表单之前验证令牌仍然有效。
3. 用户提交新密码——令牌被消费，密码被更新。

## 数据库结构

```sql
CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash` 存储原始令牌的 SHA-256 哈希。原始令牌永不存储在数据库中。

## 令牌生成与存储

使用 `random_bytes` 生成原始令牌，然后只存储 SHA-256 哈希：

```php
$rawToken  = bin2hex(random_bytes(32)); // 256 位熵，64 个十六进制字符
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($userId, $tokenHash, $expiresAt, $now);

// 将 $rawToken 返回给用户（通过邮件或 API 响应）
```

验证时以相同方式对传入的令牌进行哈希：

```php
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

存储哈希意味着即使 DB 被攻破也不会暴露可用的重置令牌——攻击者需要对 256 位随机值逆向 SHA-256，这在计算上是不可行的。

## 用户枚举防护

`POST /password-reset` 必须始终返回 202，即使对于未知邮箱地址也如此：

```php
$user = $this->repo->findUserByEmail($email);

// 始终返回 202 — 不透露邮箱是否已注册
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// ... 为真实用户生成令牌
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

对未知邮箱返回 404 会让攻击者通过探测邮箱地址来枚举已注册账户。

## 单次使用

重置完成时设置 `used_at`。拒绝任何 `used_at IS NOT NULL` 的令牌：

```php
if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}

$this->repo->markUsed($tokenHash, $now);
```

```php
public function isUsed(): bool
{
    return $this->usedAt !== null;
}
```

## 过期

在 GET（状态检查）和 POST（完成）时都强制执行过期。始终在检查 `isUsed()` 之前检查过期：

```php
if ($reset->isExpired($now)) {
    return 410; // Gone — 与"未找到"（404）和"已使用"（409）不同
}
if ($reset->isUsed()) {
    return 409;
}
```

410（Gone）将"已过期"与"已使用"（409）区分开来，给用户提供可操作的信息。

## 旧令牌失效

当用户请求新的重置时，使该用户所有之前未使用的令牌失效：

```php
$this->executor->execute(
    "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
    [$now, $userId],
);
```

没有这个逻辑，丢失重置邮件后请求新邮件的用户会同时有两个有效令牌在流通——两个都可以用来重置密码。

## 响应清洗

`GET /password-reset/{token}` 不得在响应中暴露 `user_id` 或 `token_hash`：

```php
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'expires_at' => $this->expiresAt,
        'created_at' => $this->createdAt,
    ];
}
```

暴露 `user_id` 会将重置令牌与用户账户 ID 关联，这是不必要的——令牌本身就是授权凭证。

## 安全属性

| 属性 | 实现 |
|---|---|
| 令牌熵 | `bin2hex(random_bytes(32))` — 256 位 |
| 令牌存储 | 仅 SHA-256 哈希——原始令牌不在 DB 中 |
| 用户枚举 | `POST /password-reset` 始终返回 202 |
| 过期时间 | 1 小时；GET 和 POST 时都检查 |
| 单次使用 | 完成时设置 `used_at`；重用时返回 409 |
| 旧令牌失效 | 新请求时将之前未使用的令牌标记为已使用 |
| 响应泄露 | 所有响应中排除 `user_id` 和 `token_hash` |
| 密码哈希 | Argon2id |

## 路由汇总

| 方法 | 路径 | 描述 |
|---|---|---|
| `POST` | `/password-reset` | 请求重置（始终返回 202） |
| `GET` | `/password-reset/{token}` | 检查令牌有效性 |
| `POST` | `/password-reset/{token}` | 使用新密码完成重置 |
