---
title: "How to Build Access Token Management with NENE2"
category: auth
tags: [access-token, pat, api-keys, sha256, scopes]
difficulty: intermediate
related: [api-key-management, bearer-token-middleware, use-bearer-auth]
---

# How to Build Access Token Management with NENE2

This guide walks through building a personal access token (PAT) system — users issue, list, and revoke their own API tokens, each with a scope (`read`/`write`/`admin`). Tokens are never stored in plaintext; only their SHA-256 hash is kept.

**Field Trial**: FT136  
**NENE2 version**: ^1.5  
**Covered topics**: token hashing, scope enums, ownership enforcement, revoke idempotency, verify endpoint

---

## What we're building

- `POST /users/{id}/tokens` — issue a token (owner only, returns raw token once)
- `GET /users/{id}/tokens` — list tokens (owner only, no raw token in response)
- `DELETE /users/{id}/tokens/{tokenId}` — revoke a token (owner only, 409 if already revoked)
- `POST /tokens/verify` — verify a raw token (returns valid/invalid + scope)

---

## Database schema

```sql
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

- `token_hash` — SHA-256 of the raw token; never store the raw token
- `revoked_at` — nullable timestamp; `NULL` = active, non-null = revoked
- `CHECK (scope IN (...))` — DB-level scope constraint as defense-in-depth

---

## Token scope enum

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` returns `null` for unknown scopes — use this to validate input before storing.

---

## Issuing tokens

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // 64-char hex string
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // returned once, never stored
}
```

The raw token is returned to the caller exactly once. After that, only the hash is in the database — there is no way to recover the raw token.

---

## Verifying tokens

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
        'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
    ];
}
```

**Why `!isset($arr['revoked_at'])` and not `=== null`?** After `isset()` returns true, PHPStan eliminates `null` from the type — comparing to `null` would be `identical.alwaysFalse`. Use `isset()` alone to check for null.

The verify endpoint always returns 200 with `{ "valid": false }` for unknown or revoked tokens — never 404. This prevents token enumeration.

---

## Ownership enforcement

Every mutating endpoint checks that the authenticated actor matches the resource owner:

```php
$actorId = $this->resolveActorId($request); // from X-User-Id header

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

For revoke, there's a second ownership check on the token itself:

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

This prevents ATK-04 — Bob using his own user path but revoking Alice's token ID.

---

## Revoke — 409 for already-revoked

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

The `WHERE revoked_at IS NULL` guard means the UPDATE is a no-op if the token is already revoked. The handler maps `$count === 0` to 409 Conflict.

---

## List tokens — never include raw token

The list response includes `id`, `scope`, `label`, `created_at`, `revoked` (bool). The raw token is never returned after the initial issue call.

---

## PHPStan level 8 pitfall: isset + null comparison

```php
// WRONG — PHPStan reports `notIdentical.alwaysTrue`
'revoked' => isset($arr['revoked_at']) && $arr['revoked_at'] !== null,

// CORRECT — isset() already implies non-null
'revoked' => isset($arr['revoked_at']),

// WRONG — PHPStan reports `identical.alwaysFalse`
'valid' => !isset($arr['revoked_at']) || $arr['revoked_at'] === null,

// CORRECT
'valid' => !isset($arr['revoked_at']),
```

---

## Cracker attack test results (FT136)

| Attack | Expected | Result |
|--------|----------|--------|
| ATK-01: Issue token for another user (IDOR) | 403 | Pass |
| ATK-02: List another user's tokens (IDOR) | 403 | Pass |
| ATK-03: Revoke another user's token via their path | 403 | Pass |
| ATK-04: Revoke another user's token via own path | 403 | Pass |
| ATK-05: Invalid scope (`superuser`) | 422 | Pass |
| ATK-06: Use revoked token for verify | valid=false | Pass |
| ATK-07: Brute-force random token | valid=false | Pass |
| ATK-08: SQL injection in verify body | valid=false | Pass |
| ATK-09: Non-numeric X-User-Id (`admin`) | not 201 | Pass |
| ATK-10: Negative user ID | 404 | Pass |
| ATK-11: 10KB scope string | 422 | Pass |
| ATK-12: Empty/whitespace-only token | 422 | Pass |

All 12 attack tests pass.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| `isset($x) && $x !== null` | Use `isset($x)` alone — PHPStan level 8 rejects the redundant check |
| Storing raw token in DB | Store only `hash('sha256', $raw)` |
| Returning raw token in list response | Only return raw token in the issue response |
| Not checking token ownership on revoke | Check `token['user_id'] === userId` after finding the token |
| Returning 404 for invalid token in verify | Always return 200 with `valid: false` — prevents enumeration |
