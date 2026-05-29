---
title: "URL Shortener API & SSRF Prevention"
category: security
tags: [ssrf, url-shortener, vulnerability-assessment]
difficulty: advanced
related: [url-shortener-ssrf-prevention]
---

# URL Shortener API & SSRF Prevention

**FT183** — `shortlog` field trial (脆弱性診断 VULN-A〜L).

A URL shortener lets users submit arbitrary URLs as redirect targets. If the
redirect is followed server-side (e.g., for link preview or analytics) without
validation, attackers can point it at internal services — this is a **Server-Side
Request Forgery (SSRF)** attack.

This guide covers SSRF prevention alongside the full VULN-A〜L security audit
run against the shortlog implementation.

---

## SSRF: The Core Risk

A URL shortener stores and potentially fetches an attacker-controlled URL. SSRF
allows an attacker to:

- Reach internal services: `http://10.0.0.1/admin`, `http://192.168.1.1/`
- Hit cloud metadata: `http://169.254.169.254/latest/meta-data/` (AWS IMDS)
- Read local files: `file:///etc/passwd`
- Execute browser scripts: `javascript:alert(1)`
- Access loopback services: `http://127.0.0.1:8080/`

**The fix:** validate the URL's scheme _and_ destination IP before storing it.

---

## URL Validation Strategy (VULN-K)

### Step 1 — Scheme allowlist

`filter_var($url, FILTER_VALIDATE_URL)` alone is **not sufficient** — it accepts
`javascript:alert(1)` and `ftp://` as valid URLs. Use `parse_url()` and an
explicit scheme allowlist:

```php
$parts = parse_url($url);

if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    return false;   // Malformed URL — no scheme or host
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return false;   // Rejects: javascript:, file://, ftp://, data:, etc.
}
```

`parse_url()` is not a regex — it cannot be ReDoS-exploited (VULN-F).

### Step 2 — Host / IP validation

```php
$host = strtolower($parts['host']);

// Strip IPv6 brackets: [::1] → ::1
if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
    $host = substr($host, 1, -1);
}

// Block localhost and *.localhost aliases
if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
    return false;
}

// If host is an IP literal, check it directly
if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    return !isBlockedIp($host);
}

// Otherwise resolve hostname → check resolved IP
$resolved = gethostbyname($host);

if ($resolved !== $host) {   // false if unresolvable
    return !isBlockedIp($resolved);
}
// Unresolvable hostname → allow (may be a valid domain not reachable from server)
return true;
```

### Step 3 — Private / reserved IP check

```php
function isBlockedIp(string $ip): bool
{
    // IPv6 loopback
    if ($ip === '::1') return true;

    // FILTER_FLAG_NO_PRIV_RANGE: blocks 10.x, 172.16-31.x, 192.168.x
    // FILTER_FLAG_NO_RES_RANGE:  blocks 127.x, 169.254.x, 0.x, 240.x+
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}
```

### DNS rebinding caution

DNS rebinding attacks change a domain's IP _after_ validation passes. For
critical use cases, validate the URL at _fetch time_ too (not just store time),
or use a network-layer egress firewall that blocks private ranges.

---

## Inject the Resolver for Testing

DNS calls in unit tests are slow and non-deterministic. Make the resolver
injectable:

```php
final class UrlValidator
{
    /** @param (callable(string): string)|null $ipResolver */
    public function __construct(private readonly mixed $ipResolver = null)
    {
    }

    private function resolveHost(string $host): string
    {
        /** @var callable(string): string $resolver */
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        return $resolver($host);
    }
}
```

In tests:

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // private → blocked
        'public.example.com' => '93.184.216.34',  // public → allowed
        default              => $host,             // unresolvable → allowed
    };
};

$validator = new UrlValidator($stubResolver);
```

---

## VULN-A〜L Assessment Results

### VULN-A — Integer overflow (`limit` query parameter)

`V::queryInt()` uses `ctype_digit()` + `strlen() > 18` guard.
20-digit and 19-digit strings are rejected before `(int)` cast.

```
✅ PASS — overflow guard prevents silent PHP_INT_MAX wrap
```

### VULN-B — Type confusion (URL / slug from JSON body)

`V::str()` enforces `is_string()` — rejects `int 42`, `bool true`, `null`.

```php
V::str($body['original_url'] ?? null, 2048)  // → null for non-string
V::str($body['slug'] ?? null, 20)            // → null for non-string
```

```
✅ PASS — string type enforced before any URL or slug validation
```

### VULN-C — SQL injection

All queries use PDO parameterized statements:

```php
'SELECT ... FROM links WHERE slug = :slug LIMIT 1'
// → $stmt->execute([':slug' => $slug])
```

`'; DROP TABLE links; --'` fails at slug format validation (SLUG_PATTERN)
before reaching the DB. Even if it reached the DB, parameterized queries
prevent execution.

```
✅ PASS — parameterized queries + slug allowlist
```

### VULN-D — Parameter pollution

PSR-7 `getQueryParams()` calls PHP's `parse_str()` which takes the _last_
value for duplicate keys. Sending `?limit=10&limit=999999` → `limit=999999`
which fails `V::queryInt()` range check (> MAX_LIMIT).

```
✅ PASS — range check catches any single value; no crash
```

### VULN-E — IDOR (cross-user link access)

DELETE uses `deleteForUser($slug, $userId)`:

```sql
DELETE FROM links WHERE slug = :slug AND user_id = :user_id
```

User B's `DELETE /links/user-a-slug` with their own `X-User-Id` returns 404
(the row is not deleted; it simply doesn't match the WHERE clause).

```
✅ PASS — ownership enforced at DB level; 404 avoids enumeration
```

### VULN-F — ReDoS immunity

URL validation uses `parse_url()` (C extension, no backtracking).
Slug validation uses a simple anchored regex with no alternation groups.
`V::queryInt()` uses `ctype_digit()` (O(n), immune to backtracking).

```
✅ PASS — no exponential-backtracking regex on untrusted input
```

### VULN-G — Path traversal

No filesystem access in this API. Not applicable.

```
N/A
```

### VULN-H — Timing attacks on secret comparison

`V::secret()` delegates to `hash_equals()` — constant-time regardless of where
strings differ. Avoids early-exit string comparison that leaks length/prefix
information via timing.

```
✅ PASS — hash_equals() prevents timing oracle
```

### VULN-I — Empty expected secret bypass

`V::secret('', '')` → `false`. An unconfigured API key never grants access:

```php
return $expected !== '' && hash_equals($expected, $actual);
```

```
✅ PASS — empty expected always returns false
```

### VULN-J — ISO 8601 date overflow in `expires_at`

`V::isoDatetime()` uses `DateTimeImmutable::createFromFormat(DATE_ATOM, ...)` +
round-trip comparison. `2024-02-30T00:00:00+00:00` rolls over to Mar 1 in PHP;
the re-formatted string doesn't match input → null.

`+25:00` offset: caught by explicit `$tzHours > 14` range check (PHP silently
accepts it without the check, and the round-trip also passes — making the explicit
check mandatory).

```
✅ PASS — round-trip catches overflow dates; explicit offset range check catches +25:00
```

### VULN-K — SSRF

Without URL validation: `http://127.0.0.1/admin`, `http://169.254.169.254/`,
`http://10.0.0.1/`, `javascript:alert(1)`, `file:///etc/passwd` would all be
stored and potentially fetched.

With `UrlValidator`:

| Input | Block reason |
|---|---|
| `http://127.0.0.1/` | loopback IP (`NO_RES_RANGE`) |
| `http://localhost/` | exact match `'localhost'` |
| `http://internal.localhost/` | `.localhost` suffix |
| `http://10.0.0.1/` | private IP (`NO_PRIV_RANGE`) |
| `http://192.168.1.1/` | private IP |
| `http://169.254.169.254/` | reserved IP (`NO_RES_RANGE`) |
| `http://private.internal/` | resolves to 10.0.0.1 → blocked |
| `javascript:alert(1)` | scheme not in `['http','https']` |
| `file:///etc/passwd` | scheme not in allowlist |
| `ftp://example.com/` | scheme not in allowlist |

```
✅ PASS — scheme allowlist + IP range filter blocks all SSRF vectors
```

### VULN-L — Mass assignment

`click_count` and `created_at` are set server-side in `LinkRepository::create()`.
Request body keys `click_count: 999999` and `created_at: "2000-01-01..."` are
simply ignored — the controller never reads them.

```
✅ PASS — server-side fields set in repository, never from request body
```

---

## VULN Assessment Summary

| ID | Vulnerability | Status |
|---|---|---|
| VULN-A | Integer overflow | ✅ PASS |
| VULN-B | Type confusion | ✅ PASS |
| VULN-C | SQL injection | ✅ PASS |
| VULN-D | Parameter pollution | ✅ PASS |
| VULN-E | IDOR | ✅ PASS |
| VULN-F | ReDoS | ✅ PASS |
| VULN-G | Path traversal | N/A |
| VULN-H | Timing attacks | ✅ PASS |
| VULN-I | Empty secret bypass | ✅ PASS |
| VULN-J | DateTime overflow | ✅ PASS |
| VULN-K | SSRF | ✅ PASS |
| VULN-L | Mass assignment | ✅ PASS |

**All applicable vulnerabilities: PASS (11/11)**

---

## Slug Safety (VULN-A, C)

Slugs must be restricted to a safe character set to prevent both injection and
unexpected routing:

```php
// Pattern: lowercase alphanumeric + hyphens/underscores, 3–20 chars
// Must start and end with alphanumeric
private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,18}[a-z0-9]$|^[a-z0-9]{3}$/';

if (!preg_match(self::SLUG_PATTERN, $rawSlug)) {
    return 422;
}
```

This single regex is anchored and has no alternation groups with overlapping
match paths — it cannot be exploited for ReDoS.

**Rejected slugs**: `'; DROP TABLE links; --'` · `../../etc`  · `MySlug`
· `sl@g!` · `a` (too short) · 21-char string (too long)

---

## Key Takeaways

| Pattern | Implementation |
|---|---|
| SSRF prevention | `parse_url()` scheme allowlist + `filter_var NO_PRIV_RANGE` |
| DNS resolution in tests | Injectable `ipResolver` callback |
| Slug safety | Character allowlist regex (anchored, no backtracking) |
| URL type enforcement | `V::str()` → `is_string()` before URL parsing |
| Expiry validation | `V::isoDatetime()` with round-trip + offset range check |
| IDOR prevention | `WHERE slug = ? AND user_id = ?` in every write query |
| Mass assignment | Server-side fields set in repository, ignored in controller |
