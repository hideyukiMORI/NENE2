# How-To: JWT Authentication

Issuing and verifying JWT Bearer tokens with `LocalBearerTokenVerifier` and `BearerTokenMiddleware`.

---

## Quick start

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret   = getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not set');
$verifier = new LocalBearerTokenVerifier($secret);

// Protect all paths except /auth/login
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login'],
);

$app = (new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware, ...))->create();
```

---

## Issuing tokens

`LocalBearerTokenVerifier` implements both `TokenIssuerInterface` and `TokenVerifierInterface` — one instance handles both.

```php
$now   = time();
$token = $verifier->issue([
    'sub'   => $user->id,       // subject: user identifier (int or string)
    'email' => $user->email,    // custom claim
    'iat'   => $now,            // issued-at (Unix timestamp — int)
    'exp'   => $now + 3600,     // expiry   (Unix timestamp — int, required for expiry to work)
]);
```

**`exp` must be a Unix timestamp (int).** Passing a date string (`'2026-06-01'`) silently skips expiry enforcement because `LocalBearerTokenVerifier` checks `is_int($claims['exp'])` before comparing.

---

## Reading claims in a handler

`BearerTokenMiddleware` stores decoded claims in the `nene2.auth.claims` request attribute after successful verification:

```php
private function me(ServerRequestInterface $request): ResponseInterface
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    // This null-guard should not trigger — the middleware already rejected missing tokens.
    // Include it anyway for PHPStan level 8 and defensive clarity.
    if (!is_array($claims)) {
        return $this->problems->create($request, 'unauthorized', 'Unauthorized', 401);
    }

    return $this->json->create([
        'id'    => $claims['sub'],
        'email' => $claims['email'],
    ]);
}
```

Also available: `$request->getAttribute('nene2.auth.credential_type')` returns `'bearer'`.

---

## Path protection modes

`BearerTokenMiddleware` supports three modes — the first non-empty configuration wins:

| Configuration | Behaviour | When to use |
|---|---|---|
| `protectedPaths: ['/me', '/admin']` | Only listed exact paths are protected | Public paths are the majority |
| `protectedPathPrefixes: ['/api/']` | Paths starting with prefix are protected | Protecting a whole sub-tree |
| `excludedPaths: ['/login', '/register']` | All paths except listed ones are protected | Public paths are the minority |
| (default — all arrays empty) | Every path is protected | Fully private API |

```php
// ✅ /auth/login is public, everything else requires a token
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login']);

// ✅ Only /auth/me is protected
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/auth/me']);

// ✅ All /api/ paths are protected
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/']);

// ⚠️  protectedPaths: [] is NOT "protect nothing" — it disables allowlist mode
//     and falls through to the next mode (prefixes, then blocklist, then protect-all).
```

---

## `alg: none` attack — already rejected

`LocalBearerTokenVerifier` checks that `alg == 'HS256'` in the token header before verifying the signature. Any other algorithm — including `none` — throws `TokenVerificationException`:

```
Token algorithm must be HS256.
```

This prevents the classic `alg: none` bypass where an attacker crafts a header-less token with no signature. When implementing a custom verifier, always enforce the expected algorithm explicitly.

---

## Error responses

`BearerTokenMiddleware` returns 401 Problem Details and automatically adds the `WWW-Authenticate` header (RFC 6750):

```
WWW-Authenticate: Bearer realm="NENE2", error="missing_token", error_description="No Bearer token was provided."
```

Possible `error` values: `missing_token` (no header), `invalid_token` (bad scheme, bad signature, expired, `nbf` in future, malformed).

---

## Secret management

Never hardcode the JWT secret. Read it from an environment variable:

```php
// ❌ Hardcoded secret — committed to version control
$verifier = new LocalBearerTokenVerifier('my-secret');

// ✅ Environment variable
$secret   = (string) (getenv('NENE2_LOCAL_JWT_SECRET') ?: throw new \RuntimeException('JWT secret not configured'));
$verifier = new LocalBearerTokenVerifier($secret);
```

Use a strong random secret in all environments. For production, use a library-backed implementation (`firebase/php-jwt`, `lcobucci/jwt`) instead of `LocalBearerTokenVerifier` — the "Local" prefix signals its scope.

---

## Token revocation

JWT is stateless — there is no built-in revocation. Tokens remain valid until `exp`. If you need immediate revocation (e.g., logout, password change):

- Store a token blocklist in Redis with TTL matching `exp`
- Or use short-lived tokens (15 minutes) with refresh tokens

---

## `authMiddleware` parameter name

The `RuntimeApplicationFactory` named parameter is `authMiddleware:`, not `middlewares:` or `middleware:`:

```php
// ❌ Unknown named parameter $middlewares
new RuntimeApplicationFactory($psr17, $psr17, middlewares: [$authMiddleware]);

// ✅ Correct
new RuntimeApplicationFactory($psr17, $psr17, authMiddleware: $authMiddleware);
```

---

## Code review checklist

- [ ] `exp` claim is a Unix timestamp (int), not a date string
- [ ] JWT secret is read from an environment variable (not hardcoded)
- [ ] `LocalBearerTokenVerifier` is not used in production (use a library implementation)
- [ ] `nene2.auth.claims` attribute is null-checked before use
- [ ] `excludedPaths` / `protectedPaths` mode choice matches intent
- [ ] Token response does not contain `password_hash` or other secrets
- [ ] `Authorization` header is not logged
- [ ] 401 is returned for auth failures (not 404)
