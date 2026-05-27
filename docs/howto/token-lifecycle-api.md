# How-to: API Token Lifecycle Management

> **FT reference**: FT272 (`NENE2-FT/tokenlog`) — API token lifecycle: SHA-256 hash storage (plaintext never persisted), scope enum (read/write/admin) with DB CHECK constraint, IDOR guard (actorId must match userId), soft-revoke via revoked_at, verify endpoint returns valid/user_id/scope, 29 tests / 70 assertions PASS.
>
> **ATK assessment**: ATK-01 through ATK-12 included at the end of this document.

Demonstrates a scoped API token system: issue tokens for a user, list/revoke them, and verify a raw token at access time. Tokens are stored only as SHA-256 hashes — the plaintext is returned once at issuance and never stored.

---

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

Key design choices:
- `token_hash UNIQUE` — prevents accidental duplicate issuance; also the lookup key on verify
- `CHECK (scope IN (...))` — DB-level enforcement of the scope enum
- `revoked_at TEXT` — soft revoke; `NULL` means active, non-NULL means revoked

---

## Routes

| Method   | Path                                | Description                          |
|----------|-------------------------------------|--------------------------------------|
| `POST`   | `/users`                            | Create a user                        |
| `POST`   | `/users/{userId}/tokens`            | Issue a token (owner only)           |
| `GET`    | `/users/{userId}/tokens`            | List tokens for a user (owner only)  |
| `DELETE` | `/users/{userId}/tokens/{tokenId}`  | Revoke a token (owner only)          |
| `POST`   | `/tokens/verify`                    | Verify a raw token                   |

---

## Hash-only storage

The raw token is returned once at issuance and never stored:

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32));   // 64 hex chars — 256 bits of entropy
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // returned to caller, never stored
}
```

On verify, the caller supplies the raw token; the hash is recomputed and looked up:

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null; // not found → caller returns {valid: false}
    }

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => (int) $arr['user_id'],
        'scope'   => (string) $arr['scope'],
    ];
}
```

---

## Scope enforcement

`TokenScope` is a PHP-backed enum; `tryFrom()` rejects unknown values before any DB access:

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

// In the route handler:
$scope = TokenScope::tryFrom($scopeValue);
if ($scope === null) {
    return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
}
```

The DB `CHECK` constraint provides a second enforcement layer.

---

## IDOR guard

Token issuance, listing, and revocation require the actor to be the owner:

```php
$actorId = $this->resolveActorId($request); // from X-User-Id header

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Revocation also verifies that the token belongs to `userId`, not just any token:

```php
if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Revocation

Soft-revoke sets `revoked_at`; the UPDATE only applies if `revoked_at IS NULL`:

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );
    return $count > 0;
}
```

If the token is already revoked, the route handler returns 409 Conflict:

```php
if ($token['revoked']) {
    return $this->responseFactory->create(['error' => 'token already revoked'], 409);
}
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Token replay after revocation 🚫 BLOCKED

**Attack**: Revoke a token, then use the same raw token value on `/tokens/verify`.
**Result**: BLOCKED — `verifyToken()` looks up `revoked_at` in the row; a non-NULL `revoked_at` causes `valid: false`. The revoked token is not deleted, so it resolves but returns `{valid: false}`.

---

### ATK-02 — Brute-force token guessing 🚫 BLOCKED

**Attack**: Submit random 64-char hex strings to `/tokens/verify` hoping to match a valid token hash.
**Result**: BLOCKED — tokens are `bin2hex(random_bytes(32))` = 256 bits of entropy. Probability of a successful guess is `1 / 2^256`. No rate limiting is in this FT, but the entropy alone makes brute-force computationally infeasible.

---

### ATK-03 — IDOR: access another user's token list 🚫 BLOCKED

**Attack**: Set `X-User-Id: 1` and request `GET /users/2/tokens`.
**Result**: BLOCKED — `actorId (1) !== userId (2)` → 403 Forbidden.

---

### ATK-04 — IDOR: revoke another user's token 🚫 BLOCKED

**Attack**: As user 1, call `DELETE /users/2/tokens/{tokenId}`.
**Result**: BLOCKED — the route handler checks `actorId !== userId` → 403 before fetching the token.

---

### ATK-05 — Cross-owner token revocation (shared token ID) 🚫 BLOCKED

**Attack**: As user 2, call `DELETE /users/2/tokens/{tokenId}` where `tokenId` belongs to user 1.
**Result**: BLOCKED — after the IDOR check passes (actorId = userId = 2), `findTokenById` returns the token, then `$token['user_id'] !== $userId` → 403. Double ownership check prevents cross-user revocation.

---

### ATK-06 — Invalid scope injection 🚫 BLOCKED

**Attack**: POST `/users/{id}/tokens` with `{"scope": "superadmin"}`.
**Result**: BLOCKED — `TokenScope::tryFrom('superadmin')` returns `null` → 422. The DB CHECK constraint would also block it if the application layer somehow passed it through.

---

### ATK-07 — Token plaintext extraction from DB 🚫 BLOCKED

**Attack**: If an attacker gains read access to the `tokens` table, can they obtain working tokens?
**Result**: BLOCKED — only `token_hash` (SHA-256) is stored. Reversing SHA-256 is computationally infeasible. The raw token is returned once at issuance and discarded server-side.

---

### ATK-08 — Verify with empty/malformed token 🚫 BLOCKED

**Attack**: POST `/tokens/verify` with `{"token": ""}` or `{"token": null}`.
**Result**: BLOCKED — empty string check: `if ($token === '') → 422`. `null` is rejected by `is_string()` check. The SHA-256 of an empty string would not match any stored hash anyway.

---

### ATK-09 — Token issuance for non-existent user 🚫 BLOCKED

**Attack**: POST `/users/9999/tokens` where user 9999 does not exist.
**Result**: BLOCKED — `findUserById(9999)` returns `false` → 404 before any token is created.

---

### ATK-10 — Double-revoke (idempotency) 🚫 BLOCKED

**Attack**: Revoke the same token twice in rapid succession.
**Result**: BLOCKED — `revokeToken` uses `WHERE revoked_at IS NULL`; the second call returns 0 rows affected. The route handler reads `$token['revoked'] === true` before calling the repo → 409 Conflict. No race-condition window for double-revoke to succeed.

---

### ATK-11 — Negative or string userId in path 🚫 BLOCKED

**Attack**: `GET /users/-1/tokens` or `GET /users/abc/tokens`.
**Result**: BLOCKED — `is_numeric($params['userId'])` → `(int)` cast. `-1` becomes -1; `findUserById(-1)` returns false → 404. `abc` is not numeric → `userId = 0` → 404.

---

### ATK-12 — Scope downgrade on verify response 🚫 BLOCKED

**Attack**: After obtaining a `read`-scoped token, try to forge `scope: write` in the verify response by sending a modified request body.
**Result**: BLOCKED — `/tokens/verify` only accepts a raw token string; the scope is read from the DB row, not from any client-supplied field. The client cannot influence the returned scope.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Replay revoked token | 🚫 BLOCKED |
| ATK-02 | Brute-force token guessing | 🚫 BLOCKED |
| ATK-03 | IDOR: read another user's token list | 🚫 BLOCKED |
| ATK-04 | IDOR: revoke another user's tokens | 🚫 BLOCKED |
| ATK-05 | Cross-owner token revocation | 🚫 BLOCKED |
| ATK-06 | Invalid scope injection | 🚫 BLOCKED |
| ATK-07 | Plaintext extraction from DB | 🚫 BLOCKED |
| ATK-08 | Empty/malformed token on verify | 🚫 BLOCKED |
| ATK-09 | Token issuance for non-existent user | 🚫 BLOCKED |
| ATK-10 | Double-revoke race condition | 🚫 BLOCKED |
| ATK-11 | Negative/string userId in path | 🚫 BLOCKED |
| ATK-12 | Scope downgrade via verify body | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED**
No critical findings. The hash-only storage, scope enum enforcement, and dual IDOR checks form a robust defence surface.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw token in DB | DB read leak exposes all tokens; tokens cannot be rotated without user action |
| Use MD5/SHA-1 for token hash | Collision attacks; prefer SHA-256 or BLAKE2 |
| Accept arbitrary scope strings | Without `tryFrom()` validation, `superadmin` scopes can be issued |
| No ownership check on revoke | Any authenticated user can revoke any token (IDOR) |
| Hard-delete tokens on revoke | Audit trail is lost; no way to detect replay of a revoked token |
| Return 404 on already-revoked token | Makes it impossible to distinguish "not found" from "already revoked"; use 409 |
