# 操作指南：RBAC + JWT 认证

> **FT 参考**：FT279（`NENE2-FT/rbaclog`）——基于角色的访问控制与 JWT：Argon2id 密码哈希带时序攻击防护、JWT 中的角色声明、401 与 403 的区分、带手动回退的 `BearerTokenMiddleware`，14 个测试 / 48 个断言全部通过。
>
> **VULN 评估**：文档末尾包含 V-01 到 V-10。

本指南展示如何使用 NENE2 通过 JWT 令牌构建基于角色的访问控制（RBAC）系统。

## 功能

- 邮箱 + 密码登录（Argon2id 哈希）
- 角色声明嵌入 JWT（`user` / `admin`）
- 公开、已认证和仅管理员端点
- 带按处理器回退的 `BearerTokenMiddleware`
- 正确的 `401 Unauthorized` 与 `403 Forbidden` 语义

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'user',
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author_id  INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

## 端点

| 方法 | 路径 | 认证 | 说明 |
|--------|------|------|-------------|
| `POST` | `/auth/login` | 无 | 登录，获取 JWT |
| `GET` | `/posts` | 无 | 列出所有帖子（公开） |
| `POST` | `/posts` | 用户或管理员 | 创建帖子 |
| `DELETE` | `/posts/{id}` | 仅管理员 | 删除帖子 |

## 带时序攻击防护的登录

哑值哈希技巧确保无论邮箱是否存在，登录始终耗时相同：

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401, '...');
}
```

没有哑值哈希，时序攻击可以通过测量响应时间来检测有效的邮箱地址——对于未知邮箱，哈希计算被跳过。

## JWT 中的角色声明

角色存储在 JWT 载荷中，避免每次请求进行 DB 往返：

```php
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // Role::User → 'user', Role::Admin → 'admin'
    'iat'   => $now,
    'exp'   => $now + self::TOKEN_TTL_SECONDS,
]);
```

## 使用枚举的角色检查

```php
private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
{
    $claims = $this->requireAuth($request);
    if ($claims instanceof ResponseInterface) {
        return $claims;
    }

    $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

    if ($actualRole !== $required) {
        return $this->problems->create(
            $request, 'forbidden', 'Forbidden', 403,
            "This action requires the '{$required->value}' role."
        );
    }

    return $claims;
}
```

`Role::tryFrom()` 安全地将字符串声明映射回枚举——无效的角色字符串变为 `null`，不通过检查。

## 401 与 403 的区分

| 状态 | 含义 | 时机 |
|--------|---------|------|
| `401 Unauthorized` | 未认证 | 无令牌、令牌无效、令牌过期 |
| `403 Forbidden` | 已认证但角色不足 | 令牌有效，角色错误 |

这个区分对客户端很重要：`401` 应提示重新登录；`403` 应显示"拒绝访问"消息。

## 带回退的 BearerTokenMiddleware

某些路径同时服务于公开和受保护的方法（例如，`GET /posts` 是公开的，`POST /posts` 是已认证的）。中间件完全排除该路径，需要认证的处理器手动调用 `requireAuth()`：

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts 需要按方法处理
);
```

```php
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // 快速路径：中间件已验证
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // 慢速路径：排除路径的手动提取
    $authorization = $request->getHeaderLine('Authorization');
    if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }

    try {
        return $this->verifier->verify(substr($authorization, 7));
    } catch (TokenVerificationException) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
    }
}
```

---

## VULN 评估——漏洞诊断

### V-01 — 通过伪造 JWT 声明提升角色 ✅ SAFE

**威胁**：攻击者创建带 `"role": "admin"` 的 JWT 并用随机密钥签名。
**防御**：`LocalBearerTokenVerifier` 针对服务器密钥验证 HMAC-HS256 签名。密钥不匹配导致 `TokenVerificationException` → 401。
**结果**：✅ SAFE——签名验证防止声明伪造。

---

### V-02 — 通过登录时邮箱枚举的时序攻击 ✅ SAFE

**威胁**：攻击者发送对未知和已知邮箱的登录请求，测量响应时间以枚举有效账户。
**防御**：对于未知邮箱，`password_verify()` 针对哑 Argon2id 哈希（相同的费用参数）调用。两条路径各耗时约 200ms。未知邮箱和密码错误的登录失败消息相同。
**结果**：✅ SAFE——时序被均衡；错误消息是通用的。

---

### V-03 — 过期令牌被接受为有效 ✅ SAFE

**威胁**：攻击者在令牌过期后重用已捕获的 JWT。
**防御**：`LocalBearerTokenVerifier` 针对 `time()` 检查 `exp` 声明。过期令牌抛出 `TokenVerificationException` → 401。
**结果**：✅ SAFE——强制执行 `exp` 检查。

---

### V-04 — 通过修改 JWT 载荷降级角色（不重新签名）✅ SAFE

**威胁**：攻击者 base64 解码 JWT 载荷，将 `"role": "user"` 改为 `"role": "admin"`，重新编码，并使用原始签名提交。
**防御**：JWT 签名覆盖 header + payload。修改 payload 使签名无效 → `TokenVerificationException` → 401。
**结果**：✅ SAFE——HMAC 检测到载荷篡改。

---

### V-05 — 使用用户角色访问管理员端点 ✅ SAFE

**威胁**：攻击者以 `user` 身份登录并尝试 `DELETE /posts/{id}`。
**防御**：`requireRole($request, Role::Admin)` 检查 JWT `role` 声明。`user` 令牌有 `role: 'user'` → `Role::tryFrom('user') !== Role::Admin` → 403。
**结果**：✅ SAFE——返回 403 Forbidden；用户令牌无法提升到管理员。

---

### V-06 — 未认证访问受保护端点 ✅ SAFE

**威胁**：攻击者无 Authorization 头发送 `POST /posts` 或 `DELETE /posts/{id}`。
**防御**：`requireAuth()` 检查 `Bearer ` 前缀；头不存在 → 401 `unauthorized`。
**结果**：✅ SAFE——返回 401 Unauthorized。

---

### V-07 — 401 与 403 混淆（信息泄露）✅ SAFE

**威胁**：不正确的 401/403 使用暴露资源是否存在或用户是否已认证。
**防御**：系统对未认证访问（无/无效令牌）返回 401，对角色不足的已认证访问返回 403。区分在语义上是正确的，不会暴露超出角色要求的资源存在性。
**结果**：✅ SAFE——401/403 语义正确；`test401MeansNotAuthenticated` 和 `test403MeansAuthenticatedButForbidden` 测试都通过。

---

### V-08 — JWT 中无效角色字符串绕过 ✅ SAFE

**威胁**：攻击者制作带有效密钥的 JWT（例如密钥泄露场景）并将 `role` 设置为未知值，如 `"superadmin"`。
**防御**：`Role::tryFrom((string) ($claims['role'] ?? ''))` 对未知字符串返回 `null` → `null !== Role::Admin` → 403。
**结果**：✅ SAFE——`tryFrom()` 是 null 安全的；未知角色被视为不足。

---

### V-09 — 通过登录邮箱字段的 SQL 注入 ✅ SAFE

**威胁**：攻击者发送 `{"email": "' OR '1'='1", "password": "anything"}`。
**防御**：`findByEmail()` 使用参数化查询（`WHERE email = ?`）。注入的字符串作为字面值处理，而非 SQL。
**结果**：✅ SAFE——参数化查询防止 SQL 注入。

---

### V-10 — 明文存储密码 ✅ SAFE

**威胁**：如果 DB 被攻破，密码可读。
**防御**：`password_hash($password, PASSWORD_ARGON2ID)`，费用参数 `m=65536,t=4,p=1`。只存储 Argon2id 哈希；明文密码从不持久化。
**结果**：✅ SAFE——Argon2id 是当前推荐算法（RFC 9106）；PBKDF2/bcrypt/scrypt 也能通过。

---

### VULN 汇总

| ID | 威胁 | 结果 |
|----|--------|--------|
| V-01 | 通过伪造 JWT 提升角色 | ✅ SAFE |
| V-02 | 通过邮箱枚举的时序攻击 | ✅ SAFE |
| V-03 | 过期令牌被接受 | ✅ SAFE |
| V-04 | JWT 载荷篡改不重新签名 | ✅ SAFE |
| V-05 | 使用用户角色令牌访问管理员端点 | ✅ SAFE |
| V-06 | 未认证访问受保护端点 | ✅ SAFE |
| V-07 | 401 与 403 混淆 | ✅ SAFE |
| V-08 | 未知角色字符串绕过 | ✅ SAFE |
| V-09 | 通过邮箱字段的 SQL 注入 | ✅ SAFE |
| V-10 | 明文存储密码 | ✅ SAFE |

**10 SAFE，0 EXPOSED**
Argon2id 哈希、HMAC 签名的 JWT、`Role::tryFrom()` 守护和参数化查询防止了所有测试的漏洞向量。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 将角色存储在 DB 并每次请求查找 | 每次请求需额外 DB 查询；角色变更需要令牌撤销逻辑 |
| 使用 `Role::from()` 而非 `Role::tryFrom()` | 未知角色字符串抛出 `ValueError`——500 而非 403 |
| 对未认证请求返回 403 | 误导客户端——403 应表示"已认证但禁止"，而非"未登录" |
| 对错误角色访问返回 401 | 客户端可能尝试重新登录而非显示"拒绝访问" |
| 登录时跳过哑值哈希 | 时序攻击暴露有效邮箱地址 |
| 以 MD5/SHA1/明文存储密码 | 暴力破解或彩虹表攻击在 DB 被攻破时暴露所有密码 |
| 在 JWT 中嵌入权限（而非角色） | 权限集变更需要重新颁发令牌；角色稳定，权限变化 |
| 允许 `alg: none` JWT | 攻击者可以完全删除签名来伪造令牌 |
| 使用 `str_contains($role, 'admin')` 而非枚举检查 | `"not-admin"` 或 `"superadmin"` 可能意外匹配 |
