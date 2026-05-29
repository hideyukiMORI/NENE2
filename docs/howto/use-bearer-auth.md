---
title: "How to use Bearer token authentication"
category: auth
tags: [bearer, jwt, middleware, authentication]
difficulty: beginner
related: [add-jwt-authentication, bearer-token-middleware, jwt-authentication]
---

# How to use Bearer token authentication

NENE2 ships `BearerTokenMiddleware` and `LocalBearerTokenVerifier` for JWT-based authentication. This guide covers setup, configuration, token issuance, and common pitfalls.

## Setup

Wire the middleware into `RuntimeApplicationFactory` using the `authMiddleware` named parameter:

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
    authMiddleware:  $bearer, // ← named parameter is authMiddleware, not middlewares
))->create();
```

> **Note:** The parameter name is `authMiddleware`, not `middlewares`. Using `middlewares:` causes a runtime `Error: Unknown named parameter`.

## Protect all routes vs. selective protection

`BearerTokenMiddleware` supports four path-matching modes (first match wins):

```php
// 1. Protect only specific paths (allowlist)
new BearerTokenMiddleware($problems, $verifier, protectedPaths: ['/me', '/entries']);

// 2. Protect paths starting with a prefix (prefix allowlist)
new BearerTokenMiddleware($problems, $verifier, protectedPathPrefixes: ['/api/', '/me/']);

// 3. Protect everything EXCEPT listed paths (blocklist — common pattern)
new BearerTokenMiddleware($problems, $verifier, excludedPaths: ['/auth/login', '/auth/register', '/health']);

// 4. Protect all paths (default — no arrays given)
new BearerTokenMiddleware($problems, $verifier);
```

## Reading claims in a handler

After successful verification, claims are stored as a request attribute:

```php
/** @var array<string, mixed> $claims */
$claims  = $request->getAttribute('nene2.auth.claims') ?? [];
$ownerId = (string) ($claims['sub'] ?? '');
```

The credential type is stored separately:

```php
$credType = $request->getAttribute('nene2.auth.credential_type'); // 'bearer'
```

## Issuing tokens (local / test)

`LocalBearerTokenVerifier` also implements `TokenIssuerInterface`:

```php
$token = $verifier->issue([
    'sub' => 'user-123',
    'iat' => time(),
    'exp' => time() + 3600, // ← always include exp
]);
```

> **Always include `exp`.** Tokens without `exp` are treated as non-expiring. This is safe for tests but dangerous if such tokens reach production. If `exp` is absent, the verifier skips the expiry check.

## Error responses

On failure, `BearerTokenMiddleware` returns a `401 Unauthorized` Problem Details response with a `WWW-Authenticate` header:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="NENE2", error="invalid_token", error_description="Token has expired."
Content-Type: application/problem+json
```

Error codes in `WWW-Authenticate`:
- `missing_token` — no `Authorization` header
- `invalid_token` — bad scheme, expired, invalid signature, malformed, wrong algorithm, `nbf` in future

## Security properties of `LocalBearerTokenVerifier`

| Threat | Protection |
|--------|-----------|
| Signature forgery | HMAC-HS256, constant-time `hash_equals` |
| Algorithm substitution (`alg:none`) | Only `HS256` accepted |
| Expired token | `exp` claim checked |
| Not-yet-valid token | `nbf` claim checked |
| Tampered payload | Signature covers header + payload; tampering breaks signature |

> `LocalBearerTokenVerifier` is designed for local development and testing. For production, inject a library-backed implementation of `TokenVerifierInterface` (e.g., firebase/php-jwt) that supports key rotation and asymmetric algorithms.

## Testing patterns

```php
// In setUp(): create verifier with a test secret
$this->verifier = new LocalBearerTokenVerifier('test-secret');

// Issue a valid token for a user
$token = $this->verifier->issue(['sub' => 'alice', 'exp' => time() + 3600]);

// Issue an expired token (for negative test)
$expired = $this->verifier->issue(['sub' => 'alice', 'exp' => time() - 1]);

// Issue a not-yet-valid token
$future = $this->verifier->issue(['sub' => 'alice', 'nbf' => time() + 3600, 'exp' => time() + 7200]);
```
