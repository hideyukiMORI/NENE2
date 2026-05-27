# How-to: Refresh Token Pattern

> **FT reference**: FT281 (`NENE2-FT/refreshlog`) — Refresh token pattern: short-lived access token (5 min JWT) + long-lived refresh token (7 days), SHA-256 hash storage, token rotation on use, replay attack detection (revoked token → revoke all), logout returns 204 always, 15 tests / 63 assertions PASS.

This guide shows how to implement the refresh token pattern — short-lived access tokens for security, refresh tokens for session continuity.

## Why it matters

JWTs are stateless. Once issued, they cannot be revoked until they expire. A 5-minute TTL limits exposure if a token is stolen. Refresh tokens extend sessions without repeated password prompts, and can be rotated (revoked and re-issued) on each use to detect theft.

## Schema

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` stores SHA-256 of the raw token — never the raw value. `revoked` is a soft-delete flag (vs hard delete for replay detection).

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/auth/login` | None | Email + password → access token + refresh token |
| `POST` | `/auth/refresh` | Refresh token in body | Rotate refresh token, issue new pair |
| `POST` | `/auth/logout` | Refresh token in body | Revoke refresh token |
| `GET` | `/auth/me` | Bearer access token | Get current user info |

## Token Lifetimes

```php
private const int ACCESS_TOKEN_TTL_SECONDS = 300; // 5 minutes — short for security
// Refresh tokens: 7 days (RefreshTokenRepository::TTL_DAYS)
```

Short access tokens limit exposure if stolen. Long refresh tokens allow users to stay logged in across sessions without re-entering passwords.

## Issuing the Token Pair

```php
private function issueTokenPair(User $user): array
{
    $now         = time();
    $accessToken = $this->issuer->issue([
        'jti'   => bin2hex(random_bytes(8)),  // unique token ID — enables future revocation tracking
        'sub'   => $user->id,
        'email' => $user->email,
        'iat'   => $now,
        'exp'   => $now + self::ACCESS_TOKEN_TTL_SECONDS,
    ]);

    $refreshToken = $this->refreshTokens->issue($user->id);

    return [
        'access_token'  => $accessToken,
        'token_type'    => 'Bearer',
        'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $refreshToken,
    ];
}
```

## Storing Only the Hash

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 64 hex chars = 256 bits entropy
    $hash      = hash('sha256', $raw);
    // INSERT token_hash = $hash ...

    return $raw;  // ← return raw to client; never stored
}

public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);
    // SELECT WHERE token_hash = $hash
}
```

If the DB is breached, attackers get hashes — useless without the raw tokens held by clients.

## Token Rotation

```php
// On successful /auth/refresh:
$this->refreshTokens->revoke($stored->id);      // revoke old
return $this->json->create($this->issueTokenPair($user));  // issue new pair
```

Each refresh rotates the token. The old token becomes invalid immediately, so a stolen refresh token can only be used once before rotation invalidates it.

## Replay Attack Detection

```php
$stored = $this->refreshTokens->findByRaw($body['refresh_token']);

if ($stored === null || !$stored->isValid()) {
    // A revoked token being re-used → potential replay attack
    if ($stored !== null && $stored->revoked) {
        $this->refreshTokens->revokeAllForUser($stored->userId);
    }
    return $this->problems->create($request, 'invalid-refresh-token', '...', 401);
}
```

If an attacker steals a refresh token, uses it, and the legitimate client then tries to use it (now revoked) — the system detects this and revokes all sessions for the user, forcing re-authentication.

## Logout Always Returns 204

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Always 204 — never reveal whether the token was valid
    return $this->json->createEmpty(204);
}
```

Returning 401 for an already-revoked token on logout would allow an attacker to probe whether they've been logged out.

## Token Validity Check

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }
    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

Both revocation and expiry are checked. Expired but not-revoked tokens are also rejected.

## Security Properties Summary

| Property | Implementation |
|---|---|
| Access token TTL | 5 minutes (minimize theft exposure) |
| Refresh token TTL | 7 days (session continuity) |
| Token storage | SHA-256 hash only; raw value never stored |
| Token rotation | Old token revoked on each successful refresh |
| Replay detection | Revoked token re-use → revoke all user sessions |
| Logout | Always 204 (never leak token validity) |
| `jti` claim | Unique per token (future revocation tracking) |

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw refresh token in DB | DB breach exposes all active sessions |
| Use hard delete on revoke | Cannot detect replay attacks (need `revoked = 1` to know the token existed) |
| Long access token TTL (hours/days) | Stolen token provides long-term access; defeat the purpose of refresh tokens |
| Return 401 on logout with invalid token | Attacker can probe whether they're still logged in |
| No `jti` in access token | Cannot track individual tokens for future revocation lists |
| Single token (access only, no refresh) | User must re-authenticate every 5 minutes, or use dangerously long TTLs |
| MD5 or SHA-1 for token hash | Weak hash; use SHA-256 or better |
| No expiry on refresh tokens | Refresh tokens live forever; a stolen token provides indefinite access |
