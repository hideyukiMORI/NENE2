---
title: "How to Build Numeric Verification Code"
category: auth
tags: [verification-code, otp, brute-force-protection, sms, email]
difficulty: intermediate
related: [otp-authentication, pin-verification-lockout, one-time-secrets]
---

# How to Build Numeric Verification Code

> **Pattern proven by FT188 verifylog** — 6-digit SMS/email verification code with brute-force protection, constant-time comparison, and replay prevention. ATK-01〜12 全 Pass。

---

## What This Covers

A contact verification flow (email or phone):

1. **Request code** — server generates a random 6-digit code, delivers it out-of-band
2. **Submit code** — user submits the code; 3 attempts max before lockout
3. **Status check** — check if a verification has been completed

Security guarantees:

| Concern | Technique |
|---|---|
| Brute force | 3 max attempts → 429 Locked |
| Timing attack | `hash_equals()` constant-time comparison |
| Code replay | Verified code returns 410 Gone |
| User enumeration | `POST /verifications` always returns 202 |
| Mass assignment | `code_hash/verified_at` set server-side only |
| SQL injection | Integer-only path param (ctype_digit + strlen > 18 guard) |
| Type confusion | `is_string()` check before `ctype_digit()` |
| ReDoS | `ctype_digit()` O(n) — no regex |

---

## Schema

```sql
CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- SHA-256 of the 6-digit code
    attempts_count INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    verified_at    TEXT,               -- NULL = pending
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
```

`code_hash` stores `hash('sha256', $code)` — never the plaintext code.

---

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/verifications` | Request a code (always 202) |
| `POST` | `/verifications/{id}/check` | Submit code (3 attempts max) |
| `GET` | `/verifications/{id}` | Status check (no code revealed) |

---

## Core Pattern: Code Generation and Hash Storage

```php
// Generate cryptographically random 6-digit code
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// Store hash — NEVER the plaintext
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)

// Return plainCode to caller (for delivery) — never store or log it
return ['verification' => $v, 'plainCode' => $plainCode];
```

`random_int(0, 999999)` uses CSPRNG. `str_pad(..., 6, '0', STR_PAD_LEFT)` ensures leading zeros (e.g., `000042`).

---

## Core Pattern: Constant-Time Comparison

```php
// ATK-10: hash_equals prevents timing attack
// $v->codeHash = stored SHA-256 from DB
// $submittedCode = user input (6-digit string)
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**Why not `===`:** `===` short-circuits on the first mismatch — an attacker can measure timing differences between "first byte wrong" and "all bytes wrong" to narrow down the correct code character-by-character. `hash_equals()` is constant-time regardless of where the mismatch occurs.

---

## Core Pattern: Fail-First Attempt Counting

```php
public function check(int $id, string $submittedCode): string
{
    $v = $this->fetchById($id);

    if ($v === null)        return 'not_found';
    if ($v->isVerified())   return 'already';   // ATK-11: replay guard
    if ($v->isLocked())     return 'locked';    // ATK-05: brute force guard
    if ($v->isExpired())    return 'expired';

    // Increment BEFORE checking — prevents race exploitation
    UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

    // ATK-10: constant-time comparison
    $valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));

    if ($valid) {
        UPDATE verifications SET verified_at = :now WHERE id = :id
        return 'verified';
    }

    return 'wrong';
}
```

Incrementing attempts **before** the comparison ensures a concurrent race to check the same code cannot bypass the limit.

---

## Core Pattern: User Enumeration Prevention

```php
// POST /verifications — ALWAYS returns 202
// Even if contact is invalid or delivery fails
private function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $contact = V::str($body['contact'] ?? null, self::MAX_CONTACT_LEN);

    if ($contact === null || $contact === '') {
        return $this->responseFactory->create(['error' => '...'], 422); // only for empty/null
    }

    // delivery success or failure is invisible to caller
    $this->repository->create($contact);

    return $this->responseFactory->create(['id' => $v->id, 'expires_in' => 600], 202);
}
```

A 404 or 422 for an unknown contact leaks "this contact is not registered." Always 202.

---

## Core Pattern: Code Type and Format Validation

```php
$raw = $body['code'] ?? null;

// ATK-07: type confusion — code must be a string
if (!is_string($raw)) {
    return $this->responseFactory->create(['error' => 'code must be a 6-digit string.'], 422);
}

// ATK-09: ReDoS — ctype_digit is O(n), not a regex
// ATK-09: exact length check — not "at least 6"
if (!ctype_digit($raw) || strlen($raw) !== 6) {
    return $this->responseFactory->create(['error' => 'code must be exactly 6 digits.'], 422);
}
```

`is_string()` before `ctype_digit()` rejects JSON integers, booleans, and arrays. `ctype_digit()` is safe from ReDoS (linear time).

---

## Response Design

| Scenario | Status | Body |
|---|---|---|
| Code correct | 200 | `{verified: true}` |
| Code wrong, attempts remaining | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| Max attempts reached | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| Already verified (replay) | 410 | `{error: "This verification has already been completed."}` |
| Expired | 410 | `{error: "Verification has expired. Request a new code."}` |
| Not found | 404 | `{error: "Verification not found."}` |

---

## ATK-01〜12 全 Pass

| ATK | 攻撃 | 防御 |
|---|---|---|
| 01 | SQL injection in `{id}` | `ctype_digit()` + strlen > 18 ガード |
| 02 | IDOR — 他者の verification ID で check | 同一 404 — ownership oracle なし |
| 03 | Mass assignment (code_hash/verified_at から body) | サーバー側のみ設定 |
| 04 | XSS in contact | JSON output のみ — HTML 非レンダリング。contact をレスポンスに返さない |
| 05 | Brute force 6桁コード | 3回失敗で 429 Locked |
| 06 | Auth bypass | verified_at はサーバーのみ設定 |
| 07 | Type confusion (code as int/bool/array) | `is_string()` + `ctype_digit()` |
| 08 | Integer overflow in `{id}` | strlen > 18 guard |
| 09 | ReDoS-style code input | `ctype_digit()` O(n) |
| 10 | Timing attack on code comparison | `hash_equals()` 定数時間 |
| 11 | Code replay after success | 410 Gone |
| 12 | CRLF injection in headers | PSR-7 が HTTP 層で拒否 |

---

## Test Results (FT188)

```
48 tests / 103 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
ATK-01〜12 全 Pass
```

Source: [`../NENE2-FT/verifylog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/verifylog)
