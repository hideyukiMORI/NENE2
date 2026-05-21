# Field Trial Report — FT118: Signed URL Pattern

**Date**: 2026-05-21
**Release**: v1.5.52
**App**: `signedlog` (`/home/xi/docker/NENE2-FT/signedlog/`)
**Tests**: 17/17 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement time-limited, resource-scoped HMAC-SHA256 signed URLs for temporary access to protected resources. No authentication required to consume the URL — the token carries its own authorization proof.

## Token design

```
base64url({resource_id}|{expires_at}|{HMAC-SHA256(resource_id|expires_at, secret)})
```

Both `resource_id` and `expires_at` are covered by the HMAC together. Altering either part invalidates the signature. This prevents:
- Resource substitution (using a valid token for resource A to access resource B)
- Expiry extension (extending the expiry field without a new server-side signature)

## Key implementation decisions

### hash_equals() for HMAC comparison — non-negotiable

`===` exits on first byte mismatch, leaking how many characters match. An attacker exploiting this timing oracle can construct valid HMAC signatures byte by byte without knowing the secret. `hash_equals()` always compares the full string.

### 410 Gone vs 401 Unauthorized

The download endpoint distinguishes expired tokens (410 Gone) from tampered tokens (401 Unauthorized) to give users actionable feedback. The implementation is safe because:

1. `extractExpiresAt()` only base64-decodes and splits — no HMAC computation.
2. `verify()` always checks HMAC before returning any result.
3. The distinction is made only after `verify()` has returned null, so no timing oracle is exposed.
4. The expiry timestamp is not secret — it's visible in the encoded token.

An attacker learning that a token is "expired" gains no information useful for forging signatures.

### Stateless verification

The server needs only its secret key to verify a token — no DB lookup. This is the primary advantage over session tokens or API keys for high-volume download links.

Trade-off: stateless tokens cannot be revoked before expiry. For revocable tokens, add a `revoked_tokens` DB table.

## Test coverage (17 tests)

| Category | Tests |
|---|---|
| File creation (validation) | 3 |
| Sign URL (token returned, default TTL, 404 for missing file) | 3 |
| Download (valid token, missing token, tampered token, expired→410, resource binding, garbage) | 6 |
| HmacSigner unit (round trip, expired, wrong secret, different IDs, extractExpiresAt) | 5 |

## DX observations

- The `extractExpiresAt()` helper makes the 410 vs 401 distinction clean without coupling the route handler to base64/HMAC internals.
- Passing `$now` as a string parameter to `verify()` makes expiry logic testable with fixed timestamps.
- `urlencode()` on the token before embedding in URLs is easy to forget — worth documenting as a common mistake.
