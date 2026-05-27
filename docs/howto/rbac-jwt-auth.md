# How-to: RBAC + JWT Authentication

> **FT reference**: FT279 (`NENE2-FT/rbaclog`) — Role-Based Access Control with JWT: Argon2id password hashing with timing-attack protection, role claim in JWT, 401 vs 403 distinction, BearerTokenMiddleware with manual fallback, 14 tests / 48 assertions PASS.
>
> **VULN assessment**: V-01 through V-10 included at the end of this document.

This guide shows how to build a role-based access control (RBAC) system using JWT tokens with NENE2.

## Features

- Email + password login (Argon2id hashing)
- Role claim embedded in JWT (`user` / `admin`)
- Public, authenticated, and admin-only endpoints
- `BearerTokenMiddleware` with per-handler fallback
- Correct `401 Unauthorized` vs `403 Forbidden` semantics

## Schema

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

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/auth/login` | None | Login, receive JWT |
| `GET` | `/posts` | None | List all posts (public) |
| `POST` | `/posts` | User or Admin | Create a post |
| `DELETE` | `/posts/{id}` | Admin only | Delete a post |

## Login with Timing-Attack Protection

The dummy hash trick ensures login always takes the same time whether or not the email exists:

```php
$user = $this->users->findByEmail(trim($body['email']));

$dummyHash   = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';
$hashToCheck = $user !== null ? $user->passwordHash : $dummyHash;

if (!password_verify($body['password'], $hashToCheck) || $user === null) {
    return $this->problems->create($request, 'invalid-credentials', 'Invalid Credentials', 401, '...');
}
```

Without the dummy hash, a timing attack can detect valid email addresses by measuring response time — the hash computation is skipped for unknown emails.

## Role Claim in JWT

Role is stored in the JWT payload to avoid a DB round-trip on every request:

```php
$token = $this->issuer->issue([
    'sub'   => $user->id,
    'email' => $user->email,
    'role'  => $user->role->value,   // Role::User → 'user', Role::Admin → 'admin'
    'iat'   => $now,
    'exp'   => $now + self::TOKEN_TTL_SECONDS,
]);
```

## Role Check with Enum

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

`Role::tryFrom()` safely maps the string claim back to the enum — invalid role strings become `null`, which fails the check.

## 401 vs 403 Distinction

| Status | Meaning | When |
|--------|---------|------|
| `401 Unauthorized` | Not authenticated | No token, invalid token, expired token |
| `403 Forbidden` | Authenticated but insufficient role | Valid token, wrong role |

This distinction matters for clients: a `401` should prompt a re-login; a `403` should show an "access denied" message.

## BearerTokenMiddleware with Fallback

Some paths serve both public and protected methods (e.g., `GET /posts` is public, `POST /posts` is authenticated). The middleware excludes the path entirely, and handlers that require auth call `requireAuth()` manually:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $this->verifier,
    excludedPaths: ['/auth/login', '/posts'],  // /posts needs per-method handling
);
```

```php
private function requireAuth(ServerRequestInterface $request): array|ResponseInterface
{
    // Fast path: middleware already verified
    $claims = $request->getAttribute('nene2.auth.claims');
    if (is_array($claims)) {
        return $claims;
    }

    // Slow path: manual extraction for excluded paths
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

## VULN Assessment — Vulnerability Diagnosis

### V-01 — Role elevation via forged JWT claim 🛡️ SAFE

**Threat**: Attacker creates a JWT with `"role": "admin"` and signs it with a random secret.
**Defense**: `LocalBearerTokenVerifier` validates HMAC-HS256 signature against the server secret. A mismatched secret causes `TokenVerificationException` → 401.
**Result**: SAFE — signature verification prevents claim forgery.

---

### V-02 — Timing attack via email enumeration on login 🛡️ SAFE

**Threat**: Attacker sends login requests for unknown vs known emails and measures response time to enumerate valid accounts.
**Defense**: For unknown emails, `password_verify()` is called against a dummy Argon2id hash (same cost parameters). Both paths take ~200ms. Login failure message is identical for wrong email and wrong password.
**Result**: SAFE — timing is equalized; error message is generic.

---

### V-03 — Expired token accepted as valid 🛡️ SAFE

**Threat**: Attacker reuses a captured JWT after it expires.
**Defense**: `LocalBearerTokenVerifier` checks `exp` claim against `time()`. Expired tokens throw `TokenVerificationException` → 401.
**Result**: SAFE — `exp` check is enforced.

---

### V-04 — Role downgrade by modifying JWT payload (without re-signing) 🛡️ SAFE

**Threat**: Attacker base64-decodes the JWT payload, changes `"role": "user"` to `"role": "admin"`, re-encodes, and submits with original signature.
**Defense**: JWT signature covers the header + payload. Modifying the payload invalidates the signature → `TokenVerificationException` → 401.
**Result**: SAFE — payload tamper detected by HMAC.

---

### V-05 — Admin endpoint accessible with user role 🛡️ SAFE

**Threat**: Attacker logs in as a `user` and attempts `DELETE /posts/{id}`.
**Defense**: `requireRole($request, Role::Admin)` checks the JWT `role` claim. A `user` token has `role: 'user'` → `Role::tryFrom('user') !== Role::Admin` → 403.
**Result**: SAFE — 403 Forbidden returned; user token cannot elevate to admin.

---

### V-06 — Unauthenticated access to protected endpoint 🛡️ SAFE

**Threat**: Attacker sends `POST /posts` or `DELETE /posts/{id}` with no Authorization header.
**Defense**: `requireAuth()` checks for `Bearer ` prefix; absent header → 401 `unauthorized`.
**Result**: SAFE — 401 Unauthorized returned.

---

### V-07 — 401 vs 403 confusion (information leakage) 🛡️ SAFE

**Threat**: Incorrect 401/403 usage reveals whether a resource exists or whether the user is authenticated.
**Defense**: System returns 401 for unauthenticated access (no/invalid token) and 403 for authenticated access with insufficient role. The distinction is semantically correct and does not reveal resource existence beyond the role requirement.
**Result**: SAFE — 401/403 semantics are correct; tests `test401MeansNotAuthenticated` and `test403MeansAuthenticatedButForbidden` both pass.

---

### V-08 — Invalid role string in JWT bypass 🛡️ SAFE

**Threat**: Attacker crafts a JWT (with valid secret, e.g., compromised secret scenario) and sets `role` to an unknown value like `"superadmin"`.
**Defense**: `Role::tryFrom((string) ($claims['role'] ?? ''))` returns `null` for unknown strings → `null !== Role::Admin` → 403.
**Result**: SAFE — `tryFrom()` is null-safe; unknown roles are treated as insufficient.

---

### V-09 — SQL injection via email field on login 🛡️ SAFE

**Threat**: Attacker sends `{"email": "' OR '1'='1", "password": "anything"}`.
**Defense**: `findByEmail()` uses parameterized query (`WHERE email = ?`). The injected string is treated as a literal value, not SQL.
**Result**: SAFE — parameterized queries prevent SQL injection.

---

### V-10 — Password stored in plaintext 🛡️ SAFE

**Threat**: If the DB is breached, passwords are readable.
**Defense**: `password_hash($password, PASSWORD_ARGON2ID)` with cost parameters `m=65536,t=4,p=1`. Only the Argon2id hash is stored; the plaintext password is never persisted.
**Result**: SAFE — Argon2id is the current recommended algorithm (RFC 9106); PBKDF2/bcrypt/scrypt would also pass.

---

### VULN Summary

| ID | Threat | Result |
|----|--------|--------|
| V-01 | Role elevation via forged JWT | 🛡️ SAFE |
| V-02 | Timing attack via email enumeration | 🛡️ SAFE |
| V-03 | Expired token accepted | 🛡️ SAFE |
| V-04 | JWT payload tamper without re-signing | 🛡️ SAFE |
| V-05 | Admin endpoint with user role token | 🛡️ SAFE |
| V-06 | Unauthenticated access to protected endpoint | 🛡️ SAFE |
| V-07 | 401 vs 403 confusion | 🛡️ SAFE |
| V-08 | Unknown role string bypass | 🛡️ SAFE |
| V-09 | SQL injection via email field | 🛡️ SAFE |
| V-10 | Password stored in plaintext | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
Argon2id hashing, HMAC-signed JWT, `Role::tryFrom()` guard, and parameterized queries prevent all tested vulnerability vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store role in DB and look up on every request | Extra DB query per request; role change requires token revocation logic |
| Use `Role::from()` instead of `Role::tryFrom()` | Unknown role strings throw `ValueError` — 500 instead of 403 |
| Return 403 for unauthenticated requests | Misleads clients — 403 should mean "authenticated but forbidden," not "not logged in" |
| Return 401 for wrong-role access | Client may attempt re-login instead of showing "access denied" |
| Skip dummy hash in login | Timing attack reveals valid email addresses |
| Store passwords as MD5/SHA1/plaintext | Brute-force or rainbow table attacks expose all passwords in a DB breach |
| Embed permissions in JWT (not roles) | Permission set changes require token reissuance; roles are stable, permissions change |
| Allow `alg: none` JWT | Attacker can forge tokens by removing signature entirely |
| Use `str_contains($role, 'admin')` instead of enum check | `"not-admin"` or `"superadmin"` might match unexpectedly |
