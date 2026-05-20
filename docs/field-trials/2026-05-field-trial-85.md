# Field Trial 85 — Invitation Code System (invitelog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/invitelog/`
**NENE2 version:** 1.5.23
**Theme:** One-time invitation codes — generate, claim (with expiry), revoke (issuer-only), list by issuer

---

## What was built

An invitation system where issuers generate unique codes. Each code can be claimed exactly once by a different user. Codes may optionally carry an expiry timestamp. Issuers can revoke unclaimed codes.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/invitations` | Generate a new invitation code |
| GET | `/invitations/{code}` | Fetch invitation details |
| POST | `/invitations/{code}/claim` | Claim a pending code; 409 if already used/revoked/expired |
| POST | `/invitations/{code}/revoke` | Revoke a pending code (issuer only); 409 otherwise |
| GET | `/issuers/{issuerId}/invitations` | List issuer's invitations, optional `?status=pending\|claimed\|revoked` |

### Schema

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    issuer_id   TEXT    NOT NULL,
    recipient   TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'claimed', 'revoked')),
    claimed_by  TEXT,
    expires_at  TEXT,
    created_at  TEXT    NOT NULL,
    claimed_at  TEXT
);
```

Code generation: `bin2hex(random_bytes(16))` — 32-character hex string, collision probability negligible.

---

## Frictions found

### 1. `json_encode([])` produces JSON array `[]`, not object `{}`

**Severity:** Medium (recurring — also reported as FT21 F-4)

When testing "missing required field" scenarios, a natural test pattern is to send an empty body `$this->req('POST', $uri, [])`. In PHP, `json_encode([])` produces `[]` (JSON array), not `{}` (empty object). `JsonRequestBodyParser` rejects JSON arrays with 400, so the handler never runs and the expected 422 is never returned.

**Example:**
```php
// Intention: test that missing claimer_id → 422
$this->req('POST', "/invitations/{$code}/claim", []);
// Actual: 400 (JsonBodyParseException — JsonRequestBodyParser rejects arrays)
```

**Workaround used:** send a JSON object with an irrelevant field:
```php
$this->req('POST', "/invitations/{$code}/claim", ['note' => 'no claimer']);
// Returns: 422 (handler runs, sees missing claimer_id)
```

**Impact:** Test code is misleading — it implies "no body" when it must actually be "body with other fields." This trips up developers new to the framework.

**Potential mitigations:**
- Document `json_encode([]) === '[]'` behavior in the `add-json-endpoint.md` howto.
- Provide a `JsonRequestBodyParser::parseOrEmpty()` variant that accepts JSON arrays and returns `[]` (opt-in permissive mode).
- Alternatively, document how to test "missing field" scenarios: always send an object with unrelated fields.

---

### 2. Expiry checked with string comparison (`<`)

**Severity:** Low (design note)

Expiry checking uses string comparison: `$invitation->expiresAt < $now`. This works correctly when both datetimes use the `Y-m-d H:i:s` format (lexicographic order matches temporal order). The pattern is consistent across FT79 (bookings), FT80 (pricing), and this FT.

There is no framework helper for datetime comparison — this is a domain concern. The current approach is simple and correct for UTC timestamps stored as strings.

---

### 3. Nullable fields in hydration require `isset()` guard

**Severity:** Low

SQLite returns `NULL` for `claimed_by`, `expires_at`, and `claimed_at` when not set. The hydration pattern uses `isset($row['field']) ? (string) $row['field'] : null` consistently across FT78, FT83, and this FT. The pattern is now established convention.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 15 tests, 26 assertions — OK (1 test adjusted for JSON array issue) |
| PHPStan level 8 | No errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- `bin2hex(random_bytes(16))` generates a 32-char hex code — no separate ID column needed for lookup.
- Status machine: `pending` → `claimed` or `revoked`. Terminal states are irreversible.
- Revoke is POST (not DELETE) because it transitions a resource state rather than removing a record.
- The JSON array body issue (F-1) has now appeared in FT21 and FT85 — a pattern worth addressing.
