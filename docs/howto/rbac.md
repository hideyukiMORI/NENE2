# How-To: Role-Based Access Control (RBAC)

Implementing role-based access control with JWT claims and `BearerTokenMiddleware`.

---

## Quick start

```php
// 1. Include role in the JWT on login
$token = $issuer->issue([
    'sub'  => $user->id,
    'role' => $user->role->value,  // 'user' or 'admin'
    'exp'  => time() + 3600,
]);

// 2. Check the role in the handler
/** @var array<string, mixed>|null $claims */
$claims     = $request->getAttribute('nene2.auth.claims');
$actualRole = Role::tryFrom((string) ($claims['role'] ?? ''));

if ($actualRole !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## Embedding roles in JWT claims

**Two approaches:**

| Approach | Pro | Con |
|---|---|---|
| Role in JWT claims | No DB query per request | Role changes take effect only after token expires |
| DB lookup per request | Immediate role changes | Extra query on every authenticated request |

For most applications the JWT approach is appropriate. For high-security contexts (medical, financial, admin privilege revocation) add a DB lookup on sensitive operations.

```php
// Login — embed role in claims
$token = $issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // string: 'user' | 'admin'
    'iat'   => time(),
    'exp'   => time() + 3600,
]);
```

---

## 401 Unauthorized vs 403 Forbidden

This distinction matters for client error handling (401 → redirect to login, 403 → show permission error):

| Situation | Status |
|---|---|
| No token / expired / invalid signature | **401** Unauthorized |
| Valid token but insufficient role | **403** Forbidden |
| Resource not found | **404** Not Found |

```php
// ❌ Wrong — authenticated user gets 401 (implies "not logged in")
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401, '...');
}

// ✅ Correct — authenticated but lacks permission
if ($role !== Role::Admin) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403,
        "This action requires the 'admin' role.");
}
```

---

## `requireAuth()` / `requireRole()` pattern

A reusable helper pair in the route registrar:

```php
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

final class RouteRegistrar
{
    public function __construct(
        private readonly TokenVerifierInterface $verifier,
        // ... other deps
    ) {}

    /**
     * Returns claims on success or a 401 ResponseInterface.
     * Checks middleware attribute first; falls back to manual verification
     * for paths excluded from BearerTokenMiddleware.
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
     * Returns claims if the user has the required role, or a 401/403 ResponseInterface.
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

Usage in handlers:

```php
private function deletePost(ServerRequestInterface $request): ResponseInterface
{
    $claims = $this->requireRole($request, Role::Admin);
    if ($claims instanceof ResponseInterface) {
        return $claims;  // 401 or 403
    }
    // $claims is now a verified admin's JWT payload
}
```

---

## `BearerTokenMiddleware` does not differentiate by HTTP method

`BearerTokenMiddleware` uses the request path, not the HTTP method, to decide whether authentication is required. When `GET /posts` (public) and `POST /posts` (auth-required) share the same path, exclude `/posts` from the middleware and verify the token manually in the handler:

```php
// Middleware: exclude /posts entirely (covers both GET and POST)
$auth = new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/posts']);

// For DELETE /posts/{id} (path /posts/1, /posts/2 etc.) — NOT in excludedPaths → middleware protects it.
// For POST /posts (path /posts) — excluded → handler must call requireAuth() manually.
```

The `requireAuth()` helper above handles this transparently: it reads `nene2.auth.claims` from the middleware attribute if present, and falls back to parsing the `Authorization` header directly if not.

**Alternative**: use distinct path prefixes to avoid the ambiguity entirely:
- `GET /public/posts` — no auth
- `POST /posts` — auth required (middleware can protect `/posts` without conflict)

---

## `Role` enum pattern

Use a backed enum for type-safe role handling:

```php
enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}

// ❌ Role::from() throws on unknown values
$role = Role::from($claims['role']);  // UnhandledMatchError if 'superuser' or ''

// ✅ Role::tryFrom() returns null for unknown values
$role = Role::tryFrom((string) ($claims['role'] ?? ''));
if ($role === null || $role !== Role::Admin) {
    return 403;
}
```

---

## 204 No Content — use `createEmpty()`

`JsonResponseFactory::create()` requires an `array` argument. For 204 responses with no body, use `createEmpty()`:

```php
// ❌ Type error — create() does not accept null
return $this->json->create(null, 204);

// ❌ Returns empty JSON object {} (body should be absent for 204)
return $this->json->create([], 204);

// ✅ Correct — no body, correct status
return $this->json->createEmpty(204);
```

---

## Code review checklist

- [ ] `role` claim is decoded with `Role::tryFrom()` (not `Role::from()` — throws on unknown values)
- [ ] 403 is returned for insufficient permissions, 401 for unauthenticated (not both as 401)
- [ ] `requireRole()` also calls `requireAuth()` — no duplicate auth checks needed
- [ ] `BearerTokenMiddleware` exclusion is understood: excluded paths bypass claims attribute
- [ ] Handlers on excluded paths call `requireAuth()` with manual token verification
- [ ] 204 responses use `createEmpty(204)` not `create(null, 204)`
- [ ] JWT role caching is understood: role changes take effect only after token expiry
