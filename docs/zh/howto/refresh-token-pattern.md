# 操作指南：刷新令牌模式

> **FT 参考**：FT281（`NENE2-FT/refreshlog`）——刷新令牌模式：短期访问令牌（5 分钟 JWT）+ 长期刷新令牌（7 天）、SHA-256 哈希存储、使用时令牌轮换、重放攻击检测（已撤销令牌 → 撤销所有）、登出始终返回 204，15 个测试 / 63 个断言全部通过。

本指南展示如何实现刷新令牌模式——短期访问令牌保证安全，刷新令牌保证会话持续。

## 为何重要

JWT 是无状态的。一旦颁发，在过期前无法撤销。5 分钟 TTL 限制了令牌被盗时的暴露。刷新令牌在不重复密码提示的情况下延长会话，并可以在每次使用时轮换（撤销并重新颁发）以检测盗窃。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` 存储原始令牌的 SHA-256——从不存储原始值。`revoked` 是软删除标志（用于重放检测，相对于硬删除）。

## 端点

| 方法 | 路径 | 认证 | 说明 |
|--------|------|------|-------------|
| `POST` | `/auth/login` | 无 | 邮箱 + 密码 → 访问令牌 + 刷新令牌 |
| `POST` | `/auth/refresh` | 请求体中的刷新令牌 | 轮换刷新令牌，颁发新令牌对 |
| `POST` | `/auth/logout` | 请求体中的刷新令牌 | 撤销刷新令牌 |
| `GET` | `/auth/me` | Bearer 访问令牌 | 获取当前用户信息 |

## 令牌有效期

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 分钟——为安全起见较短
// 刷新令牌：7 天（RefreshTokenRepository::TTL_DAYS）
```

短期访问令牌限制被盗时的暴露。长期刷新令牌允许用户在会话间保持登录而无需重新输入密码。

## 颁发令牌对

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // 唯一令牌 ID——支持未来的撤销追踪
        'sub'   => $user->id,
        'email' => $user->email,
        'iat'   => $now,
        'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
    ]);

    $refreshToken = $this->refreshTokens->issue($user->id);

    return [
        'access_token'  => $accessToken,
        'token_type'    => 'Bearer',
        'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $refreshToken,
    ];
}
```

## 只存储哈希

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 64 个十六进制字符 = 256 位熵
    $hash      = hash('sha256', $raw);
    // INSERT token_hash = $hash ...

    return $raw;  // ← 返回原始值给客户端；从不存储
}

public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    // SELECT WHERE token_hash = $hash
}
```

如果 DB 被攻破，攻击者得到哈希——没有客户端持有的原始令牌则无用。

## 令牌轮换

```php
// 成功的 /auth/refresh：
$this->refreshTokens->revoke($stored->id);      // 撤销旧令牌
return $this->json->create($this->issueTokenPair($user));  // 颁发新令牌对
```

每次刷新都轮换令牌。旧令牌立即失效，因此被盗的刷新令牌在轮换使其无效前只能使用一次。

## 重放攻击检测

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // 已撤销令牌被重新使用 → 潜在的重放攻击
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

如果攻击者盗取刷新令牌并使用，然后合法客户端尝试使用它（此时已被撤销）——系统检测到此情况并撤销用户的所有会话，强制重新认证。

## 登出始终返回 204

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // 始终 204——从不泄露令牌是否有效
    return $this->json->createEmpty(204);
}
```

在登出时对已撤销令牌返回 401 会允许攻击者探测他们是否已被登出。

## 令牌有效性检查

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }
    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

同时检查撤销和过期。已过期但未撤销的令牌也被拒绝。

## 安全属性汇总

| 属性 | 实现 |
|---|---|
| 访问令牌 TTL | 5 分钟（最小化盗窃暴露） |
| 刷新令牌 TTL | 7 天（会话持续） |
| 令牌存储 | 仅 SHA-256 哈希；原始值从不存储 |
| 令牌轮换 | 每次成功刷新时撤销旧令牌 |
| 重放检测 | 已撤销令牌重用 → 撤销用户所有会话 |
| 登出 | 始终 204（从不泄露令牌有效性） |
| `jti` 声明 | 每个令牌唯一（支持未来的撤销追踪） |

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 在 DB 中存储原始刷新令牌 | DB 被攻破暴露所有活跃会话 |
| 撤销时使用硬删除 | 无法检测重放攻击（需要 `revoked = 1` 来知道令牌曾经存在） |
| 长访问令牌 TTL（小时/天） | 被盗令牌提供长期访问；违背刷新令牌的目的 |
| 对无效令牌的登出返回 401 | 攻击者可以探测他们是否仍然登录 |
| 访问令牌中没有 `jti` | 无法追踪单个令牌用于未来的撤销列表 |
| 单令牌（仅访问令牌，无刷新令牌） | 用户必须每 5 分钟重新认证，或使用危险的长 TTL |
| 令牌哈希使用 MD5 或 SHA-1 | 弱哈希；使用 SHA-256 或更好 |
| 刷新令牌无过期 | 刷新令牌永久有效；被盗令牌提供无限期访问 |
