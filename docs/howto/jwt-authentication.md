# How-To: JWT Authentication

> **FT reference**: FT261 (`NENE2-FT/jwtlog`) — JWT Authentication with Argon2id password hashing and BearerTokenMiddleware
> **VULN**: FT261 — vulnerability assessment (V-01 through V-10)

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

---

## Timing attack protection: dummy hash for user enumeration

When an email is not found, `$user === null`. Without a dummy hash, the code would skip
`password_verify()` entirely — making the response noticeably faster for unknown emails.

```php
$user = $this->repo->findByEmail(trim($body['email']));

// Always run password_verify — prevents timing-based user enumeration.
$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

// ⚠️  Order matters: password_verify() BEFORE || $user === null
// Short-circuit evaluation would skip password_verify() if $user were checked first.
if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return 401;  // same error regardless of whether email is unknown or password is wrong
}
```

---

## VULN — Vulnerability assessment (FT261)

### V-01 — No brute-force protection on login

**Risk**: `POST /auth/login` has no rate limiting.

**Impact**: An attacker can submit unlimited login attempts. Argon2id is intentionally slow (~100ms),
but without rate limiting, distributed requests can still try thousands of passwords.

**Verdict**: **EXPOSED** — add `ThrottleMiddleware` on `POST /auth/login` (e.g. 5 req/min/IP).
Return 429 with `Retry-After`.

---

### V-02 — JWT secret strength is environment-dependent

**Risk**: If `NENE2_LOCAL_JWT_SECRET` is empty or weak (`secret`, `test`), HMAC-HS256 tokens
can be brute-forced or guessed. A forged token with admin claims would be accepted.

**Verdict**: **EXPOSED** — fail-closed startup check:
```php
if (strlen($jwtSecret) < 32) {
    throw new \RuntimeException('NENE2_LOCAL_JWT_SECRET must be at least 32 random bytes.');
}
```

---

### V-03 — No token revocation

**Risk**: Issued JWTs remain valid until `exp`. Stolen tokens, or tokens belonging to deleted
users, remain accepted for up to 1 hour.

**Verdict**: **EXPOSED** — implement a token blocklist (e.g., `revoked_tokens(jti TEXT PK, revoked_at TEXT)`)
or use short-lived tokens (15 min) with refresh tokens.

---

### V-04 — No user registration endpoint

**Risk**: No `POST /auth/register` route exists. Test users require direct DB insertion, bypassing
the password hashing policy enforced by the application.

**Verdict**: **DESIGN GAP** — add `POST /auth/register` with email validation and Argon2id hashing.

---

### V-05 — Email case sensitivity: no normalization

**Risk**: `WHERE email = ?` is case-sensitive. `USER@EXAMPLE.COM` and `user@example.com` are
different lookups. Two accounts with different cases can co-exist.

**Verdict**: **EXPOSED** — normalize email to lowercase (`strtolower()`) at registration and login.

---

### V-06 — Token TTL: 1 hour may be too long for sensitive APIs

**Risk**: `TOKEN_TTL_SECONDS = 3600`. Stolen tokens stay valid for up to an hour.

**Verdict**: **DESIGN CONSIDERATION** — 1 hour is acceptable for most APIs. For sensitive operations,
use shorter TTLs (5–15 min) with refresh tokens. Make TTL configurable.

---

### V-07 — `password_hash` is not in JWT claims

**Risk**: The `issue()` call only includes `sub`, `email`, `iat`, `exp`.

**Verdict**: **SAFE** — claims are minimal. Even if a token is decoded (base64, not encrypted),
no sensitive internal data is exposed.

---

### V-08 — SQL injection via email

**Attack**: `{"email": "' OR '1'='1", "password": "x"}`

**Observed**: `WHERE email = ?` is a parameterized query. The injection is treated as a literal
string. No user is found; 401 is returned.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection.

---

### V-09 — No email format validation

**Risk**: Any non-empty string is accepted as email (e.g., `"not-an-email"`).

**Impact**: Wasted Argon2id computation; invalid users in DB; broken password reset flows.

**Verdict**: **EXPOSED** — add `filter_var($email, FILTER_VALIDATE_EMAIL)` at registration and login.

---

### V-10 — No HTTPS enforcement

**Risk**: JWT tokens and passwords are transmitted in plaintext over HTTP.

**Verdict**: **EXPOSED** — enforce HTTPS in production. Add `Strict-Transport-Security` header via
`SecurityHeadersMiddleware`.

---

## VULN summary

| # | Vulnerability | Verdict |
|---|---------------|---------|
| V-01 | No brute-force protection | EXPOSED |
| V-02 | JWT secret strength (env-dependent) | EXPOSED |
| V-03 | No token revocation | EXPOSED |
| V-04 | No registration endpoint | DESIGN GAP |
| V-05 | Email case sensitivity / no normalization | EXPOSED |
| V-06 | Token TTL 1 hour | DESIGN CONSIDERATION |
| V-07 | password_hash not in JWT claims | SAFE |
| V-08 | SQL injection via email | BLOCKED |
| V-09 | No email format validation | EXPOSED |
| V-10 | No HTTPS enforcement | EXPOSED |

**Critical fixes before production**:
1. **V-01** — `ThrottleMiddleware` on `POST /auth/login` (5 req/min/IP)
2. **V-02** — Fail-closed JWT secret validation at startup (`strlen >= 32`)
3. **V-03** — Token revocation list or short TTL + refresh tokens
4. **V-05** — Normalize email to lowercase at registration and login
5. **V-09** — `filter_var($email, FILTER_VALIDATE_EMAIL)` at registration

---

## Related howtos

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — brute-force lockout for PIN verification
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — rate limiting middleware
- [`webhook-signature-verification.md`](webhook-signature-verification.md) — HMAC-SHA256 + timing-safe comparison
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explicit DTO whitelisting
