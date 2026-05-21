# Password Reset Flow

Implement a secure token-based password reset: request → verify → complete.

## Overview

A password reset flow has three steps:
1. User requests a reset — a time-limited token is generated and sent (e.g., via email).
2. User verifies the token is still valid before presenting the reset form.
3. User submits a new password — token is consumed and password is updated.

## Database Schema

```sql
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

`token_hash` stores the SHA-256 hash of the raw token. The raw token is never stored in the database.

## Token Generation and Storage

Generate the raw token with `random_bytes`, then store only the SHA-256 hash:

```php
$rawToken  = bin2hex(random_bytes(32)); // 256-bit entropy, 64 hex chars
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($userId, $tokenHash, $expiresAt, $now);

// Return $rawToken to the user (via email or API response)
```

When verifying, hash the incoming token the same way:

```php
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

Storing a hash means a DB breach does not expose usable reset tokens — an attacker would need to reverse SHA-256 on a 256-bit random value, which is computationally infeasible.

## User Enumeration Prevention

`POST /password-reset` must always return 202, even for unknown email addresses:

```php
$user = $this->repo->findUserByEmail($email);

// Always 202 — do not reveal whether the email is registered
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// ... generate token for real user
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

Returning 404 for unknown emails would let an attacker enumerate registered accounts by probing email addresses.

## One-Time Use

Set `used_at` when the reset completes. Reject any token that has `used_at IS NOT NULL`:

```php
if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}

$this->repo->markUsed($tokenHash, $now);
```

```php
public function isUsed(): bool
{
    return $this->usedAt !== null;
}
```

## Expiry

Enforce expiry at both GET (status check) and POST (complete). Always check expiry before checking `isUsed()`:

```php
if ($reset->isExpired($now)) {
    return 410; // Gone — distinct from "not found" (404) and "used" (409)
}
if ($reset->isUsed()) {
    return 409;
}
```

410 (Gone) distinguishes "expired" from "used" (409), giving the user actionable information.

## Old Token Invalidation

When a user requests a new reset, invalidate all previous unused tokens for that user:

```php
$this->executor->execute(
    "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
    [$now, $userId],
);
```

Without this, a user who lost a reset email and requests a new one would have two valid tokens in circulation simultaneously — both could be used to reset the password.

## Response Sanitization

`GET /password-reset/{token}` must not expose `user_id` or `token_hash` in the response:

```php
public function toArray(): array
{
    return [
        'id'         => $this->id,
        'expires_at' => $this->expiresAt,
        'created_at' => $this->createdAt,
    ];
}
```

Exposing `user_id` would link the reset token to a user account ID, which is unnecessary since the token itself is the authorization credential.

## Security Properties

| Property | Implementation |
|---|---|
| Token entropy | `bin2hex(random_bytes(32))` — 256 bits |
| Token storage | SHA-256 hash only — raw token never in DB |
| User enumeration | Always 202 from `POST /password-reset` |
| Expiry | 1 hour; checked at GET and POST |
| One-time use | `used_at` set on completion; 409 on reuse |
| Old token invalidation | Previous unused tokens set to used on new request |
| Response leakage | `user_id` and `token_hash` excluded from all responses |
| Password hashing | Argon2id |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/password-reset` | Request a reset (always 202) |
| `GET` | `/password-reset/{token}` | Check token validity |
| `POST` | `/password-reset/{token}` | Complete reset with new password |
