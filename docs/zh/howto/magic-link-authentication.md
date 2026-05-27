# 操作指南：魔法链接认证

> **FT 参考**：FT309（`NENE2-FT/magiclog`）——魔法链接认证：令牌以 SHA-256 哈希存储（绝不存明文），15 分钟 TTL，used-at 防止重用，过期检查在 used-at 检查之前，会话令牌 64+ 十六进制字符 SHA-256 哈希存储，已吊销/已过期的会话被拒绝，/auth/request 始终返回 202（防止用户枚举），需要 Bearer 令牌（忽略 X-User-Id 请求头），VULN-A〜L 全部 SAFE，43 个测试 / 91 个断言全部通过。

本指南展示如何构建无密码魔法链接认证系统，安全性依赖于令牌熵、哈希存储、短 TTL 和一次性使用强制。

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at TEXT    NOT NULL,          -- now + 15 分钟
    used_at    TEXT,                      -- 首次成功验证时设置
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    session_token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at         TEXT    NOT NULL,
    revoked_at         TEXT,
    created_at         TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`magic_links.token_hash` 和 `auth_sessions.session_token_hash` 都存储 SHA-256 哈希。原始令牌从不存储。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/auth/request` | — | 请求魔法链接（始终 202） |
| `POST` | `/auth/verify` | — | 验证令牌 → 会话 |
| `POST` | `/auth/logout` | `Bearer` | 吊销会话 |
| `GET` | `/me` | `Bearer` | 获取当前用户 |

## 令牌生成与哈希

```php
// 生成 64 个十六进制字符（256 位熵）
$rawToken   = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $rawToken);
$expiresAt  = (new \DateTimeImmutable())->modify('+15 minutes')->format('c');

$this->repo->createMagicLink($userId, $tokenHash, $expiresAt);

// 将原始令牌返回给调用者（通过邮件以 URL 参数形式发送给用户）
return ['token' => $rawToken];
```

原始令牌在响应中返回（作为邮件 URL 参数发送）。只有 SHA-256 哈希被存储。`UNIQUE(token_hash)` 防止哈希碰撞。

## 会话令牌

```php
$rawSessionToken  = bin2hex(random_bytes(32)); // 64 个十六进制字符
$sessionTokenHash = hash('sha256', $rawSessionToken);
$sessionExpiry    = (new \DateTimeImmutable())->modify('+24 hours')->format('c');

$this->repo->createSession($userId, $sessionTokenHash, $sessionExpiry);

return ['session_token' => $rawSessionToken]; // 只返回一次，之后只存哈希
```

会话令牌：64 个十六进制字符 = 256 位熵。以 SHA-256 哈希存储。最少 64 字符由熵来源（`bin2hex(random_bytes(32))`）保证。

## 验证——检查顺序很重要

```php
// 1. 通过哈希查找
$magicLink = $this->repo->findByTokenHash(hash('sha256', $token));
if ($magicLink === null) {
    return 401; // 未找到
}

// 2. 先检查过期
if ($magicLink['expires_at'] < date('c')) {
    return 401; // 错误消息说 'expired'
}

// 3. 再检查 used_at
if ($magicLink['used_at'] !== null) {
    return 401; // 错误消息说 'already been used'
}

// 4. 标记为已使用
$this->repo->markUsed($magicLink['id'], date('c'));

// 5. 创建会话
```

过期检查在 `used_at` 检查**之前**。如果令牌既过期又已使用，错误提示为"expired"——而非"already been used"。这防止了攻击者探测令牌是否已被使用的时序攻击。

## 用户枚举防护——始终 202

```php
public function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $email = $body['email'] ?? '';
    
    $user = $this->repo->findUserByEmail($email);
    if ($user !== null) {
        // 创建魔法链接并（生产环境中）发送邮件
        $rawToken = bin2hex(random_bytes(32));
        $this->repo->createMagicLink($user['id'], hash('sha256', $rawToken), ...);
    }
    // 无论邮箱是否存在，始终返回 202
    return $this->json->create(['message' => 'If registered, a magic link has been sent.'], 202);
}
```

不存在的邮箱地址返回与有效邮箱相同的 202 响应。绝不返回"邮箱未找到"消息。

## 会话验证

```php
$token = substr($authHeader, 7); // 去除 'Bearer '
$tokenHash = hash('sha256', $token);
$session = $this->repo->findSessionByHash($tokenHash);

if ($session === null) {
    return 401; // 会话未找到
}
if ($session['revoked_at'] !== null) {
    return 401; // 'revoked'
}
if ($session['expires_at'] < date('c')) {
    return 401; // 'expired'
}
```

三个会话检查：存在性 → 已吊销 → 已过期。注销产生的已吊销会话返回"revoked"——与"expired"有明确区分。

## 认证忽略 X-User-Id 请求头

`/me` 端点需要有效的 `Bearer` 会话令牌。其他端点用作便捷认证的 `X-User-Id` 请求头在此被显式忽略：

```php
// 只有 Bearer 令牌认证——不接受 X-User-Id
$authHeader = $request->getHeaderLine('Authorization');
if (!str_starts_with($authHeader, 'Bearer ')) {
    return 401;
}
```

---

## 漏洞评估

### V-01 — 过期令牌在已使用检查之前被拒绝 ✅ SAFE

**风险**：令牌已过期但尚未使用；攻击者尝试使用它并获得"already used"错误，暴露检查顺序。
**发现**：SAFE — 先检查过期。过期+已使用的令牌都返回"expired"。

---

### V-02 — 会话令牌以哈希存储 ✅ SAFE

**风险**：DB 泄露暴露会话令牌。
**发现**：SAFE — `session_token_hash = SHA-256(raw_token)`。DB 中不存原始令牌。

---

### V-03 — 已使用的魔法链接不能重用 ✅ SAFE

**风险**：攻击者截获魔法链接 URL 并在预期用户之后使用它。
**发现**：SAFE — `used_at` 在首次使用时设置；第二次尝试返回 401 "already been used"。

---

### V-04 — 注销使会话失效 ✅ SAFE

**风险**：会话 cookie/令牌在注销后仍然有效。
**发现**：SAFE — 注销设置 `revoked_at`；之后使用该令牌的 `/me` 返回 401 "revoked"。

---

### V-05 — 不存在的邮箱返回 202 ✅ SAFE

**风险**：攻击者通过观察不同错误响应检查哪些邮箱已注册。
**发现**：SAFE — `/auth/request` 始终返回相同响应体的 202。无"未找到"泄露。

---

### V-06 — 已吊销会话被拒绝 ✅ SAFE

**风险**：手动吊销的会话仍然授予访问权限。
**发现**：SAFE — `revoked_at` 检查拒绝访问；错误消息说"revoked"。

---

### V-07 — 已过期会话被拒绝 ✅ SAFE

**风险**：很久以前的旧会话仍然有效。
**发现**：SAFE — `expires_at` 检查拒绝访问；错误消息说"expired"。

---

### V-08 — 魔法链接令牌以哈希存入 DB ✅ SAFE

**风险**：DB 泄露暴露魔法链接令牌；攻击者以任意用户身份认证。
**发现**：SAFE — `token_hash = SHA-256(raw_token)`。DB 中不存原始令牌。

---

### V-09 — 魔法链接在 15 分钟内过期 ✅ SAFE

**风险**：长效魔法链接允许延迟截获和重放。
**发现**：SAFE — TTL ≤ 900 秒（15 分钟），经测试确认。

---

### V-10 — 会话有过期时间 ✅ SAFE

**风险**：会话永不过期；旧令牌永久有效。
**发现**：SAFE — 会话创建时设置未来的 `expires_at`；确认非空。

---

### V-11 — 会话令牌有足够的熵 ✅ SAFE

**风险**：短会话令牌可被暴力破解。
**发现**：SAFE — `bin2hex(random_bytes(32))` = 64 个十六进制字符 = 256 位熵。

---

### V-12 — X-User-Id 请求头不能绕过认证 ✅ SAFE

**风险**：`X-User-Id: 1` 请求头无需有效会话即可访问 `/me`。
**发现**：SAFE — `/me` 需要 `Authorization: Bearer <token>`。X-User-Id 被忽略。

---

### VULN 汇总

| ID | 漏洞 | 发现 |
|----|------|------|
| V-01 | 过期与已使用检查顺序 | ✅ SAFE |
| V-02 | 会话令牌明文存入 DB | ✅ SAFE |
| V-03 | 已使用魔法链接重用 | ✅ SAFE |
| V-04 | 注销后会话仍有效 | ✅ SAFE |
| V-05 | 邮箱枚举 | ✅ SAFE |
| V-06 | 已吊销会话访问 | ✅ SAFE |
| V-07 | 已过期会话访问 | ✅ SAFE |
| V-08 | 魔法链接令牌存入 DB | ✅ SAFE |
| V-09 | 魔法链接 TTL > 15 分钟 | ✅ SAFE |
| V-10 | 无会话过期 | ✅ SAFE |
| V-11 | 会话令牌熵不足 | ✅ SAFE |
| V-12 | X-User-Id 绕过 | ✅ SAFE |

**12 SAFE，0 EXPOSED**

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 在 DB 中存储原始魔法链接令牌 | DB 泄露让攻击者以任意用户身份认证 |
| 在 DB 中存储原始会话令牌 | DB 泄露使所有会话失效 |
| used_at 在 expires_at 之前检查 | 时序泄露暴露令牌是否已被使用 |
| 不存在邮箱返回错误 | 攻击者枚举已注册邮箱 |
| 魔法链接无 TTL | 无限期有效令牌；延迟截获攻击 |
| 无会话过期 | 会话永久有效 |
| Bearer 认证接受 X-User-Id | 无令牌的基于请求头的认证绕过 |
| 低熵令牌（`rand()` 或 8 字符） | 令牌可被暴力破解 |
| 多个会话重用同一魔法链接 | 单个令牌暴露授予所有后续会话 |
