---
title: "API Key Management"
category: auth
tags: [api-keys, sha256, scopes, rotation, authentication]
difficulty: intermediate
related: [access-token-management, bearer-token-middleware, use-bearer-auth]
---

# API Key Management

> **FT reference**: FT266 (`NENE2-FT/apikeylog`) — API key lifecycle: generation, SHA-256 hash storage, prefix-based lookup, scope enforcement, rotation

This guide covers implementing API key management in NENE2 applications: key generation, secure storage, scope-based authorization, revocation, and rotation.

## Core design principles

1. **Never store raw keys** — only SHA-256 hashes in the database.
2. **Return the raw key once** — only at creation time, never again.
3. **Prefix-based lookup, hash-based verification** — the prefix narrows the DB query; hash_equals() does the actual authentication.
4. **Scope hierarchy** — admin ⊃ write ⊃ read; checked per endpoint.
5. **Rotate safely** — create the new key before revoking the old one to prevent lockout.

## Key format

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- 43 chars of base64url(32 random bytes) -----^
|
type prefix (identifiable in logs)
```

`random_bytes(32)` gives 256 bits of entropy. This is computationally infeasible to brute-force regardless of hash speed, so SHA-256 (fast, single-purpose) is appropriate — unlike passwords, API keys are not dictionary-attackable.

## Schema

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,     -- first 16 chars of raw key (lookup index)
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

The `prefix` column stores the **first 16 characters of the raw key** (not the type prefix `nk`). This gives ~78 bits of differentiation, making each prefix effectively unique and enabling O(1) index lookup.

**Critical**: Do NOT use the type prefix (`nk`) as the DB lookup prefix. All keys share the same type prefix, so `WHERE prefix = 'nk'` would scan the entire table — O(n) lookup and a timing channel proportional to the number of keys.

## Key generation

```php
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    public function extractPrefix(string $rawKey): string
    {
        // First 16 chars of the full key — unique per key, safe to index
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` is mandatory. Using `===` or `==` for hash comparison leaks timing information: a 64-char hex string compared with `===` exits on first mismatch, revealing how many leading characters match.

## Authentication flow

```php
public function authenticate(string $rawKey, string $now): ?ApiKey
{
    $prefix = $this->generator->extractPrefix($rawKey);

    $rows = $this->executor->fetchAll(
        'SELECT * FROM api_keys WHERE prefix = ?',
        [$prefix],
    );

    foreach ($rows as $row) {
        $key = $this->hydrate($row);
        if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
            return $key;
        }
    }

    return null;
}
```

The two-step approach:
1. Index lookup by prefix (fast DB query)
2. `hash_equals()` verification against stored hash

Return the same `null` and `401` for all failure cases (not found, wrong hash, expired, revoked) — callers must not distinguish between them.

## Scope hierarchy

```php
enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
```

Enforce scope at the endpoint level:

```php
private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
{
    $rawKey = $request->getHeaderLine('X-Api-Key');
    if ($rawKey === '') {
        return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
    }

    $key = $this->repo->authenticate($rawKey, $now);
    if ($key === null) {
        return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
    }

    if (!$key->scope->allows($required)) {
        return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
    }

    return $key;
}
```

Return `401` for unauthenticated, `403` for authenticated but insufficient scope — never leak whether the key exists.

## Response filtering

The `toArray()` method on `ApiKey` **must not** include `key_hash`. The raw key is only available via `ApiKeyCreateResult::toArray()` immediately after creation.

```php
// ApiKey::toArray() — safe to return from any endpoint
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash is intentionally absent
    ];
}

// ApiKeyCreateResult::toArray() — creation endpoint only
public function toArray(): array
{
    return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
}
```

## Key rotation — safe ordering

**Always create the new key before revoking the old one.**

```php
public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
{
    $old = $this->findById($oldId);
    if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
        return null;
    }

    // Create first — if this fails, old key remains active (no lockout)
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // Revoke after — if this fails, both keys exist temporarily (recoverable via list)
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

Revoke-then-create is dangerous: if CREATE fails after REVOKE, the owner is permanently locked out. The reverse (create-then-revoke) means the worst case is two active keys temporarily — observable and recoverable.

## Expiry

Store `expires_at` as an ISO datetime string. Check in `isActive()`:

```php
public function isActive(string $now): bool
{
    return !$this->isRevoked() && !$this->isExpired($now);
}

public function isExpired(string $now): bool
{
    return $this->expiresAt !== null && $this->expiresAt < $now;
}
```

The authentication flow passes `$now` as a parameter, making the logic testable with fixed timestamps.

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw key in DB | Full exposure on DB breach |
| Use `===` for hash comparison | Timing attack leaks hash prefix length |
| Use type prefix (`nk`) as DB lookup index | O(n) table scan; timing channel |
| Return `key_hash` in list/detail responses | Offline dictionary attack on hashes |
| Revoke old key before creating new key in rotation | Owner lockout on DB error |
| Return different errors for "key not found" vs "key expired" | Oracle for key existence |
| Log `X-Api-Key` header | Key leaks into log storage |
