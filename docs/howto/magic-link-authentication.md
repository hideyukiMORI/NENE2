---
title: "How-to: Magic Link Authentication"
category: auth
tags: [magic-link, passwordless, token, ttl]
difficulty: intermediate
related: [passwordless-auth-magic-link, otp-authentication, session-management]
ft: FT309
---

# How-to: Magic Link Authentication

> **FT reference**: FT309 (`NENE2-FT/magiclog`) — Magic link authentication: token stored as SHA-256 hash (never plaintext), 15-minute TTL, used-at prevents reuse, expiry checked before used-at, session token 64+ hex chars SHA-256 stored, revoked/expired sessions denied, 202 always on /auth/request (user enumeration prevention), Bearer token required (X-User-Id header ignored), VULN-A〜L all SAFE, 43 tests / 91 assertions PASS.

This guide shows how to build a passwordless magic-link authentication system where security depends on token entropy, hash storage, short TTL, and one-time use enforcement.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at TEXT    NOT NULL,          -- now + 15 minutes
    used_at    TEXT,                      -- set on first successful verify
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE auth_sessions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    session_token_hash TEXT    NOT NULL UNIQUE,   -- SHA-256(raw_token)
    expires_at         TEXT    NOT NULL,
    revoked_at         TEXT,
    created_at         TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Both `magic_links.token_hash` and `auth_sessions.session_token_hash` store SHA-256 hashes. Raw tokens are never stored.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/auth/request` | — | Request magic link (always 202) |
| `POST` | `/auth/verify` | — | Verify token → session |
| `POST` | `/auth/logout` | `Bearer` | Revoke session |
| `GET` | `/me` | `Bearer` | Get current user |

## Token Generation and Hashing

```php
// Generate 64 hex chars (256-bit entropy)
$rawToken   = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $rawToken);
$expiresAt  = (new \DateTimeImmutable())->modify('+15 minutes')->format('c');

$this->repo->createMagicLink($userId, $tokenHash, $expiresAt);

// Return raw token to caller (sent via email to the user)
return ['token' => $rawToken];
```

The raw token is returned in the response (to be sent as a URL parameter in the email). Only the SHA-256 hash is stored. `UNIQUE(token_hash)` prevents hash collisions.

## Session Token

```php
$rawSessionToken  = bin2hex(random_bytes(32)); // 64 hex chars
$sessionTokenHash = hash('sha256', $rawSessionToken);
$sessionExpiry    = (new \DateTimeImmutable())->modify('+24 hours')->format('c');

$this->repo->createSession($userId, $sessionTokenHash, $sessionExpiry);

return ['session_token' => $rawSessionToken]; // returned once, then hash-only
```

Session token: 64 hex characters = 256 bits of entropy. Stored as SHA-256 hash. Minimum 64 chars enforced by the entropy source (`bin2hex(random_bytes(32))`).

## Verify — Order of Checks Matters

```php
// 1. Find by hash
$magicLink = $this->repo->findByTokenHash(hash('sha256', $token));
if ($magicLink === null) {
    return 401; // not found
}

// 2. Check expiry FIRST
if ($magicLink['expires_at'] < date('c')) {
    return 401; // 'expired' in error message
}

// 3. Check used_at SECOND
if ($magicLink['used_at'] !== null) {
    return 401; // 'already been used' in error message
}

// 4. Mark as used
$this->repo->markUsed($magicLink['id'], date('c'));

// 5. Create session
```

Expiry is checked **before** `used_at`. If a token is both expired and used, the error says "expired" — not "already been used". This prevents timing attacks where an attacker probes whether a token was used.

## User Enumeration Prevention — Always 202

```php
public function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $email = $body['email'] ?? '';
    
    $user = $this->repo->findUserByEmail($email);
    if ($user !== null) {
        // Create magic link and (in production) send email
        $rawToken = bin2hex(random_bytes(32));
        $this->repo->createMagicLink($user['id'], hash('sha256', $rawToken), ...);
    }
    // Always return 202 regardless of whether email exists
    return $this->json->create(['message' => 'If registered, a magic link has been sent.'], 202);
}
```

Non-existent email addresses return the same 202 response as valid ones. No "email not found" message is ever returned.

## Session Validation

```php
$token = substr($authHeader, 7); // strip 'Bearer '
$tokenHash = hash('sha256', $token);
$session = $this->repo->findSessionByHash($tokenHash);

if ($session === null) {
    return 401; // session not found
}
if ($session['revoked_at'] !== null) {
    return 401; // 'revoked'
}
if ($session['expires_at'] < date('c')) {
    return 401; // 'expired'
}
```

Three session checks: existence → revoked → expired. Revoked sessions from logout return "revoked" — distinct from "expired" for clear error messages.

## X-User-Id Header Ignored for Auth

The `/me` endpoint requires a valid `Bearer` session token. The `X-User-Id` header (used by other endpoints as a convenience auth) is explicitly ignored here:

```php
// Only Bearer token authentication — X-User-Id is not accepted
$authHeader = $request->getHeaderLine('Authorization');
if (!str_starts_with($authHeader, 'Bearer ')) {
    return 401;
}
```

---

## Vulnerability Assessment

### V-01 — Expired token rejected before used check ✅ SAFE

**Risk**: Token expired but not yet used; attacker tries to use it and gets "already used" error revealing order of checks.
**Finding**: SAFE — expiry is checked first. Both expired+used tokens return "expired".

---

### V-02 — Session token stored as hash ✅ SAFE

**Risk**: DB breach reveals session tokens.
**Finding**: SAFE — `session_token_hash = SHA-256(raw_token)`. Raw token not in DB.

---

### V-03 — Used magic link cannot be reused ✅ SAFE

**Risk**: Attacker captures the magic link URL and uses it after the intended user.
**Finding**: SAFE — `used_at` is set on first use; second attempt returns 401 "already been used".

---

### V-04 — Logout invalidates session ✅ SAFE

**Risk**: Session cookie/token still works after logout.
**Finding**: SAFE — logout sets `revoked_at`; subsequent `/me` with the token returns 401 "revoked".

---

### V-05 — Non-existent email returns 202 ✅ SAFE

**Risk**: Attacker checks which emails are registered by observing different error responses.
**Finding**: SAFE — `/auth/request` always returns 202 with the same body. No "not found" leak.

---

### V-06 — Revoked session denied ✅ SAFE

**Risk**: Manually revoked session still grants access.
**Finding**: SAFE — `revoked_at` check denies access; error message says "revoked".

---

### V-07 — Expired session denied ✅ SAFE

**Risk**: Old session from a long time ago still works.
**Finding**: SAFE — `expires_at` check denies access; error message says "expired".

---

### V-08 — Magic link token stored as hash ✅ SAFE

**Risk**: DB breach reveals magic link tokens; attacker authenticates as any user.
**Finding**: SAFE — `token_hash = SHA-256(raw_token)`. Raw token not in DB.

---

### V-09 — Magic link expires within 15 minutes ✅ SAFE

**Risk**: Long-lived magic link allows delayed interception and replay.
**Finding**: SAFE — TTL ≤ 900 seconds (15 minutes) confirmed by test.

---

### V-10 — Session has expiry ✅ SAFE

**Risk**: Session never expires; old tokens remain valid forever.
**Finding**: SAFE — `expires_at` is set in the future at session creation; confirmed non-null.

---

### V-11 — Session token has sufficient entropy ✅ SAFE

**Risk**: Short session token brute-forceable.
**Finding**: SAFE — `bin2hex(random_bytes(32))` = 64 hex chars = 256-bit entropy.

---

### V-12 — X-User-Id header cannot bypass auth ✅ SAFE

**Risk**: `X-User-Id: 1` header grants access to `/me` without a valid session.
**Finding**: SAFE — `/me` requires `Authorization: Bearer <token>`. X-User-Id is ignored.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Expiry vs used check order | ✅ SAFE |
| V-02 | Session token in plaintext DB | ✅ SAFE |
| V-03 | Used magic link reuse | ✅ SAFE |
| V-04 | Session valid after logout | ✅ SAFE |
| V-05 | Email enumeration | ✅ SAFE |
| V-06 | Revoked session access | ✅ SAFE |
| V-07 | Expired session access | ✅ SAFE |
| V-08 | Magic link token in DB | ✅ SAFE |
| V-09 | Magic link TTL > 15 min | ✅ SAFE |
| V-10 | No session expiry | ✅ SAFE |
| V-11 | Low entropy session token | ✅ SAFE |
| V-12 | X-User-Id bypass | ✅ SAFE |

**12 SAFE, 0 EXPOSED**

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw magic link token in DB | DB breach lets attacker authenticate as any user |
| Store raw session token in DB | DB breach invalidates all sessions |
| Check used_at before expires_at | Timing leak reveals whether token was used |
| Return error for non-existent email | Attacker enumerates registered emails |
| No TTL on magic links | Indefinitely valid tokens; delayed interception attack |
| No session expiry | Sessions remain valid forever |
| Accept X-User-Id for bearer auth | Header-based auth bypass without token |
| Low entropy tokens (`rand()` or 8-char) | Brute-forceable tokens |
| Reuse same magic link for multiple sessions | Single token exposure grants all subsequent sessions |
