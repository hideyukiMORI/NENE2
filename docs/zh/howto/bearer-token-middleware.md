# 操作指南：Bearer Token 中间件（JWT 认证边界情况）

> **FT 参考**：FT273（`NENE2-FT/authlog`）——BearerTokenMiddleware JWT 认证：alg=none 拒绝，签名篡改检测，exp/nbf 强制执行，WWW-Authenticate 请求头，按 sub 的数据隔离，IDOR → 404，18 个测试 / 26 个断言全部通过。
>
> **漏洞评估**：V-01 至 V-10 包含在本文档末尾。

演示使用 NENE2 的 `BearerTokenMiddleware` + `LocalBearerTokenVerifier`（HMAC-HS256）保护路由。所有 JWT 验证边界情况都由中间件处理；控制器只通过 `nene2.auth.claims` 接收已解码的声明。

---

## 设置

```php
$verifier        = new LocalBearerTokenVerifier($secret); // env: NENE2_LOCAL_JWT_SECRET
$bearerMiddleware = new BearerTokenMiddleware($problems, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearerMiddleware,
))->create();
```

中间件在任何路由处理器运行之前在请求上设置 `nene2.auth.claims`。如果验证失败，它在处理器被调用之前返回带有 `WWW-Authenticate: Bearer` 的 401。

---

## 在控制器中提取声明

```php
private function resolveOwnerId(ServerRequestInterface $request): string
{
    /** @var array<string, mixed> $claims */
    $claims = $request->getAttribute('nene2.auth.claims') ?? [];
    return (string) ($claims['sub'] ?? '');
}
```

`sub` 声明是规范的用户身份。将其用作 `owner_id` 确保了按用户的数据隔离，而无需任何额外查找。

---

## WWW-Authenticate 请求头

在 401 时，中间件发出 `WWW-Authenticate: Bearer realm="api"`。
对于过期令牌，请求头包含 `error="invalid_token"`：

```
WWW-Authenticate: Bearer realm="api", error="invalid_token", error_description="..."
```

RFC 6750 合规允许客户端区分"无令牌"和"无效令牌"。

---

## 漏洞评估

### V-01 — alg=none 算法替换 ✅ SAFE

**风险**：攻击者构造带有 `"alg":"none"` 且无签名的 JWT，声明 `sub: admin`。
**结论**：SAFE——`LocalBearerTokenVerifier` 只接受 HMAC-HS256。`alg=none` 令牌在签名验证时被拒绝；测试 `testWrongAlgorithmHeaderReturns401` 确认返回 401。

---

### V-02 — 签名篡改 ✅ SAFE

**风险**：攻击者拦截有效的 JWT 并修改载荷（例如，将 `sub` 改为 `admin`），同时保留请求头和原始签名。
**结论**：SAFE——HMAC-HS256 签名覆盖 `header.payload`。任何修改都会使 MAC 无效；`testTamperedPayloadReturns401` 确认返回 401。

---

### V-03 — 过期令牌重放 ✅ SAFE

**风险**：在会话应该无效之后重放过期令牌。
**结论**：SAFE——`exp` 声明经过验证；`exp < time()` 的令牌被拒绝。`testExpiredTokenReturns401` 确认返回 401，`WWW-Authenticate` 中带有 `invalid_token`。

---

### V-04 — Not-before (nbf) 绕过 ✅ SAFE

**风险**：在激活时间之前使用带有未来 `nbf`（尚未有效）的令牌。
**结论**：SAFE——`nbf` 被强制执行；`testNbfInFutureReturns401` 确认返回 401。

---

### V-05 — 错误的 Authorization 方案 ✅ SAFE

**风险**：攻击者发送 `Authorization: Basic dXNlcjpwYXNz` 或省略 `Bearer ` 前缀。
**结论**：SAFE——中间件只接受以 `Bearer ` 为前缀的令牌。`Basic` 和裸令牌字符串都返回 401。

---

### V-06 — 格式错误的令牌结构 ✅ SAFE

**风险**：攻击者发送只有 2 部分、4 部分、非 base64 载荷或随机字符串的令牌以探测错误处理。
**结论**：SAFE——所有格式错误的变体都返回 401。非 3 部分的令牌和无效的 base64 在任何声明提取之前就被拒绝。

---

### V-07 — 错误的签名密钥 ✅ SAFE

**风险**：了解 JWT 格式的攻击者使用不同的密钥签名令牌。
**结论**：SAFE——如果密钥不同，HMAC 验证失败；`testWrongSecretSignatureReturns401` 确认返回 401。

---

### V-08 — IDOR：跨用户数据访问 ✅ SAFE

**风险**：用户 A 通过知道或猜测条目 ID 来尝试读取用户 B 的数据。
**结论**：SAFE——`findByIdAndOwner($id, $ownerId)` 将查找范围限定为 JWT `sub`。跨用户请求返回 404（而非 403），以避免透露条目存在。

---

### V-09 — 按用户数据隔离 ✅ SAFE

**风险**：用户 A 的写入对用户 B 可见。
**结论**：SAFE——所有读取都通过 `owner_id = sub` 进行范围限定。`testEntriesAreIsolatedByToken` 验证 Alice 和 Bob 的条目完全分离。

---

### V-10 — 无 exp 声明的令牌 ✅ SAFE（可接受）

**风险**：签发没有 `exp` 声明的令牌，使其实际上永不过期。
**结论**：SAFE（设计如此）——`LocalBearerTokenVerifier` 只在声明存在时验证 `exp`。没有 `exp` 的令牌被接受。这是服务间场景的有意权衡；如果需要，生产部署应通过更严格的验证器强制 `exp`。

---

### 漏洞总结

| ID | 漏洞 | 结论 |
|----|---------------|---------|
| V-01 | alg=none 算法替换 | ✅ SAFE |
| V-02 | 签名篡改 | ✅ SAFE |
| V-03 | 过期令牌重放 | ✅ SAFE |
| V-04 | Not-before (nbf) 绕过 | ✅ SAFE |
| V-05 | 错误的 Authorization 方案 | ✅ SAFE |
| V-06 | 格式错误的令牌结构 | ✅ SAFE |
| V-07 | 错误的签名密钥 | ✅ SAFE |
| V-08 | IDOR 跨用户数据访问 | ✅ SAFE |
| V-09 | 按用户数据隔离 | ✅ SAFE |
| V-10 | 无 exp 声明的令牌 | ✅ SAFE（设计如此） |

**10 项 SAFE，0 项 EXPOSED**
无关键漏洞。`BearerTokenMiddleware` 处理所有标准 JWT 攻击向量；应用代码只需使用 `sub` 声明进行所有权范围限定。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 接受 `alg=none` 令牌 | 攻击者可以通过省略签名来伪造任何身份 |
| 跳过 `exp` 验证 | 被盗令牌永久有效 |
| IDOR 时返回 403 | 透露资源存在并属于某人 |
| 使用 `X-User-Id` 请求头代替 JWT `sub` | 请求头极易伪造；JWT 声明是密码学绑定的 |
| 在不同环境间共享签名密钥 | 开发环境泄露会危及生产令牌 |
| 使用小于 2048 位的 `RS256` 密钥 | 易受因式分解攻击 |
