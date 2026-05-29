---
title: "How-to: OTP Authentication System"
category: auth
tags: [otp, passwordless, brute-force-protection, session-token, lockout]
difficulty: advanced
related: [numeric-verification-code, pin-verification-lockout, passwordless-auth-magic-link]
---

# How-to: OTP Authentication System

> **FT reference**: FT290 (`NENE2-FT/otplog`) — OTP authentication: 6-digit numeric code with SHA-256 hash storage, brute-force lockout (3 attempts → 10 min), OTP TTL (5 min), replay attack prevention via `used_at`, session token with SHA-256 + revocation, user enumeration prevention via always-202 request endpoint, ATK-01~12 PASS, 35 tests / 44 assertions PASS.

This guide shows how to build a passwordless OTP (One-Time Password) authentication system where users receive a 6-digit code and exchange it for a session token.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Key design points:
- `code_hash` stores SHA-256 of the OTP, never the raw code.
- `attempt_count` + `locked_until` implement brute-force lockout per OTP row.
- `used_at` prevents replay attacks (OTP can only be used once).
- `session_token_hash` stores SHA-256 of the session token; `UNIQUE` prevents collisions.
- `revoked_at` enables explicit logout without deleting the row.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/otp/request` | none | Request OTP (creates user if needed) |
| `POST` | `/otp/verify` | none | Verify OTP, receive session token |
| `GET` | `/otp/session` | `Bearer <token>` | Get session info |
| `DELETE` | `/otp/session` | `Bearer <token>` | Logout (revoke session) |

## OTP Generation — Never Store Raw Code

```php
private const int MAX_ATTEMPTS = 3;
private const int OTP_TTL_MINUTES = 5;
private const int LOCK_MINUTES = 10;
private const int SESSION_TTL_HOURS = 24;

$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);
```

`str_pad` ensures leading zeros (e.g. `random_int(0, 999999)` returning `42` → `'000042'`). The raw code is sent to the user's email; only the hash is stored. `random_int()` is cryptographically secure.

## User Enumeration Prevention — Always 202

```php
// Always 202 — prevents user enumeration
// In production: send email. In this FT we return the code for testing.
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // remove in production
], 202);
```

Whether the email exists or not, the response is always `202 Accepted`. An attacker cannot distinguish "account exists" from "account doesn't exist."

## Auto-Create User on First Request

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    return $this->executor->insert(
        'INSERT INTO users (email, created_at) VALUES (?, ?)',
        [$email, $now]
    );
}
```

Users are created implicitly on the first OTP request — no separate registration step required. The `UNIQUE(email)` constraint prevents duplicates on concurrent inserts.

## OTP Verification — Ordered Checks

```php
// 1. Lockout check (first — before any code comparison)
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. Expiry check
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. Already used check
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. Code check with hash_equals (timing-safe)
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

Check order matters: lockout → expiry → used → code. Only increment `attempt_count` on a wrong code — not on lockout or expiry.

## Brute-Force Lockout

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    if ($otp === null) {
        return;
    }
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

After `MAX_ATTEMPTS` (3) wrong codes, `locked_until` is set 10 minutes in the future. The lockout check happens before any code comparison, so attempts during lockout do not reset the timer.

## Latest OTP Only — New Request Supersedes Old

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

Multiple OTP requests create multiple rows, but only the latest is used for verification. Old OTPs are effectively invalidated — submitting them returns 401.

## Session Token — SHA-256 + Revocation

```php
// Issue session token
$rawToken = bin2hex(random_bytes(32));   // 256-bit entropy, 64 hex chars
$tokenHash = hash('sha256', $rawToken);
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,
    'user_id' => (int) $user['id'],
], 200);
```

Only the SHA-256 hash is stored. If the DB is compromised, raw tokens are never exposed.

## Bearer Token Extraction

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

An empty string after `Bearer ` (e.g. `Authorization: Bearer `) is treated as missing — returns 401.

## Logout — Silent Success

```php
$session = $this->repository->findSessionByTokenHash($tokenHash);
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession($tokenHash, date('c'));
}

return $this->responseFactory->create(['message' => 'logged out'], 200);
```

Logout always returns 200 — it does not reveal whether the token was valid. This prevents attackers from probing token validity via the logout endpoint.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Brute-force OTP 🚫 BLOCKED

**Attack**: Try all `000000`–`999999` combinations sequentially.
**Result**: BLOCKED — after `MAX_ATTEMPTS` (3) wrong codes, `locked_until` is set 10 minutes in the future. Subsequent attempts return 429 until the lockout expires.

---

### ATK-02 — Replay Attack (reuse used OTP) 🚫 BLOCKED

**Attack**: Capture a valid OTP and submit it a second time after it has already been used.
**Result**: BLOCKED — `used_at` is set on first successful verification. A second attempt finds `used_at !== null` → 401.

---

### ATK-03 — User Enumeration via /otp/request 🚫 BLOCKED

**Attack**: Probe `/otp/request` with known and unknown emails to discover which accounts exist.
**Result**: BLOCKED — both existing and non-existing emails always return `202 Accepted` with identical response bodies.

---

### ATK-04 — Verify for Non-Existent User 🚫 BLOCKED

**Attack**: Call `/otp/verify` with an email that has no account.
**Result**: BLOCKED — returns 401 (`invalid code`), not 404 or 500. No stack trace or account-existence signal in the response.

---

### ATK-05 — SQL Injection in Email Field 🚫 BLOCKED

**Attack**: Submit `'; DROP TABLE users; --` as the email.
**Result**: BLOCKED — `filter_var($email, FILTER_VALIDATE_EMAIL)` rejects injection strings as invalid email format before any DB query. All queries use parameterized statements.

---

### ATK-06 — 5-Digit Code (too short) 🚫 BLOCKED

**Attack**: Submit a 5-character code to bypass the OTP format check.
**Result**: BLOCKED — `/^\d{6}$/` requires exactly 6 digits. Returns 422.

---

### ATK-07 — 7-Digit Code (too long) 🚫 BLOCKED

**Attack**: Submit a 7-digit code to bypass format validation.
**Result**: BLOCKED — same regex rejects codes that are not exactly 6 digits. Returns 422.

---

### ATK-08 — Session Token Reuse After Logout 🚫 BLOCKED

**Attack**: Use a token after logging out to maintain access.
**Result**: BLOCKED — `revokeSession()` sets `revoked_at`. The GET handler checks `$session['revoked_at'] !== null` → 401.

---

### ATK-09 — Random Token Guessing 🚫 BLOCKED

**Attack**: Submit a random 64-hex string as a Bearer token.
**Result**: BLOCKED — SHA-256 hash of the random token does not match any `session_token_hash`. Returns 401. Token space is 2^256.

---

### ATK-10 — Empty Bearer Token 🚫 BLOCKED

**Attack**: Send `Authorization: Bearer ` (empty after Bearer prefix).
**Result**: BLOCKED — `trim(substr($header, 7))` returns empty string → `if ($token === '') return 401`.

---

### ATK-11 — Alphabetic Code (non-numeric) 🚫 BLOCKED

**Attack**: Submit `abcdef` as the OTP code.
**Result**: BLOCKED — `/^\d{6}$/` requires decimal digits only. Returns 422 before any DB interaction.

---

### ATK-12 — New OTP Request Invalidates Old Code 🚫 BLOCKED (by design)

**Attack**: Get a valid OTP, let victim request a new one, then submit the original code.
**Result**: BLOCKED — `findLatestOtpForUser()` retrieves only `ORDER BY id DESC LIMIT 1`. The old OTP is superseded; submitting it returns 401 (wrong code hash for the latest OTP).

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Brute-force OTP | 🚫 BLOCKED |
| ATK-02 | Replay attack (used OTP) | 🚫 BLOCKED |
| ATK-03 | User enumeration via /otp/request | 🚫 BLOCKED |
| ATK-04 | Verify non-existent user | 🚫 BLOCKED |
| ATK-05 | SQL injection in email | 🚫 BLOCKED |
| ATK-06 | 5-digit code (too short) | 🚫 BLOCKED |
| ATK-07 | 7-digit code (too long) | 🚫 BLOCKED |
| ATK-08 | Session reuse after logout | 🚫 BLOCKED |
| ATK-09 | Random token guessing | 🚫 BLOCKED |
| ATK-10 | Empty Bearer token | 🚫 BLOCKED |
| ATK-11 | Alphabetic code | 🚫 BLOCKED |
| ATK-12 | Old OTP invalidated by new request | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Hash-based storage, brute-force lockout, `used_at` replay guard, format validation, and always-202 enumeration prevention cover all critical OTP attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw OTP code in DB | DB compromise exposes all active OTPs; always SHA-256 hash |
| No brute-force lockout | 6-digit OTP has 10^6 combinations — bruteforceable in seconds with no lockout |
| Return 404 for unknown email on verify | Reveals which emails have accounts (user enumeration) |
| Return different status for known vs unknown email on /request | Same enumeration risk; always return 202 |
| No `used_at` flag | OTP can be replayed indefinitely until it expires |
| Accept alphabetic or non-6-digit codes | Bypasses format contract; add `/^\d{6}$/` check |
| Store raw session token in DB | DB breach exposes all sessions; store SHA-256 hash only |
| Delete session row on logout | Cannot detect revoked tokens; use `revoked_at` to soft-revoke |
| Reveal logout success/failure based on token validity | Attackers probe token validity via logout; always return 200 |
| Use `findAllOtpsForUser()` and pick valid one | Multiple active OTPs confuse state; use `ORDER BY id DESC LIMIT 1` |
| No email length limit | RFC 5321 max is 254 chars; oversized input causes DB/email issues |
