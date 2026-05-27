# 操作指南：使用 Bearer Token 认证

NENE2 提供 `BearerTokenMiddleware` 和 `LocalBearerTokenVerifier`，用于基于 JWT 的认证。本指南涵盖设置、配置、token 颁发和常见陷阱。

## 设置

使用 `authMiddleware` 命名参数将中间件连接到 `RuntimeApplicationFactory`：

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: 'change-me';
$verifier = new LocalBearerTokenVerifier($secret);
$bearer   = new BearerTokenMiddleware($problemDetails, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearer, // ← 命名参数是 authMiddleware，不是 middlewares
))->create();
```

> **注意：** 参数名是 `authMiddleware`，不是 `middlewares`。使用 `middlewares:` 会导致运行时 `Error: Unknown named parameter`。

## 保护所有路由 vs. 选择性保护

`BearerTokenMiddleware` 支持四种路径匹配模式（第一个匹配优先）：

```php
// 1. 只保护特定路径（白名单）
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/me', '/entries']);

// 2. 保护以某前缀开头的路径（前缀白名单）
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/', '/me/']);

// 3. 保护除列出路径之外的所有路径（黑名单——常见模式）
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/auth/register', '/health']);

// 4. 保护所有路径（默认——不传数组）
new BearerTokenMiddleware($problems, $verifier);
```

## 在处理器中读取声明

验证成功后，声明作为请求属性存储：

```php
/** @var array<string, mixed> $claims */
$claims  = $request->getAttribute('nene2.auth.claims') ?? [];
$ownerId = (string) ($claims['sub'] ?? '');
```

凭证类型单独存储：

```php
$credType = $request->getAttribute('nene2.auth.credential_type'); // 'bearer'
```

## 颁发 Token（本地/测试）

`LocalBearerTokenVerifier` 也实现了 `TokenIssuerInterface`：

```php
$token = $verifier->issue([
    'sub' => 'user-123',
    'iat' => time(),
    'exp' => time() + 3600, // ← 始终包含 exp
]);
```

> **始终包含 `exp`。** 没有 `exp` 的 Token 被视为永不过期。这对测试是安全的，但如果此类 Token 到达生产环境则很危险。如果 `exp` 缺失，验证器会跳过过期检查。

## 错误响应

验证失败时，`BearerTokenMiddleware` 返回带有 `WWW-Authenticate` 头部的 `401 Unauthorized` Problem Details 响应：

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="NENE2", error="invalid_token", error_description="Token has expired."
Content-Type: application/problem+json
```

`WWW-Authenticate` 中的错误代码：
- `missing_token` — 无 `Authorization` 头部
- `invalid_token` — 格式错误的 scheme、已过期、签名无效、格式错误、算法错误、`nbf` 在未来

## `LocalBearerTokenVerifier` 的安全属性

| 威胁 | 防护 |
|------|------|
| 签名伪造 | HMAC-HS256，恒定时间 `hash_equals` |
| 算法替换（`alg:none`） | 只接受 `HS256` |
| 过期 Token | 检查 `exp` 声明 |
| 尚未生效的 Token | 检查 `nbf` 声明 |
| 篡改有效载荷 | 签名覆盖 header + payload；篡改会破坏签名 |

> `LocalBearerTokenVerifier` 为本地开发和测试设计。在生产环境中，注入支持密钥轮换和非对称算法的 `TokenVerifierInterface` 库实现（例如 firebase/php-jwt）。

## 测试模式

```php
// 在 setUp() 中：使用测试密钥创建验证器
$this->verifier = new LocalBearerTokenVerifier('test-secret');

// 为用户颁发有效 token
$token = $this->verifier->issue(['sub' => 'alice', 'exp' => time() + 3600]);

// 颁发已过期 token（用于负向测试）
$expired = $this->verifier->issue(['sub' => 'alice', 'exp' => time() - 1]);

// 颁发尚未生效的 token
$future = $this->verifier->issue(['sub' => 'alice', 'nbf' => time() + 3600, 'exp' => time() + 7200]);
```
