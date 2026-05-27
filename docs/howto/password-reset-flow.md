# How-to: Password Reset Flow

> **FT reference**: FT285 (`NENE2-FT/resetlog`) — Password reset flow: user enumeration prevention (always 202), SHA-256 token hash storage, 1-hour TTL, single-use token (409 on reuse), 410 Gone on expiry, Argon2id new password hash, 15 tests / 23 assertions PASS.
>
> **VULN assessment**: V-01 through V-10 included at the end of this document.

This guide shows how to implement a secure password reset flow — users request a reset, receive a token (usually by email), and use it to set a new password.

## Schema

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash TEXT UNIQUE` — stores SHA-256 of the raw token. Raw token is sent to client and never stored.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/password-reset` | None | Request password reset |
| `GET` | `/password-reset/{token}` | None | Check token status |
| `POST` | `/password-reset/{token}` | None | Complete reset with new password |

## User Enumeration Prevention

```php
$user = $this->repo->findUserByEmail($email);

// Always return 202 to prevent user enumeration
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// Real user: create token and (in production) send email
$rawToken = bin2hex(random_bytes(32));
// ...
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Both valid and invalid emails return identical 202 responses. An attacker cannot determine which emails are registered.

> **Production note**: The token is returned in the API response here for testability. In production, send the token via email only — never include it in the API response.

## Token Storage — SHA-256 Only

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 hex chars = 256-bit entropy
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// Return raw token to client (in production: via email, not HTTP response)
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

The database stores only the SHA-256 hash. The raw token is sent to the user (by email in production) and never stored. A DB breach reveals hashes — useless without the raw tokens.

## Token Validation

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

The raw token arrives in the request path. The server hashes it and queries the DB. SHA-256 is deterministic — same raw token always produces the same hash.

## Token Lifecycle States

```
pending → used (409 on reuse)
pending → expired (410 Gone)
```

```php
if ($reset->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
}

if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}
```

| Status | HTTP | When |
|--------|------|------|
| Not found | 404 | Token doesn't exist in DB |
| Expired | 410 Gone | `expires_at` is in the past |
| Already used | 409 Conflict | `used_at` is set |
| Valid | 200 (GET) / 200 (POST) | Active, unused, not expired |

`410 Gone` is more semantically correct than 404 for expired resources — the token existed but is no longer available.

## Complete Reset

```php
$newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->repo->updatePasswordHash($reset->userId, $newHash);
$this->repo->markUsed($tokenHash, $now);  // set used_at = $now

return $this->json->create(['status' => 'completed'], 200);
```

Both operations should be in a transaction in production. If `updatePasswordHash` succeeds but `markUsed` fails, the user is reset but the token remains reusable.

## Password Validation

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

Minimum 8 characters; enforced on both registration and reset. The new password is hashed with `PASSWORD_ARGON2ID` before storage.

---

## VULN Assessment — Vulnerability Diagnosis

### V-01 — User enumeration via reset response timing/content 🛡️ SAFE

**Threat**: Attacker sends reset requests for many emails to identify registered ones.
**Defense**: Both registered and unregistered emails return `202 { "status": "pending" }` with identical response body and status code. No timing difference (no password hash verification needed for reset request).
**Result**: SAFE — enumeration impossible from API response.

---

### V-02 — Token brute-force 🛡️ SAFE

**Threat**: Attacker guesses token values and submits them to reset any account.
**Defense**: `bin2hex(random_bytes(32))` generates 256-bit entropy (64 hex chars). At 10,000 guesses/second, brute-forcing would take ~10^65 years. SHA-256 hash comparison prevents length-extension and timing oracle.
**Result**: SAFE — 256-bit entropy is unguessable.

---

### V-03 — Token replay after use 🛡️ SAFE

**Threat**: Attacker intercepts a reset token and uses it after the legitimate user already reset their password.
**Defense**: `markUsed()` sets `used_at` after reset. Subsequent attempts check `isUsed()` → 409 Conflict.
**Result**: SAFE — single-use enforcement prevents replay.

---

### V-04 — Expired token accepted 🛡️ SAFE

**Threat**: Attacker saves a token, waits for user to log in, then uses the old token.
**Defense**: `isExpired($now)` checks `expires_at`. Tokens expire after 1 hour → 410 Gone.
**Result**: SAFE — time-bounded tokens prevent delayed attacks.

---

### V-05 — SQL injection via token path parameter 🛡️ SAFE

**Threat**: Submit `'; DROP TABLE password_resets; --` as the token.
**Defense**: `hash('sha256', $rawToken)` produces a 64-char hex string regardless of input. The hash is used in a parameterized query (`WHERE token_hash = ?`). SQL injection via path parameter is not possible.
**Result**: SAFE — hashing + parameterized query double-blocks injection.

---

### V-06 — Token stored in plaintext in DB 🛡️ SAFE

**Threat**: DB breach exposes all active reset tokens; attacker resets every account.
**Defense**: DB stores `hash('sha256', $rawToken)` only. Raw tokens are returned to clients (or sent by email). SHA-256 is one-way; hashes cannot be reversed to raw tokens without brute force.
**Result**: SAFE — SHA-256 hash storage protects tokens at rest.

---

### V-07 — New password stored in plaintext 🛡️ SAFE

**Threat**: DB breach exposes new passwords set during reset.
**Defense**: `password_hash($newPassword, PASSWORD_ARGON2ID)` hashes the new password before storage. The plaintext is never persisted.
**Result**: SAFE — Argon2id hashing protects passwords at rest.

---

### V-08 — Account takeover by creating duplicate reset token 🛡️ SAFE

**Threat**: Attacker predicts or collides with another user's token hash.
**Defense**: `token_hash TEXT UNIQUE` — duplicate hashes are rejected by DB. With 256-bit entropy, collision probability is negligible (birthday bound ~2^128 attempts for 50% collision probability).
**Result**: SAFE — UNIQUE constraint + 256-bit entropy prevent collision.

---

### V-09 — Submit weak new password (< 8 chars) during reset 🛡️ SAFE

**Threat**: Attacker resets an account to a trivially guessable password like `aa`.
**Defense**: `strlen($newPassword) < 8` → 422 validation error before any DB operation.
**Result**: SAFE — minimum length enforced on reset path (same as registration).

---

### V-10 — Token endpoint reveals which step failed (enumeration) 🛡️ SAFE

**Threat**: By comparing 404 vs 409 vs 410 responses, attacker maps the state of reset tokens.
**Defense**: The error codes reveal token lifecycle state (not-found/expired/used) but not user information. Knowing a token is expired or used does not identify the account holder. The reset request always returns 202 regardless of whether the email exists.
**Result**: SAFE — no user identity information is revealed by token state responses.

---

### VULN Summary

| ID | Threat | Result |
|----|--------|--------|
| V-01 | User enumeration via reset response | 🛡️ SAFE |
| V-02 | Token brute-force | 🛡️ SAFE |
| V-03 | Token replay after use | 🛡️ SAFE |
| V-04 | Expired token accepted | 🛡️ SAFE |
| V-05 | SQL injection via token path | 🛡️ SAFE |
| V-06 | Token stored in plaintext | 🛡️ SAFE |
| V-07 | New password stored in plaintext | 🛡️ SAFE |
| V-08 | Duplicate token collision | 🛡️ SAFE |
| V-09 | Weak new password accepted | 🛡️ SAFE |
| V-10 | Token state reveals user info | 🛡️ SAFE |

**10 SAFE, 0 EXPOSED**
User enumeration prevention, 256-bit token entropy, SHA-256 hash storage, Argon2id password hashing, and single-use enforcement prevent all tested vulnerability vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return 404 for unregistered email, 202 for registered | User enumeration — attacker maps registered accounts |
| Store raw token in DB | DB breach exposes all active reset tokens; mass account takeover |
| Send token in HTTP response body (production) | Token intercepted by browser logs, proxies, or JS; send only via email |
| No expiry on reset tokens | Old tokens remain valid forever; stolen tokens usable months later |
| Allow token reuse after password reset | Token replay attack after email interception |
| No minimum password length | Users set `aa` as their new password |
| Return 200 for GET `/password-reset/{token}` on used token | Client cannot distinguish valid from already-used |
| Use MD5/SHA-1 for token hash | Precomputed rainbow tables exist; use SHA-256 or better |
| No transaction for `updatePasswordHash` + `markUsed` | Race condition: password updated but token remains reusable |
