# 操作指南：JWT 刷新令牌轮换

本指南介绍如何实现短期访问令牌与长期刷新令牌的组合。关键属性是**轮换**：每次使用刷新令牌时立即撤销它并颁发一个新的。重用已撤销的刷新令牌会触发该用户所有令牌的撤销。

---

## 为何需要两个令牌？

| 令牌 | TTL | 存储 | 用途 |
|---|---|---|---|
| 访问令牌 | 5 分钟 | 客户端内存 | 认证 API 请求（无状态，无 DB 查询） |
| 刷新令牌 | 7 天 | DB（哈希） | 颁发新访问令牌；通过轮换管理 |

短期访问令牌限制了泄露时的损害——几分钟内过期。刷新令牌延长会话而无需重新登录，但它是可撤销的，因为它存在于数据库中。

---

## 数据库结构

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,  -- SHA-256 哈希；从不是原始值
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash`——始终存储哈希，从不存储原始令牌。如果 DB 泄露，哈希令牌无法直接使用。

---

## 颁发令牌

### 访问令牌：添加 `jti` 保证唯一性

没有 `jti`，同一用户在同一秒颁发的两个令牌是相同的——它们的载荷字节完全相同。`jti`（JWT ID）保证每个令牌唯一，是未来访问令牌黑名单的基础：

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // 每次颁发唯一
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 300,  // 5 分钟
]);
```

### 刷新令牌：存储哈希，返回原始值

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 256 位随机令牌
    $hash      = hash('sha256', $raw);       // 只存储这个
    $expiresAt = (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
    $createdAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $this->executor->insert(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at)
         VALUES (?, ?, ?, 0, ?)',
        [$userId, $hash, $expiresAt, $createdAt],
    );

    return $raw;  // 客户端接收这个；DB 从不存储它
}
```

从客户端提供的值查找令牌：

```php
public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);

    $row = $this->executor->fetchOne(
        'SELECT ... FROM refresh_tokens WHERE token_hash = ?',
        [$hash],
    );
    // ...
}
```

---

## 令牌轮换

每次刷新请求必须在颁发新令牌之前撤销旧令牌：

```php
private function refresh(ServerRequestInterface $request): ResponseInterface
{
    // ... 解析请求体，查找存储的令牌 ...

    if ($stored === null || !$stored->isValid()) {
        // 已撤销令牌的重用是潜在的重放攻击——
        // 撤销用户的所有令牌以强制重新认证。
        if ($stored !== null && $stored->revoked) {
            $this->refreshTokens->revokeAllForUser($stored->userId);
        }

        return $this->problems->create(
            $request,
            'invalid-refresh-token',
            'Invalid or Expired Refresh Token',
            401,
            'The refresh token is invalid, expired, or has already been used.',
        );
    }

    $user = $this->users->findById($stored->userId);

    // 轮换：先撤销旧令牌，再颁发新令牌对
    $this->refreshTokens->revoke($stored->id);

    return $this->json->create($this->issueTokenPair($user));
}
```

**重用检测**：如果已撤销的刷新令牌到达 `/auth/refresh` 端点，这意味着用户在重放旧令牌（不寻常）或攻击者盗取了它。`revokeAllForUser()` 强制每个会话重新认证，限制爆炸半径。

---

## 登出：始终返回 204

根据刷新令牌是否有效返回不同的状态码会允许攻击者探测令牌是否仍然活跃：

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    // ... 解析请求体 ...

    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // 始终 204——从不泄露令牌是否有效
    return $this->json->createEmpty(204);
}
```

这也意味着双重登出（用相同令牌两次调用登出）两次都返回 204——客户端始终可以安全调用登出而无需担心令牌状态。

---

## RefreshToken 实体上的有效性检查

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }

    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

字符串比较对按字典序排序的 ISO-8601 日期有效。如果将时间戳存储为 Unix 整数，则与 `time()` 比较。

---

## BearerTokenMiddleware：排除刷新/登出路径

刷新和登出端点在请求体中接收刷新令牌，而非在 Authorization 头中接收 Bearer 访问令牌。从 `BearerTokenMiddleware` 中排除它们：

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
);
```

`/auth/me` 端点（以及任何其他受保护的路径）仍由中间件保护。

---

## 响应格式

```json
{
  "access_token":  "eyJhbGci...",
  "token_type":    "Bearer",
  "expires_in":    300,
  "refresh_token": "a3f92c..."
}
```

`expires_in`（秒）让客户端在访问令牌过期前安排主动刷新，避免失败请求后再刷新。

---

## 代码审查清单

1. `token_hash` 列存储 `hash('sha256', $raw)`——从不存储原始值
2. 在刷新处理器中，`revoke()` 在 `issueTokenPair()` 之前调用
3. 已撤销令牌重用触发 `revokeAllForUser()`（不仅仅是 401）
4. 登出始终返回 204——无条件 401/404
5. 访问令牌 TTL 较短（≤ 15 分钟）
6. 访问令牌中存在 `jti` 声明
7. 测试覆盖跨令牌轮换（刷新后旧令牌无效）和重用检测

---

## 测试轮换和重用检测

```php
public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
{
    $tokens = $this->login();

    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // 旧令牌必须被拒绝
    $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}

public function testRefreshTokenReuseRevokesAllUserTokens(): void
{
    $tokens = $this->login();

    // 轮换一次——旧令牌现在已被撤销
    $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

    // 攻击者重放旧（已撤销）刷新令牌——触发 revokeAllForUser()
    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // 新颁发的刷新令牌现在也被撤销
    $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}
```

---

## 参阅

- [`jwt-authentication.md`](jwt-authentication.md) — JWT 颁发、BearerTokenMiddleware、`nene2.auth.claims`
- [`password-hashing.md`](password-hashing.md) — Argon2id、用于防用户枚举的哑值哈希模式
