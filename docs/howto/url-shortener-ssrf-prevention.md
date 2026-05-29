---
title: "How-to: URL Shortener with SSRF Prevention"
category: security
tags: [ssrf, url-shortener, private-ip, mass-assignment]
difficulty: advanced
related: [url-shortener-ssrf]
---

# How-to: URL Shortener with SSRF Prevention

> **FT reference**: FT337 (`NENE2-FT/shortlog`) — URL shortener with SSRF blocking (private IPs, loopback, link-local, dangerous schemes), slug validation, mass assignment prevention, ISO 8601 date validation, ReDoS-safe limit parsing, 50+ tests PASS.

This guide shows how to build a URL shortener that accepts only safe public URLs, validates slugs, prevents mass assignment, and protects against Server-Side Request Forgery (SSRF).

## Schema

```sql
CREATE TABLE links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    original_url TEXT    NOT NULL,
    expires_at   TEXT,               -- ISO 8601, nullable
    click_count  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/links` | Create short link |
| `GET`  | `/links` | List own links |
| `GET`  | `/links/{slug}` | Get link by slug |
| `DELETE` | `/links/{slug}` | Delete own link |

## Create Short Link

```php
POST /links
X-User-Id: 1
{
  "original_url": "https://example.com/very/long/path",
  "slug": "my-link",
  "expires_at": "2030-12-31T23:59:59+09:00"
}
→ 201
{
  "id": 1,
  "user_id": 1,
  "slug": "my-link",
  "original_url": "https://example.com/very/long/path",
  "expires_at": "2030-12-31T23:59:59+09:00",
  "click_count": 0,
  "created_at": "..."
}
```

`slug` is optional — auto-generated (`[a-z0-9_-]+`) if omitted.

### Missing Auth

```php
POST /links  (no X-User-Id header)
→ 401
```

### Duplicate Slug

```php
POST /links  {"slug": "my-link"}  // already exists
→ 409
```

## Slug Validation

```
Valid: lowercase letters, digits, hyphens, underscores
Length: 3–20 characters

Valid examples: "abc", "my-link", "link123", "test-link-01"
```

```php
POST /links  {"slug": "ab"}          → 422  // too short (min 3)
POST /links  {"slug": "a".repeat(21)} → 422  // too long (max 20)
POST /links  {"slug": "MySlug"}       → 422  // uppercase not allowed
POST /links  {"slug": "sl@g!"}        → 422  // special chars
POST /links  {"slug": "my slug"}      → 422  // space not allowed
POST /links  {"slug": 42}             → 422  // type must be string (VULN-B)
```

## URL Validation

```php
POST /links  {"original_url": ""}              → 422  // empty
POST /links  {}                                → 422  // missing
POST /links  {"original_url": 42}              → 422  // not a string (VULN-B)
POST /links  {"original_url": true}            → 422  // bool (VULN-B)
POST /links  {"original_url": null}            → 422  // null (VULN-B)
POST /links  {"original_url": "https://..."+"x".repeat(2030)}  → 422  // too long
```

## SSRF Prevention

Block URLs that would make the server call internal infrastructure:

### Blocked Schemes

```php
POST /links  {"original_url": "javascript:alert(1)"}  → 422
POST /links  {"original_url": "file:///etc/passwd"}   → 422
POST /links  {"original_url": "ftp://example.com/"}   → 422
```

Only `http://` and `https://` are allowed.

### Blocked IP Ranges

```php
// Loopback
POST /links  {"original_url": "http://127.0.0.1/admin"}     → 422
POST /links  {"original_url": "http://localhost/secret"}     → 422
POST /links  {"original_url": "http://internal.localhost/"}  → 422  // *.localhost

// RFC 1918 private ranges
POST /links  {"original_url": "http://10.0.0.1/metadata"}    → 422
POST /links  {"original_url": "http://192.168.1.1/router"}   → 422
POST /links  {"original_url": "http://172.16.0.1/internal"}  → 422

// Link-local (AWS metadata, etc.)
POST /links  {"original_url": "http://169.254.169.254/latest/meta-data/"}  → 422

// Public IP — accepted
POST /links  {"original_url": "https://8.8.8.8/"}            → 201  ✅
```

### DNS Rebinding Prevention

Hostnames that resolve to private IPs are also blocked:

```php
// "private.internal" resolves to 10.0.0.1 → blocked
POST /links  {"original_url": "http://private.internal/data"}  → 422

// "public.example.com" resolves to 93.184.216.34 → allowed
POST /links  {"original_url": "https://public.example.com/page"}  → 201  ✅
```

### Implementation

```php
private const BLOCKED_RANGES = [
    '127.',          // loopback
    '10.',           // RFC 1918
    '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.',
    '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918
    '192.168.',      // RFC 1918
    '169.254.',      // link-local
];

private const ALLOWED_SCHEMES = ['http', 'https'];

public function validate(string $url): bool
{
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
        return false;
    }

    $host = $parsed['host'] ?? '';

    // Block *.localhost
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // Resolve hostname to IP
    $ip = ($this->dnsResolver)($host);

    foreach (self::BLOCKED_RANGES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return false;
        }
    }

    return true;
}
```

## Mass Assignment Prevention

```php
// Attacker tries to set click_count or created_at
POST /links
{
  "original_url": "https://example.com",
  "slug": "attack",
  "click_count": 999999,
  "created_at": "2000-01-01T00:00:00+00:00"
}
→ 201  {"click_count": 0, "created_at": "2026-..."}  // ignored fields
```

Only whitelist `original_url`, `slug`, `expires_at` from the request body. Never read `click_count`, `created_at`, or `user_id` from the body.

## ISO 8601 Date Validation

```php
// Invalid calendar dates
POST /links  {"expires_at": "2024-02-30T00:00:00+00:00"}  → 422  // Feb 30
POST /links  {"expires_at": "2024-13-01T00:00:00+00:00"}  → 422  // month 13
POST /links  {"expires_at": "2030-06-01T00:00:00+25:00"}  → 422  // +25:00 offset

// Valid
POST /links  {"expires_at": "2030-06-01T00:00:00+09:00"}  → 201  ✅
```

Validation pattern: parse with `DateTimeImmutable::createFromFormat()` and verify the round-trip:

```php
$dt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
if ($dt === false) return false;
// Round-trip check catches "2024-02-30" which PHP normalizes to "2024-03-01"
return $dt->format(DATE_RFC3339) === $value;
```

## ReDoS-Safe Limit Validation

```php
// ctype_digit for O(n) — immune to ReDoS
GET /links?limit=10       → 200  ✅
GET /links?limit=999999   → 422  // exceeds MAX_LIMIT
GET /links?limit=9...9 (19 digits)  → 422  // overflow guard
GET /links?limit=111...1x (51 chars with x)  → 422, <100ms  // ReDoS payload
```

## IDOR Prevention

```php
// User 2 tries to delete user 1's link
DELETE /links/user1-link
X-User-Id: 2
→ 404  // NOT 403 — prevents enumeration
```

The link exists but the lookup is scoped to `WHERE slug = ? AND user_id = ?`. A mismatch returns 404 as if the link doesn't exist.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow `http://localhost` or `http://127.0.0.1` | Server fetches its own admin endpoint via the short link |
| Skip DNS resolution check | Attacker registers `evil.example.com` → A record `10.0.0.1` to bypass IP literal check |
| Allow `javascript:` scheme | XSS via shortlink in any browser that opens the redirect |
| Allow `file://` scheme | Server reads `/etc/passwd` if the shortener fetches the URL on creation |
| Accept `click_count` from request body | Attacker inflates click metrics |
| No slug length/charset restriction | `slug = "' OR 1=1--"` passes validation, reaches SQL |
| Use regex `/^\d+$/` for limit validation | ReDoS on long mixed-digit payloads |
| Return `created_at` from request body | Time spoofing corrupts audit trail |
