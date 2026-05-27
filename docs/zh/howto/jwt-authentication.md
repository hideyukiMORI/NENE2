# 操作指南：JWT 认证

> **FT 参考**：FT261（`NENE2-FT/jwtlog`）——带 Argon2id 密码哈希和 BearerTokenMiddleware 的 JWT 认证
> **VULN**：FT261——漏洞评估（V-01 至 V-10）

使用 `LocalBearerTokenVerifier` 和 `BearerTokenMiddleware` 签发和验证 JWT Bearer 令牌。

---

## 快速开始

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not set');
$verifier = new LocalBearerTokenVerifier($secret);

// 保护除 /auth/login 之外的所有路径
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login'],
);

$app = (new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware, ...))->create();
```

---

## 签发令牌

`LocalBearerTokenVerifier` 同时实现了 `TokenIssuerInterface` 和 `TokenVerifierInterface`——一个实例处理两者。

```php
$now   = time();
$token = $verifier->issue([
    'sub'   => $user->id,       // 主体：用户标识符（int 或 string）
    'email' => $user->email,    // 自定义声明
    'iat'   => $now,            // 签发时间（Unix 时间戳——int）
    'exp'   => $now + 3600,     // 过期时间（Unix 时间戳——int，过期检查所必须）
]);
```

**`exp` 必须是 Unix 时间戳（int）。** 传入日期字符串（`'2026-06-01'`）会静默跳过过期检查，因为 `LocalBearerTokenVerifier` 在比较前会检查 `is_int($claims['exp'])`。

---

## 在处理器中读取声明

`BearerTokenMiddleware` 在验证成功后将解码的声明存储在 `nene2.auth.claims` 请求属性中：

```php
private function me(ServerRequestInterface $request): ResponseInterface
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    // 此 null 检查不应触发——中间件已拒绝了缺少令牌的请求。
    // 仍然包含此检查以满足 PHPStan level 8 和防御性清晰度。
    if (!is_array($claims)) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    return $this->json->create([
        'id'    => $claims['sub'],
        'email' => $claims['email'],
    ]);
}
```

还可使用：`$request->getAttribute('nene2.auth.credential_type')` 返回 `'bearer'`。

---

## 路径保护模式

`BearerTokenMiddleware` 支持三种模式——第一个非空配置生效：

| 配置 | 行为 | 使用场景 |
|------|------|----------|
| `protectedPaths: ['/me', '/admin']` | 只保护列出的精确路径 | 公开路径占多数 |
| `protectedPathPrefixes: ['/api/']` | 以前缀开头的路径被保护 | 保护整个子树 |
| `excludedPaths: ['/login', '/register']` | 除列出的路径外全部保护 | 公开路径占少数 |
| （默认——所有数组为空） | 每个路径都被保护 | 完全私有 API |

```php
// ✅ /auth/login 是公开的，其他所有路径需要令牌
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ 只有 /auth/me 被保护
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ 所有 /api/ 路径被保护
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/']);

// ⚠️  protectedPaths: [] 不是"不保护任何东西"——它禁用白名单模式
//     并降级到下一种模式（前缀，然后黑名单，然后全部保护）。
```

---

## `alg: none` 攻击——已被拒绝

`LocalBearerTokenVerifier` 在验证签名前检查令牌头中的 `alg == 'HS256'`。任何其他算法——包括 `none`——都会抛出 `TokenVerificationException`：

```
Token algorithm must be HS256.
```

这防止了经典的 `alg: none` 绕过攻击，攻击者可以构造无签名的无头令牌。实现自定义验证器时，务必显式强制执行预期算法。

---

## 错误响应

`BearerTokenMiddleware` 返回 401 Problem Details 并自动添加 `WWW-Authenticate` 请求头（RFC 6750）：

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

可能的 `error` 值：`missing_token`（无请求头）、`invalid_token`（错误方案、错误签名、已过期、`nbf` 在未来、格式错误）。

---

## 密钥管理

绝不硬编码 JWT 密钥。从环境变量读取：

```php
// ❌ 硬编码密钥——提交到版本控制
$verifier = new LocalBearerTokenVerifier('my-secret');

// ✅ 环境变量
$secret   = (string) (getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not configured'));
$verifier = new LocalBearerTokenVerifier($secret);
```

在所有环境中使用强随机密钥。生产环境使用库支持的实现（`firebase/php-jwt`、`lcobucci/jwt`）而非 `LocalBearerTokenVerifier`——"Local" 前缀表明其使用范围。

---

## 令牌吊销

JWT 是无状态的——没有内置吊销机制。令牌在 `exp` 之前保持有效。如果需要立即吊销（例如注销、修改密码）：

- 在 Redis 中存储令牌黑名单，TTL 与 `exp` 匹配
- 或使用短寿命令牌（15 分钟）加刷新令牌

---

## `authMiddleware` 参数名

`RuntimeApplicationFactory` 的命名参数是 `authMiddleware:`，而非 `middlewares:` 或 `middleware:`：

```php
// ❌ 未知命名参数 $middlewares
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ 正确
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## 代码审查检查清单

- [ ] `exp` 声明是 Unix 时间戳（int），而非日期字符串
- [ ] JWT 密钥从环境变量读取（非硬编码）
- [ ] `LocalBearerTokenVerifier` 不用于生产（使用库实现）
- [ ] `nene2.auth.claims` 属性在使用前进行 null 检查
- [ ] `excludedPaths` / `protectedPaths` 模式选择符合意图
- [ ] 令牌响应不包含 `password_hash` 或其他机密
- [ ] `Authorization` 请求头未被记录到日志
- [ ] 认证失败返回 401（而非 404）

---

## 时序攻击防护：用户枚举的虚拟哈希

当找不到邮箱时，`$user === null`。没有虚拟哈希，代码会完全跳过 `password_verify()`——使得未知邮箱的响应明显更快。

```php
$user = $this->repo->findByEmail(trim($body['email']));

// 始终运行 password_verify——防止基于时序的用户枚举。
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

// ⚠️  顺序很重要：password_verify() 在 || $user === null 之前
// 短路求值会在先检查 $user 的情况下跳过 password_verify()。
if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return 401;  // 无论邮箱不存在还是密码错误，返回相同错误
}
```

---

## VULN——漏洞评估（FT261）

### V-01 — 登录无暴力破解防护

**风险**：`POST /auth/login` 无速率限制。

**影响**：攻击者可以提交无限次登录尝试。Argon2id 故意设计得很慢（约 100ms），但没有速率限制时，分布式请求仍可尝试数千个密码。

**结论**：⚠️ EXPOSED——在 `POST /auth/login` 上添加 `ThrottleMiddleware`（例如 5 次/分钟/IP）。返回带 `Retry-After` 的 429。

---

### V-02 — JWT 密钥强度依赖环境

**风险**：如果 `NENE2_LOCAL_JWT_SECRET` 为空或弱（`secret`、`test`），HMAC-HS256 令牌可被暴力破解或猜出。带管理员声明的伪造令牌将被接受。

**结论**：⚠️ EXPOSED——启动时故障关闭检查：
```php
if (strlen($jwtSecret) < 32) {
    throw new \RuntimeException('NENE2_LOCAL_JWT_SECRET must be at least 32 random bytes.');
}
```

---

### V-03 — 无令牌吊销

**风险**：已签发的 JWT 在 `exp` 之前保持有效。被盗令牌或属于已删除用户的令牌最长可被接受 1 小时。

**结论**：⚠️ EXPOSED——实现令牌黑名单（例如 `revoked_tokens(jti TEXT PK, revoked_at TEXT)`）或使用短寿命令牌（15 分钟）加刷新令牌。

---

### V-04 — 无用户注册端点

**风险**：不存在 `POST /auth/register` 路由。测试用户需要直接插入 DB，绕过应用程序强制的密码哈希策略。

**结论**：⚠️ DESIGN GAP——添加带邮箱校验和 Argon2id 哈希的 `POST /auth/register`。

---

### V-05 — 邮箱大小写敏感：无规范化

**风险**：`WHERE email = ?` 区分大小写。`USER@EXAMPLE.COM` 和 `user@example.com` 是不同的查找。不同大小写的两个账户可以并存。

**结论**：⚠️ EXPOSED——在注册和登录时将邮箱规范化为小写（`strtolower()`）。

---

### V-06 — 令牌 TTL：1 小时对敏感 API 可能太长

**风险**：`TOKEN_TTL_SECONDS = 3600`。被盗令牌最长有效 1 小时。

**结论**：⚠️ DESIGN CONSIDERATION——1 小时对大多数 API 可接受。敏感操作使用更短的 TTL（5–15 分钟）加刷新令牌。使 TTL 可配置。

---

### V-07 — `password_hash` 不在 JWT 声明中

**风险**：`issue()` 调用只包含 `sub`、`email`、`iat`、`exp`。

**结论**：✅ SAFE——声明是最小的。即使令牌被解码（base64，非加密），也不会暴露敏感内部数据。

---

### V-08 — 通过邮箱的 SQL 注入

**攻击**：`{"email": "' OR '1'='1", "password": "x"}`

**观察结果**：`WHERE email = ?` 是参数化查询。注入被视为字面字符串。找不到用户；返回 401。

**结论**：🚫 BLOCKED——参数化查询防止 SQL 注入。

---

### V-09 — 无邮箱格式校验

**风险**：任意非空字符串被接受为邮箱（例如 `"not-an-email"`）。

**影响**：浪费 Argon2id 计算；DB 中存在无效用户；密码重置流程损坏。

**结论**：⚠️ EXPOSED——在注册和登录时添加 `filter_var($email, FILTER_VALIDATE_EMAIL)`。

---

### V-10 — 无 HTTPS 强制

**风险**：JWT 令牌和密码通过 HTTP 明文传输。

**结论**：⚠️ EXPOSED——生产环境强制使用 HTTPS。通过 `SecurityHeadersMiddleware` 添加 `Strict-Transport-Security` 请求头。

---

## VULN 汇总

| # | 漏洞 | 结论 |
|---|------|------|
| V-01 | 无暴力破解防护 | ⚠️ EXPOSED |
| V-02 | JWT 密钥强度（依赖环境） | ⚠️ EXPOSED |
| V-03 | 无令牌吊销 | ⚠️ EXPOSED |
| V-04 | 无注册端点 | ⚠️ DESIGN GAP |
| V-05 | 邮箱大小写敏感/无规范化 | ⚠️ EXPOSED |
| V-06 | 令牌 TTL 1 小时 | ⚠️ DESIGN CONSIDERATION |
| V-07 | password_hash 不在 JWT 声明中 | ✅ SAFE |
| V-08 | 通过邮箱的 SQL 注入 | 🚫 BLOCKED |
| V-09 | 无邮箱格式校验 | ⚠️ EXPOSED |
| V-10 | 无 HTTPS 强制 | ⚠️ EXPOSED |

**生产前必须修复的关键问题**：
1. **V-01** — `ThrottleMiddleware` 用于 `POST /auth/login`（5 次/分钟/IP）
2. **V-02** — 启动时 JWT 密钥故障关闭校验（`strlen >= 32`）
3. **V-03** — 令牌吊销列表或短 TTL + 刷新令牌
4. **V-05** — 注册和登录时将邮箱规范化为小写
5. **V-09** — 注册时添加 `filter_var($email, FILTER_VALIDATE_EMAIL)`

---

## 相关操作指南

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — PIN 验证的暴力破解锁定
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — 速率限制中间件
- [`webhook-signature-verification.md`](webhook-signature-verification.md) — HMAC-SHA256 + 时序安全比较
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — 显式 DTO 白名单
