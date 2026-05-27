# 操作指南：基于角色的访问控制（RBAC）

通过 JWT 声明和 `BearerTokenMiddleware` 实现基于角色的访问控制。

---

## 快速开始

```php
// 1. 登录时在 JWT 中包含角色
$token = $issuer->issue([
    'sub'  => $user->id,
    'role' => $user->role->value,  // 'user' 或 'admin'
    'exp'  => time() + 3600,
]);

// 2. 在处理器中检查角色
/** @var array<string, mixed>|null $claims */
$claims     = $request->getAttribute('nene2.auth.claims');
$actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

if ($actualRole !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## 在 JWT 声明中嵌入角色

**两种方式：**

| 方式 | 优点 | 缺点 |
|---|---|---|
| 角色在 JWT 声明中 | 每次请求无需 DB 查询 | 角色变更只在令牌过期后生效 |
| 每次请求查 DB | 角色变更立即生效 | 每次已认证请求需额外查询 |

对于大多数应用，JWT 方式是合适的。对于高安全性场景（医疗、金融、管理员权限撤销），在敏感操作上添加 DB 查找。

```php
// 登录——在声明中嵌入角色
$token = $issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // 字符串: 'user' | 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

## 401 Unauthorized 与 403 Forbidden

这个区分对客户端错误处理很重要（401 → 重定向到登录，403 → 显示权限错误）：

| 情况 | 状态 |
|---|---|
| 无令牌 / 已过期 / 签名无效 | **401** Unauthorized |
| 令牌有效但角色不足 | **403** Forbidden |
| 资源未找到 | **404** Not Found |

```php
// ❌ 错误——已认证用户收到 401（暗示"未登录"）
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ 正确——已认证但缺乏权限
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## `requireAuth()` / `requireRole()` 模式

路由注册器中的可复用辅助方法对：

```php
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenVerifierInterface $verifier,
        // ... 其他依赖
    ) {}

    /**
     * 成功时返回声明，失败时返回 401 ResponseInterface。
     * 优先检查中间件属性；对被 BearerTokenMiddleware 排除的路径回退到手动验证。
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
    {
        /** @var array<string, mixed>|null $claims */
        $claims = $request->getAttribute('nene2.auth.claims');

        if (is_array($claims)) {
            return $claims;
        }

        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '' || !str_starts_with($authorization, 'Bearer ')) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Authentication required.');
        }

        try {
            return $this->verifier->verify(substr($authorization, 7));
        } catch (TokenVerificationException) {
            return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401,
                'Token is invalid or expired.');
        }
    }

    /**
     * 用户具有所需角色时返回声明，否则返回 401/403 ResponseInterface。
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function requireRole(ServerRequestInterface $request, Role $required): array|ResponseInterface
    {
        $claims = $this->requireAuth($request);

        if ($claims instanceof ResponseInterface) {
            return $claims;
        }

        $actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

        if ($actualRole !== $required) {
            return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
                "This action requires the '{$required->value}' role.");
        }

        return $claims;
    }
}
```

在处理器中的用法：

```php
private function deletePost(ServerRequestInterface $request): ResponseInterface
{
    $claims = $this->requireRole($request, Role::Admin);
    if ($claims instanceof ResponseInterface) {
        return $claims;  // 401 或 403
    }
    // $claims 现在是已验证的管理员 JWT 载荷
}
```

---

## `BearerTokenMiddleware` 不按 HTTP 方法区分

`BearerTokenMiddleware` 使用请求路径而非 HTTP 方法来决定是否需要认证。当 `GET /posts`（公开）和 `POST /posts`（需认证）共享同一路径时，从中间件中排除 `/posts` 并在处理器中手动验证令牌：

```php
// 中间件：完全排除 /posts（涵盖 GET 和 POST）
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);

// 对于 DELETE /posts/{id}（路径 /posts/1，/posts/2 等）——不在 excludedPaths → 中间件保护它。
// 对于 POST /posts（路径 /posts）——已排除 → 处理器必须手动调用 requireAuth()。
```

上面的 `requireAuth()` 辅助方法透明地处理了这个问题：如果中间件属性存在，它从中读取 `nene2.auth.claims`；否则直接解析 `Authorization` 头。

**替代方案**：使用不同的路径前缀来完全避免歧义：
- `GET /public/posts` — 无认证
- `POST /posts` — 需认证（中间件可以保护 `/posts` 而无冲突）

---

## `Role` 枚举模式

使用支持枚举实现类型安全的角色处理：

```php
enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}

// ❌ Role::from() 对未知值抛出异常
$role = Role::from($claims['role']);  // 如果是 'superuser' 或 '' 则 UnhandledMatchError

// ✅ Role::tryFrom() 对未知值返回 null
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role === null || $role !== Role::Admin) {
    return 403;
}
```

---

## 204 No Content——使用 `createEmpty()`

`JsonResponseFactory::create()` 需要 `array` 参数。对于无请求体的 204 响应，使用 `createEmpty()`：

```php
// ❌ 类型错误——create() 不接受 null
return $this->json->create(null, 204);

// ❌ 返回空 JSON 对象 {}（204 应无请求体）
return $this->json->create([], 204);

// ✅ 正确——无请求体，正确状态
return $this->json->createEmpty(204);
```

---

## 代码审查清单

- [ ] `role` 声明用 `Role::tryFrom()` 解码（不用 `Role::from()`——对未知值抛出异常）
- [ ] 权限不足返回 403，未认证返回 401（不都返回 401）
- [ ] `requireRole()` 也调用了 `requireAuth()`——不需要重复的认证检查
- [ ] 理解 `BearerTokenMiddleware` 排除：排除的路径绕过声明属性
- [ ] 排除路径上的处理器调用 `requireAuth()` 进行手动令牌验证
- [ ] 204 响应使用 `createEmpty(204)` 而非 `create(null, 204)`
- [ ] 理解 JWT 角色缓存：角色变更只在令牌过期后生效
