# Field Trial Report — FT117: API Key Management (with Vulnerability Assessment)

**Date**: 2026-05-21
**Release**: v1.5.51
**App**: `apikeylog` (`/home/xi/docker/NENE2-FT/apikeylog/`)
**Tests**: 19/19 passed
**PHPStan**: level 8, 0 errors
**CS**: clean
**Vulnerability Assessment**: 2 findings found and fixed before release

## Theme

Implement API key management in NENE2: key generation with type prefix, SHA-256 hash storage, scope-based authorization (read/write/admin hierarchy), key revocation, and key rotation.

FT117 is the third FT in the FT115/FT116/FT117 cycle, triggering a mandatory vulnerability assessment.

## Implementation patterns

### Key format: `{type}_{base64url(32 random bytes)}`

`random_bytes(32)` gives 256 bits of entropy. The type prefix (`nk`) makes keys identifiable in logs and code searches. The raw key is returned once at creation and never stored.

### SHA-256 (not Argon2id) for API keys

API keys are 256-bit random values — not dictionary words. Fast hashing is appropriate here; Argon2id overhead per request is unjustified, and brute-forcing 256-bit random values through SHA-256 is computationally infeasible regardless.

### Prefix-based lookup + hash_equals() verification

Authentication is a two-step process:
1. DB lookup by `prefix` column (first 16 chars of the raw key) — O(1) with index
2. `hash_equals()` timing-safe comparison of computed vs stored hash

### Scope hierarchy via enum method

`ApiKeyScope::allows(required)` encapsulates the hierarchy: admin ⊃ write ⊃ read. Endpoints call `requireScope()`, which returns either the authenticated `ApiKey` or an error response.

### Rotation: create-first, then revoke

Revoke-then-create is a lockout risk. Create-then-revoke means the worst failure mode is two temporarily active keys, which is observable and recoverable.

## Vulnerability Assessment Findings

### V1 — Critical: Prefix column always `'nk'` — full table scan on every auth

**Root cause**: `extractPrefix()` split on `_` and returned `nk` for all keys. `WHERE prefix = 'nk'` returned every key in the database on every authentication request.

**Impact**:
- Performance: O(n) authentication scan — degrades with scale
- DoS potential: any unauthenticated request triggers a full table scan
- Timing channel: response time proportional to number of keys in DB

**Fix**: Changed `extractPrefix()` to `substr($rawKey, 0, 16)`. First 16 chars of the key includes `nk_` plus 13 chars of the random part (78 bits of differentiation) — effectively unique per key, enabling O(1) index lookup.

```php
// Before (vulnerable)
public function extractPrefix(string $rawKey): string
{
    $parts = explode('_', $rawKey, 2);
    return $parts[0]; // always 'nk'
}

// After (fixed)
public function extractPrefix(string $rawKey): string
{
    return substr($rawKey, 0, 16); // unique per key
}
```

### V2 — Medium: `rotate()` not safe — revoke before create risks owner lockout

**Root cause**: Original implementation revoked the old key first, then created the new key. If the CREATE INSERT fails (DB error, constraint violation), the old key is permanently revoked with no replacement.

**Impact**: Owner permanently locked out if rotation fails mid-way. No recovery path without manual DB intervention.

**Fix**: Reordered to create-first, revoke-after. If CREATE fails, old key remains active (no lockout). If REVOKE fails after CREATE succeeds, both keys briefly exist — operator can see this via list endpoint and manually revoke the old one.

### V3 — Acceptable: SHA-256 without HMAC

**Assessment**: Using `hash('sha256', $rawKey)` without a server-side HMAC secret means an attacker who obtains the DB can compute SHA-256 of candidate keys. However, with 256 bits of entropy per key, this is computationally infeasible. HMAC-SHA256 with a secret would provide defense-in-depth but the cost/benefit ratio is low for this key length. Rated Acceptable.

### V4 — PASS: hash_equals() used throughout

Timing-safe comparison is correctly applied. Using `===` would leak timing information revealing how many hash characters match, enabling prefix-based oracle attacks.

### V5 — PASS: key_hash not in API responses

`ApiKey::toArray()` omits `key_hash`. The raw key (`key`) only appears in `ApiKeyCreateResult::toArray()`, used exclusively by the create endpoint.

### V6 — PASS: Scope enforcement

`ApiKeyScope::allows()` correctly implements hierarchy. All three scope levels tested. Read key → 403 on write endpoint. Write key → 403 on admin endpoint. Admin key → 200 on all.

### V7 — PASS: Ownership check on revoke/rotate

Both operations verify `$key->ownerId !== $ownerId` and return `null` → `404` for unauthorized access. The 404 (rather than 403) prevents disclosure of whether a key ID exists at all.

### V8 — PASS: Unified 401 for all authentication failures

Missing key, invalid key, expired key, and revoked key all return `401` with the same message. No oracle for key state.

## Test coverage (19 tests)

| Category | Tests |
|---|---|
| Create key (defaults, missing owner, invalid scope, expiry, custom) | 4 |
| List keys (isolation by owner, key_hash not exposed) | 2 |
| Revoke (happy path, revoked key can't auth, wrong owner 404) | 3 |
| Rotate (creates new + revokes old, already-revoked returns 404) | 2 |
| Protected endpoints (read/write/admin scope enforcement) | 5 |
| Error cases (missing header, invalid key, expired key) | 3 |

## DX observations

- The `prefix + hash_equals` pattern is well-understood in the API key ecosystem (GitHub, Stripe follow it) — easy to explain to implementers.
- Returning `key_hash` in the domain object but omitting from `toArray()` requires discipline — easy to accidentally include it. An explicit exclusion list is safer than an inclusion list.
- The scope hierarchy enum method (`allows()`) is cleaner than a switch in the handler — easy to extend with new scopes.
