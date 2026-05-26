# How-to: Invitation / Referral API

This guide shows how to build a token-based invitation system with expiry and one-time use using NENE2.
Pattern demonstrated by the **invitelog** field trial (FT221).

## Features

- Generate invitation tokens (`bin2hex(random_bytes(16))` = 32 lowercase hex chars)
- Set per-invitation expiry date (ISO 8601)
- Accept/use invitation (one-time, tracks invitee)
- User-scoped invitation list (IDOR: only self can view)
- Status lifecycle: `pending → used` (expired detected on use)

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
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/invitations` | User | Create invitation (returns token) |
| `GET` | `/invitations/{token}` | Public (token = secret) | Get invitation status |
| `POST` | `/invitations/{token}/use` | User | Accept invitation |
| `GET` | `/users/{userId}/invitations` | User (self only) | List own invitations |

## Token Generation

```php
$token = bin2hex(random_bytes(16)); // 32 lowercase hex chars, cryptographically secure
```

Token pattern validated in path params:

```php
/** Token: 32 lowercase hex chars (16 random bytes) */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';
```

## One-Time Use Logic

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) return 'not_found';
    if ($inv['status'] === 'used') return 'already_used'; // → 409
    if ($inv['expires_at'] < $this->now()) return 'expired'; // → 409

    // Mark as used + record invitee
    $this->pdo->prepare(
        "UPDATE invitations SET status = 'used', invitee_id = :iid, used_at = :now WHERE token = :token"
    )->execute([...]);

    return 'ok';
}
```

## IDOR Protection

The invitation list endpoint enforces self-only access:

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

The `GET /invitations/{token}` endpoint uses the token itself as the secret — knowing the token grants access. This is the "token = capability" pattern.

## Security Patterns

- **`bin2hex(random_bytes(16))`**: Cryptographically secure, 128-bit entropy token
- **Token pattern validation**: `/\A[0-9a-f]{32}\z/` — blocks SQL injection, oversized tokens
- **`ctype_digit()`**: ReDoS-safe integer validation for user ID path params
- **ISO 8601 expiry validation**: Regex pattern + lexicographic comparison (UTC)
- **Expiry checked on use**: Not pre-filtered — token lookup returns result, then expiry is checked
