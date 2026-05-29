---
title: "How-to: Invitation System"
category: product
tags: [invitation, token, one-time-use, entropy]
difficulty: intermediate
related: [invitation-referral, user-invitation]
ft: FT283
---

# How-to: Invitation System

> **FT reference**: FT283 (`NENE2-FT/invitelog`) — Invitation code system: 32-char hex token (128-bit entropy), ISO 8601 datetime validation, pending→used status lifecycle, match expression status mapping, IDOR-protected invitation list, 23 tests / 47 assertions PASS.

This guide shows how to build a secure invitation system — generate one-time use tokens that grant access when redeemed.

## Use case

A user creates an invitation link (token) and shares it. The recipient redeems the token to join. Each token is single-use and time-limited.

## Schema

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    inviter_id  INTEGER NOT NULL,
    invitee_id  INTEGER,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations (token);
CREATE INDEX IF NOT EXISTS idx_invitations_inviter ON invitations (inviter_id, id DESC);
```

Key points:
- `token TEXT UNIQUE` — enforces one-token-per-row at DB level
- `invitee_id` is `NULL` until redeemed
- `status` — `'pending'` | `'used'`
- `used_at` — set when redeemed, provides audit timestamp

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/invitations` | `X-User-Id` | Create invitation |
| `GET` | `/invitations/{token}` | None | Look up invitation by token |
| `POST` | `/invitations/{token}/use` | `X-User-Id` | Redeem invitation |
| `GET` | `/users/{userId}/invitations` | `X-User-Id` (self only) | List user's invitations |

## Token Generation

```php
/** Token: 32 lowercase hex chars (16 random bytes = 128-bit entropy) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

$token = bin2hex(random_bytes(16));
```

`random_bytes(16)` generates 128-bit cryptographically secure random data. The hex representation is 32 characters. This is the same entropy level as UUID v4 (122 bits usable).

## expires_at Validation

```php
private const string ISO_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

$expiresAt = trim((string) ($body['expires_at'] ?? ''));
if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

The regex validates format only. Time zone handling (UTC vs local) is the application's responsibility — using a consistent timezone (e.g., UTC) avoids edge cases.

## Status Lifecycle

```
pending → used (one-way, irreversible)
```

Only `pending` invitations can be redeemed. Once used, the invitation is permanently consumed.

## Redemption with match Expression

```php
$result = $this->repo->use($token, $uid);

return match ($result) {
    'not_found'    => $this->problem(404, 'not-found', 'Invitation not found.'),
    'already_used' => $this->problem(409, 'conflict', 'Invitation already used.'),
    'expired'      => $this->problem(409, 'conflict', 'Invitation has expired.'),
    default        => $this->json(['message' => 'Invitation accepted.']),
};
```

`match` is exhaustive (unlike `switch`): no fall-through, must handle all cases. The repository returns a string result type; the handler maps it to HTTP responses cleanly.

## Repository — Atomic Redemption

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) {
        return 'not_found';
    }
    if ($inv['status'] === 'used') {
        return 'already_used';
    }
    // Check expiry
    $now = $this->now();
    if ($inv['expires_at'] < $now) {
        return 'expired';
    }

    // Mark as used
    $this->pdo->prepare('UPDATE invitations SET status = \'used\', invitee_id = ?, used_at = ? WHERE token = ?')
        ->execute([$inviteeId, $now, $token]);

    return 'ok';
}
```

The check-then-update sequence is a potential TOCTOU race for concurrent redemptions of the same token. For production, use a DB-level transaction or `UPDATE WHERE status = 'pending'` and check affected rows.

## IDOR — Invitation List

Only the inviter can view their own invitations:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

Returns 404 (not 403) to hide whether the target user exists.

## X-User-Id Header Validation

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` is ReDoS-safe; `strlen > 18` prevents PHP int overflow on 64-bit; `> 0` rejects user ID 0.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Use short/sequential token (4-digit PIN) | Brute-forceable in milliseconds; use ≥128-bit random |
| Store token without `UNIQUE` constraint | Duplicate token collision causes redemption confusion |
| Check `status === 'pending'` with loose comparison | PHP `'0' == false`; always use strict `===` |
| No expiry validation on redemption | Expired invitations remain redeemable forever |
| Return 403 on invitation list IDOR check | Reveals target user exists; use 404 to hide enumeration |
| Atomic redemption without transaction | Concurrent requests can both see `pending` and both succeed — double redemption |
| Soft delete (`deleted_at`) instead of status column | Status column is self-documenting; `pending`/`used` is clearer than null/non-null |
| Accept any string as `expires_at` | SQL-injectable if not parameterized; use parameterized query + format validation |
| Reset status to `pending` on expired token | Allows reuse of tokens that were legitimately expired |
