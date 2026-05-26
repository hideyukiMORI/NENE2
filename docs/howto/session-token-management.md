# How-to: Session / Token Management API (ATK-01~12)

This guide demonstrates a secure session token API covering all ATK-01~12 cracker-mindset attack vectors.

## Pattern Overview

- `POST /sessions` — Issue a new opaque token for a user (`X-User-Id` required).
- `GET /sessions/{token}` — Validate a token (404 if revoked or expired).
- `DELETE /sessions/{token}` — Revoke a token (owner or admin).
- `GET /users/{userId}/sessions` — List active sessions (owner or admin).

Tokens are `bin2hex(random_bytes(32))` — 64 lowercase hex characters.

## Schema

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    label      TEXT    NOT NULL DEFAULT '',
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);
CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions (user_id, revoked);
```

## Token Generation

```php
$token = bin2hex(random_bytes(32));  // 64 lowercase hex chars
```

`random_bytes()` uses a CSPRNG; tokens are unguessable and non-sequential.

## Token Format Validation

Before any DB lookup, validate the token format with a tight regex:

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — never hits the DB
    }
    return $token;
}
```

This rejects SQL injection payloads, oversized inputs, uppercase hex, and non-hex strings before any DB query.

## ATK-01: SQL Injection in Token Path

The token format regex rejects `' OR '1'='1` immediately. Even if it passed, the DB query uses a prepared statement:

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02~04: Token Format Attacks

All rejected by the regex `/\A[0-9a-f]{64}\z/`:
- Empty string (length 0 ≠ 64)
- Oversized string (256 chars ≠ 64)
- Non-hex chars (`g`, `A`–`F` uppercase, special chars)
- Wrong length (63 or 65 chars)

## ATK-05: Integer Overflow in X-User-Id

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

A 19-digit integer exceeds the 18-char limit and is rejected before any `(int)` cast.

## ATK-06: Negative / Zero User ID

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` and negative values return `null`, which triggers 400.

## ATK-07: Admin Key Fail-Closed

An empty `adminKey` never grants admin:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` prevents timing attacks when comparing the key.

## ATK-08: Auth Bypass via X-User-Id: 0

`uid()` returns `null` for ID=0 → 400, not 200.

## ATK-09: Non-Numeric User ID in List Path

`ctype_digit()` rejects `abc`, `1.5`, `-1`:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## ATK-10: Float TTL

`is_int()` is PHP's strict type check — `60.5` returns `false`:

```php
if (!is_int($ttlRaw) || $ttlRaw < 1 || $ttlRaw > self::MAX_TTL) {
    return $this->problem(422, ...);
}
```

## ATK-11: Double Revoke Returns 404

The repository checks `revoked === 1` before updating:

```php
if ($session === null || (int) $session['revoked'] === 1) {
    return false;
}
```

Already-revoked sessions cannot be "un-revoked" by re-sending a DELETE.

## ATK-12: Brute-Force Token Format Rejection

Any token not matching exactly 64 lowercase hex chars is rejected with 404 before touching the database. Brute-force attempts hit the regex wall, not the DB.

## IDOR: Owner vs Admin

- Non-owners revoking or listing another user's sessions get 404 (not 403).
- Admins use `X-Admin-Key` header; fail-closed when key is unconfigured.

## See Also

- FT208 source: `../NENE2-FT/sessionlog/`
- Related: `docs/howto/rate-limiting.md` (FT200, ATK)
- Related: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
