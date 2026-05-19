# 添加 JWT 认证

本指南介绍如何为 NENE2 应用程序添加 JWT Bearer 认证——用户注册、登录（令牌签发）和受保护端点。

**前提条件**：您已有一个运行中的 NENE2 应用程序，至少包含一个路由。
如果没有，请先阅读[添加自定义路由](./add-custom-route.md)。

---

## 概述

NENE2 提供所需的中间件和接口；您的应用程序提供具体的 JWT 签发器、验证器实现和业务逻辑。

| 类 / 接口 | 职责 |
|---|---|
| `TokenIssuerInterface` | 签发已签名 JWT 的契约 |
| `TokenVerifierInterface` | 验证 JWT 并返回 Claims 的契约 |
| `LocalBearerTokenVerifier` | 仅用于开发的 HS256 实现（实现两个接口） |
| `BearerTokenMiddleware` | 在请求上强制执行 Bearer 令牌的 PSR-15 中间件 |
| `RuntimeApplicationFactory` | 接受任何 `MiddlewareInterface` 作为 `$authMiddleware` |

---

## 第 1 步 — 配置环境变量

在 `.env` 中添加 `NENE2_LOCAL_JWT_SECRET`：

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-dev-secret-at-least-32-chars
```

---

## 第 2 步 — 创建 Auth 域

### 2a — User 实体

```php
final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $passwordHash,
    ) {}
}
```

### 2b — 仓库接口

```php
interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function create(string $email, string $passwordHash): User;
}
```

### 2c — 注册用例

```php
final class RegisterUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        // 验证、重复检查、密码哈希 ...
        $user  = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
```

### 2d — 登录用例

```php
final class LoginUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface    $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new ValidationException([
                new ValidationError('credentials', 'Invalid email or password.', 'invalid_credentials'),
            ]);
        }

        return ['token' => $this->issuer->issue(['sub' => (string) $user->id])];
    }
}
```

---

## 第 3 步 — 接入 Bearer 中间件

使用 `$excludedPaths` 保护**除**公开 Auth 端点之外的所有路由：

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier:       $verifier,
    excludedPaths:  ['/auth/register', '/auth/login'],
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [$authRegistrar, $taskRegistrar],
    authMiddleware:  $authMiddleware,
))->create();
```

---

## 第 4 步 — 在处理器中读取 Claims

```php
$claims = $request->getAttribute('nene2.auth.claims');
$userId = (int) ($claims['sub'] ?? 0);
```

---

## 第 5 步 — 对其他用户的资源返回 403

所有权检查应在用例中进行，而非中间件：

```php
if ($task->userId !== $requestingUserId) {
    throw new AccessDeniedException();
}
```

通过 `DomainExceptionHandlerInterface` 将 `AccessDeniedException` 映射为 403。

---

## 生产环境：使用 JWT 库

使用 `firebase/php-jwt` 等 JWT 库实现 `TokenVerifierInterface` 和 `TokenIssuerInterface`，
替换 `LocalBearerTokenVerifier` 注入即可，其他应用代码无需修改。

---

## 参考资料

- [ADR 0008 — JWT 认证](../adr/0008-jwt-authentication.md)
- [添加限流](./add-rate-limiting.md)
- 英文完整版：[add-jwt-authentication.md](../../howto/add-jwt-authentication.md)
