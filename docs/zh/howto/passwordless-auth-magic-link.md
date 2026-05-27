# 无密码认证（Magic Link）

无密码认证（Magic Link）的实现指南。本文解说仅凭邮箱地址即可认证的一次性链接系统的安全设计模式。

## 概述

Magic Link 认证的工作流程如下：

1. 用户提交邮箱地址
2. 服务器生成一次性令牌（Magic Link）并通过邮件发送
3. 用户提交令牌以获取会话令牌
4. 使用会话令牌访问 API

## 端点

| 方法 | 路径 | 描述 |
|---|---|---|
| `POST` | `/auth/request` | 提交邮箱地址 → 生成 Magic Link（始终返回 202） |
| `POST` | `/auth/verify` | 验证 Magic Link 令牌 → 签发会话令牌 |
| `POST` | `/auth/logout` | 使会话失效（始终返回 204） |
| `GET` | `/me` | 获取已认证用户信息 |

## 数据库设计

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,  -- 存储 SHA-256 哈希（不存储原始值）
    expires_at TEXT NOT NULL,          -- 15 分钟有效期
    used_at TEXT,                      -- 单次使用（NULL = 未使用）
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,  -- 存储 SHA-256 哈希
    expires_at TEXT NOT NULL,                  -- 24 小时有效期
    revoked_at TEXT,                           -- 注销时设置
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## 安全设计

### 令牌以 SHA-256 哈希存储

```php
// 生成：256 位随机数 → 十六进制字符串（64 个字符）
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// 只在 DB 中存储 tokenHash
// 只有 rawToken 通过邮件发送（DB 泄露时的安全保障）
```

即使 DB 泄露也无法从哈希中恢复令牌。原理与密码哈希相同。

### 用户枚举防护

```php
// POST /auth/request 始终返回 202
// 对已注册/未注册邮箱不改变响应
return $this->responseFactory->create([
    'message' => 'if this email is registered, a magic link has been sent',
], 202);
```

攻击者无法确认邮箱地址是否有效。

### 过期检查先于 used_at 检查

```php
// 先检查过期
if ($now > (string) $link['expires_at']) {
    return $this->responseFactory->create(['error' => 'token has expired'], 401);
}

// 然后检查 used_at
if ($link['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'token has already been used'], 401);
}
```

防止攻击者得知过期令牌是否已被使用（防止时序信息泄露）。

### 单次使用（防重放攻击）

```php
// 验证成功时立即设置 used_at
$this->repository->markMagicLinkUsed($linkId, $now);
```

同一个 Magic Link 不能被使用两次。防止被拦截链接的重用。

### 会话使失效（注销）

```php
// 注销始终返回 204 — 不泄露会话是否存在
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession((int) $session['id'], date('c'));
}
return $this->responseFactory->createEmpty(204);
```

`/me` 端点如果 `revoked_at !== null` 则返回 401。

## 会话验证流程

```php
private function handleMe(ServerRequestInterface $request): ResponseInterface
{
    $rawToken = $this->extractBearerToken($request);
    if ($rawToken === '') {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }

    $tokenHash = hash('sha256', $rawToken);
    $session = $this->repository->findSessionByTokenHash($tokenHash);

    if ($session === null) { return 401; }
    if ($session['revoked_at'] !== null) { return 401 revoked; }
    if ($now > $session['expires_at']) { return 401 expired; }

    // ...
}
```

## Bearer 令牌提取

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

不使用 `X-User-Id` 请求头进行认证。只使用 `Authorization: Bearer <token>`。

## 新用户自动创建

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    $this->executor->execute('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
    return (int) $this->executor->lastInsertId();
}
```

首次登录时自动创建用户。这是无密码认证的特性。

## Magic Link 有效期

- **Magic Link**：15 分钟（900 秒）——给用户开启邮件并点击链接留有余地
- **Session Token**：24 小时（86400 秒）——常规 API 会话

```php
$expiresAt = date('c', time() + 900);    // magic link：15 分钟
$sessionExpiresAt = date('c', time() + 86400);  // 会话：24 小时
```

## 生产环境注意事项

- **邮件发送**：本 FT 中将 `token` 包含在响应中（用于测试）。生产环境中应通过 SMTP 发送到用户的邮箱，并从响应中删除令牌。
- **速率限制**：对 `/auth/request` 的请求按 IP / 邮箱进行速率限制。
- **旧的未使用链接失效**：当同一邮箱多次调用 `/auth/request` 时，考虑显式使旧的未使用链接失效。
- **必须使用 HTTPS**：Magic Link 令牌包含在 URL 参数中，因此必须使用 HTTPS（防止中间人攻击）。
