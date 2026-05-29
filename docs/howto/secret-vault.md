---
title: "How-To: Personal Secret Vault API"
category: security
tags: [hmac, idor-prevention, encrypted-storage, admin-auth, user-isolation]
difficulty: intermediate
related: [encrypted-field-storage, one-time-secrets, data-masking]
---

# How-To: Personal Secret Vault API

Demonstrates per-user key-value storage with HMAC integrity, IDOR prevention, and admin-only metadata access.
Field trial: FT195 (`../NENE2-FT/vaultlog/`). Includes VULN-A~L security audit.

---

## Pattern summary

| Concern | Approach |
|---|---|
| User isolation | `WHERE user_id = :uid` on every query — IDOR impossible |
| Admin never sees values | Admin endpoints return only `user_id + key` |
| HMAC integrity | `HMAC-SHA256(userId|key|value, secret)` stored per entry |
| Key validation | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)` — safe, no ReDoS risk |
| User ID validation | `ctype_digit()` + length guard + `> 0` check |
| Admin key | `hash_equals()` constant-time, fail-closed on empty key |
| Upsert | `UNIQUE(user_id, key_name)` → store first (201) or update (200) |

---

## Routes

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | Store or update a secret |
| `GET` | `/vault` | `X-User-Id` | List user's secret keys (no values) |
| `GET` | `/vault/{key}` | `X-User-Id` | Get user's secret value |
| `DELETE` | `/vault/{key}` | `X-User-Id` | Delete user's secret |
| `GET` | `/admin/vault` | `X-Admin-Key` | List all users + keys (no values) |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | List specific user's keys |

---

## Database schema

```sql
CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- HMAC-SHA256 integrity tag
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)
);
```

The `UNIQUE(user_id, key_name)` constraint enforces one entry per (user, key) pair.

---

## HMAC integrity

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

On GET, the handler verifies the stored HMAC:

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Secret integrity check failed.');
}
```

This detects direct DB tampering (e.g., a compromised DBA changing values without going through the API).

---

## IDOR prevention

Every query includes `user_id = :uid`:

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

User 200 querying key `private-key` owned by user 100 gets a 404 — identical to "not found",
preventing enumeration of which keys exist for other users.

Admin endpoints never return `value`:

```php
// User sees their own value
public function toUserArray(): array
{
    return ['key' => ..., 'value' => $this->value, ...];
}

// Admin sees only metadata — no value
public function toAdminArray(): array
{
    return ['user_id' => ..., 'key' => ..., ...];
}
```

---

## Key validation

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

`\A` and `\z` anchors prevent partial matches. The character class is minimal:
lowercase alphanumeric, dash, underscore. Length is bounded `{1,64}` — no backtracking amplification.

This rejects:
- Uppercase letters (`UPPER_CASE`)
- Spaces or special characters
- Path traversal fragments (`../etc/passwd`)
- SQL-injectable strings (`' OR '1'='1`)
- Empty string or strings > 64 chars

---

## User ID validation

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` rejects negative numbers (the `-` sign is not a digit)
- `strlen > 18` prevents integer overflow (`PHP_INT_MAX` has 19 digits)
- `> 0` rejects `"0"` as invalid user ID

---

## Upsert pattern

```php
public function store(int $userId, string $key, string $value): string
{
    $existing = $this->findEntry($userId, $key);
    if ($existing !== null) {
        // UPDATE ...
        return 'updated';  // → 200
    }
    // INSERT ...
    return 'stored';  // → 201
}
```

Returns `'stored'` (201) on first write, `'updated'` (200) on overwrite.
The handler maps these to HTTP status codes.

---

## VULN-A~L results

| Check | Test | Result |
|---|---|---|
| VULN-A | SQL injection in key param / body | PASS — key validation rejects before query |
| VULN-B | IDOR: user reads/deletes other user's key | PASS — 404 on cross-user access |
| VULN-C | List returns only own entries | PASS — WHERE user_id scoped |
| VULN-D | Admin key brute force / bypass | PASS — hash_equals + fail-closed |
| VULN-E | XSS in value | PASS — stored as-is, JSON response not HTML |
| VULN-F | Key upsert idempotency | PASS — last write wins, no duplicates |
| VULN-G | Path traversal in key | PASS — pattern rejects `..` and slashes |
| VULN-H | Negative / zero user-id | PASS — ctype_digit + > 0 guard |
| VULN-I | Very large user-id (overflow) | PASS — strlen > 18 guard |
| VULN-J | Null byte in path | PASS — router / pattern reject |
| VULN-K | Overlong key in body | PASS — 422 validation |
| VULN-L | Empty HMAC secret (no panic) | PASS — deterministic HMAC with empty key, no crash |

---

## Testing notes

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — all injectable for unit tests.
- `withParsedBody($body)` is required in test helpers (Nyholm PSR-7 doesn't auto-parse JSON).
- IDOR tests: store as user 100, attempt access as user 200 → must get 404.
- Admin tests: verify `value` key is absent from every response array.
