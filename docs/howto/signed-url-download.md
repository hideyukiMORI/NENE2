# How-to: Signed URL for Secure Downloads

> **FT reference**: FT338 (`NENE2-FT/signedlog`) — HMAC-SHA256 signed URL generation with TTL, tamper detection (401), expiry (410 Gone), resource-bound tokens, and wrong-secret rejection, 16 tests / 40+ assertions PASS.

This guide shows how to generate time-limited signed URLs that allow unauthenticated download of private files — without exposing long-lived credentials.

## Schema

```sql
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/files` | Register a file record |
| `POST` | `/files/{id}/sign` | Generate a signed download URL |
| `GET`  | `/download?token=...` | Download using signed token |

## Register File

```php
POST /files
{"name": "report.pdf", "owner_id": 1}
→ 201
{
  "id": 1,
  "name": "report.pdf",
  "owner_id": 1,
  "mime_type": "application/octet-stream",
  "created_at": "..."
}

// Custom MIME
POST /files  {"name": "image.png", "owner_id": 2, "mime_type": "image/png"}
→ 201  {"mime_type": "image/png", ...}

// Validation
POST /files  {"owner_id": 1}     → 422  // name required
POST /files  {"name": "f.pdf"}   → 422  // owner_id required
```

## Generate Signed URL

```php
POST /files/1/sign
{"ttl_seconds": 300}
→ 200
{
  "token": "1|2026-05-27 09:05:00|a3f9e2...",
  "expires_at": "2026-05-27T09:05:00Z",
  "url": "/download?token=1%7C2026-05-27+09%3A05%3A00%7Ca3f9e2...",
  "ttl_seconds": 300
}

// Default TTL = 3600 (1 hour) if omitted
POST /files/1/sign  {}
→ 200  {"ttl_seconds": 3600}

// Unknown file
POST /files/999/sign  {"ttl_seconds": 60}
→ 404
```

## Download with Token

```php
GET /download?token=1|2026-05-27+09:05:00|a3f9e2...
→ 200  {"id": 1, "name": "report.pdf", "mime_type": "application/octet-stream"}

// Missing token
GET /download
→ 401

// Tampered token (last 4 chars changed)
GET /download?token=1|2026-05-27+09:05:00|XXXX
→ 401

// Expired token (expires_at in the past)
GET /download?token=1|2020-01-01+00:00:00|...valid_hmac...
→ 410 Gone

// Random garbage
GET /download?token=totally-invalid-garbage
→ 401
```

**410 Gone** (not 401) for expired tokens: the URL existed and was valid — it simply expired. This lets clients distinguish "never valid" from "once valid, now stale."

## Token Format — HMAC-SHA256

```
token = "{file_id}|{expires_at}|{hmac}"

hmac = HMAC-SHA256(key=server_secret, message="{file_id}|{expires_at}")
```

```php
class HmacSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(int $fileId, string $expiresAt): string
    {
        $payload = "{$fileId}|{$expiresAt}";
        $hmac    = hash_hmac('sha256', $payload, $this->secret);
        return "{$payload}|{$hmac}";
    }

    public function verify(string $token, string $now): ?int
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$fileIdStr, $expiresAt, $receivedHmac] = $parts;
        $fileId  = (int) $fileIdStr;
        $payload = "{$fileId}|{$expiresAt}";

        // Constant-time comparison
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $receivedHmac)) {
            return null;  // tampered or wrong secret
        }

        // Check expiry AFTER verifying HMAC
        if ($expiresAt < $now) {
            return -1;  // expired — caller returns 410
        }

        return $fileId;
    }
}
```

**Critical ordering**: always verify the HMAC before checking expiry. Checking expiry first with an invalid token lets attackers probe expiry behaviour.

### Resource Binding

Each token encodes the `file_id`. Tokens for different files produce different HMAC digests:

```php
$token1 = $signer->sign(1, $future);
$token2 = $signer->sign(2, $future);
// $token1 !== $token2 — cannot reuse file-1 token to access file-2
```

### Wrong Secret

A token signed with a different secret returns null on `verify()`:

```php
$otherSigner = new HmacSigner('different-secret');
$token = $otherSigner->sign(1, $future);
$signer->verify($token, $now);  // null — HMAC mismatch
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Use `===` instead of `hash_equals()` for HMAC comparison | Timing attack leaks HMAC byte-by-byte |
| Check expiry before verifying HMAC | Attacker probes expiry on forged tokens to learn server clock |
| Include user_id in token payload only, not file_id | Token for user 1 file 1 reusable to access user 1 file 2 |
| Use `md5()` or `sha1()` instead of HMAC-SHA256 | Keyed hash required; unkeyed hash is trivially forgeable |
| Return 401 for expired tokens | 410 tells the client "token was real but stale"; allows proper re-sign flow |
| Log token value in access logs | Token grants access — treating it like a password; mask or omit in logs |
| Use a weak or predictable secret | Key must be at least 32 random bytes; never derive from timestamp or hostname |
