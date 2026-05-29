---
title: "How-To: JWT Refresh Token Rotation"
category: auth
tags: [refresh-token, jwt, token-rotation, replay-attack, session]
difficulty: intermediate
related: [refresh-token-pattern, add-jwt-authentication, session-management]
---

# How-To: JWT Refresh Token Rotation

This guide covers implementing short-lived access tokens combined with long-lived refresh
tokens. The key property is **rotation**: every use of a refresh token immediately revokes
it and issues a new one. A reused (already-revoked) refresh token triggers revocation of all
tokens for that user.

---

## Why two tokens?

| Token | TTL | Storage | Purpose |
|---|---|---|---|
| Access token | 5 min | Client memory | Authenticates API requests (stateless, no DB lookup) |
| Refresh token | 7 days | DB (hashed) | Issues new access tokens; managed via rotation |

A short-lived access token limits the damage if it leaks — it expires in minutes. The
refresh token extends the session without requiring re-login, but it is revocable because
it lives in the database.

---

## Schema

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL UNIQUE,  -- SHA-256 hash; never the raw value
    expires_at TEXT    NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
```

`token_hash` — always store the hash, never the raw token. If the DB leaks,
hashed tokens cannot be used directly.

---

## Issuing tokens

### Access token: add `jti` for uniqueness

Without `jti`, two tokens issued in the same second for the same user are identical —
their payloads are byte-for-byte equal. `jti` (JWT ID) guarantees each token is unique
and is the foundation for future access-token blocklists:

```php
$accessToken = $this->issuer->issue([
    'jti'   => bin2hex(random_bytes(8)),  // unique per issuance
    'sub'   => $user->id,
    'email' => $user->email,
    'iat'   => time(),
    'exp'   => time() + 300,  // 5 minutes
]);
```

### Refresh token: store the hash, return the raw value

```php
public function issue(int $userId): string
{
    $raw       = bin2hex(random_bytes(32));  // 256-bit random token
    $hash      = hash('sha256', $raw);       // store only this
    $expiresAt = (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
    $createdAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $this->executor->insert(
        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at)
         VALUES (?, ?, ?, 0, ?)',
        [$userId, $hash, $expiresAt, $createdAt],
    );

    return $raw;  // the client receives this; the DB never stores it
}
```

To look up a token from a client-supplied value:

```php
public function findByRaw(string $raw): ?RefreshToken
{
    $hash = hash('sha256', $raw);

    $row = $this->executor->fetchOne(
        'SELECT ... FROM refresh_tokens WHERE token_hash = ?',
        [$hash],
    );
    // ...
}
```

---

## Token rotation

Every refresh request must revoke the old token before issuing a new one:

```php
private function refresh(ServerRequestInterface $request): ResponseInterface
{
    // ... parse body, find stored token ...

    if ($stored === null || !$stored->isValid()) {
        // Reuse of a revoked token is a potential replay attack —
        // revoke all tokens for the user to force re-authentication.
        if ($stored !== null && $stored->revoked) {
            $this->refreshTokens->revokeAllForUser($stored->userId);
        }

        return $this->problems->create(
            $request,
            'invalid-refresh-token',
            'Invalid or Expired Refresh Token',
            401,
            'The refresh token is invalid, expired, or has already been used.',
        );
    }

    $user = $this->users->findById($stored->userId);

    // Rotation: revoke old token first, then issue new pair
    $this->refreshTokens->revoke($stored->id);

    return $this->json->create($this->issueTokenPair($user));
}
```

**Reuse detection**: if a revoked refresh token arrives at the `/auth/refresh` endpoint,
it means either the user is replaying an old token (unusual) or an attacker stole it.
`revokeAllForUser()` forces every session to re-authenticate, limiting the blast radius.

---

## Logout: always return 204

Never return different status codes depending on whether the refresh token was valid.
Doing so lets an attacker probe whether a token is still active:

```php
private function logout(ServerRequestInterface $request): ResponseInterface
{
    // ... parse body ...

    $stored = $this->refreshTokens->findByRaw($body['refresh_token']);

    if ($stored !== null && !$stored->revoked) {
        $this->refreshTokens->revoke($stored->id);
    }

    // Always 204 — never leak whether the token was valid or not
    return $this->json->createEmpty(204);
}
```

This also means double-logout (calling logout twice with the same token) returns 204 both
times — the client can always call logout safely without worrying about the token's state.

---

## Validity check on the RefreshToken entity

```php
public function isValid(): bool
{
    if ($this->revoked) {
        return false;
    }

    return $this->expiresAt > (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
}
```

String comparison works for ISO-8601 dates sorted lexicographically. If you store
timestamps as Unix integers, compare with `time()` instead.

---

## BearerTokenMiddleware: exclude refresh/logout paths

The refresh and logout endpoints receive a refresh token in the body, not a Bearer access
token in the Authorization header. Exclude them from `BearerTokenMiddleware`:

```php
$authMiddleware = new BearerTokenMiddleware(
    problemDetails: $problems,
    verifier: $verifier,
    excludedPaths: ['/auth/login', '/auth/refresh', '/auth/logout'],
);
```

The `/auth/me` endpoint (and any other protected path) stays protected by the middleware.

---

## Response shape

```json
{
  "access_token":  "eyJhbGci...",
  "token_type":    "Bearer",
  "expires_in":    300,
  "refresh_token": "a3f92c..."
}
```

`expires_in` (seconds) lets the client schedule a proactive refresh before the access
token expires, avoiding a failed request followed by a refresh.

---

## Code-review checklist

1. `token_hash` column stores `hash('sha256', $raw)` — never the raw value
2. `revoke()` is called before `issueTokenPair()` in the refresh handler
3. Revoked-token reuse triggers `revokeAllForUser()` (not just a 401)
4. Logout always returns 204 — no conditional 401/404
5. Access token TTL is short (≤ 15 minutes)
6. `jti` claim is present in access tokens
7. Tests cover cross-token-rotation (old token invalid after refresh) and reuse detection

---

## Testing rotation and reuse detection

```php
public function testRefreshTokenRotation_OldTokenIsInvalidAfterRefresh(): void
{
    $tokens = $this->login();

    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // Old token must be rejected
    $res = $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}

public function testRefreshTokenReuseRevokesAllUserTokens(): void
{
    $tokens = $this->login();

    // Rotate once — old token is now revoked
    $newTokens = $this->json($this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]));

    // Attacker replays the old (revoked) refresh token — triggers revokeAllForUser()
    $this->post('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

    // The newly issued refresh token is now also revoked
    $res = $this->post('/auth/refresh', ['refresh_token' => (string) $newTokens['refresh_token']]);
    $this->assertSame(401, $res->getStatusCode());
}
```

---

## See also

- `docs/howto/jwt-authentication.md` — JWT issuance, BearerTokenMiddleware, `nene2.auth.claims`
- `docs/howto/password-hashing.md` — Argon2id, dummy hash pattern for user enumeration prevention
- `docs/field-trials/2026-05-field-trial-113.md` — Refresh token rotation field trial
