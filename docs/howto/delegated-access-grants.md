# How-to: Delegated Access Grants

> **FT reference**: FT282 (`NENE2-FT/grantlog`) — Delegated access grants: scoped (read/write/admin) time-limited resource access, UNIQUE(grantor, grantee, resource) + CHECK(grantor != grantee), IDOR → 404, soft-delete revocation, use-count tracking, GrantScope.satisfies() hierarchy, 23 tests / 71 assertions PASS.
>
> Also validated in FT176 — original implementation.

Per-user, time-limited, revocable access delegation — a grantor gives a grantee
scoped access to a named resource for a bounded time window.

---

## Overview

Delegated Access Grants let one user (`grantor`) give another user (`grantee`)
time-limited, scoped access to a resource identifier.  Think "share document:42
as read-only with user 7, expires in 24 hours, revocable at any time."

Key properties:

- **Multi-party** — grantor and grantee are always different users; self-grants are rejected.
- **State machine** — active → revoked (one-way); expired state is computed from `expires_at`.
- **Opaque resource** — `resource` is a free-form string; the server stores it verbatim.
- **Idempotent uniqueness** — one unique grant per `(grantor_id, grantee_id, resource)`.
- **IDOR-safe** — all ownership checks return 404, not 403, to prevent existence enumeration.

---

## Schema

```sql
CREATE TABLE grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    grantor_id  INTEGER NOT NULL,
    grantee_id  INTEGER NOT NULL,
    resource    TEXT    NOT NULL,
    scope       TEXT    NOT NULL DEFAULT 'read',
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT,
    used_count  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (grantor_id, grantee_id, resource),
    CHECK (scope IN ('read', 'write', 'admin')),
    CHECK (grantor_id != grantee_id)
);
```

The `CHECK (grantor_id != grantee_id)` is a defence-in-depth measure —
self-grant must also be rejected at the application layer for a clear error response.

---

## Domain layer

### GrantScope enum with hierarchy

```php
enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];
        return $rank[$this->value] >= $rank[$required->value];
    }
}
```

### Grant entity — computed state methods

```php
final readonly class Grant
{
    public function isExpired(string $now): bool  { return $this->expiresAt <= $now; }
    public function isRevoked(): bool             { return $this->revokedAt !== null; }
    public function isActive(string $now): bool   { return !$this->isExpired($now) && !$this->isRevoked(); }
}
```

Check **revoked first**, then expiry — both paths return 403 but with distinct error bodies
so grantees understand why access failed without exposing system internals.

---

## HTTP endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/grants` | `X-User-Id` (grantor) | Create a grant |
| `GET` | `/grants/issued` | `X-User-Id` | List grants issued by caller |
| `GET` | `/grants/received` | `X-User-Id` | List grants received by caller |
| `DELETE` | `/grants/{id}` | `X-User-Id` (must be grantor) | Revoke a grant |
| `POST` | `/grants/{id}/use` | `X-User-Id` (must be grantee) | Use a grant |

---

## Validation rules

| Field | Rule |
|-------|------|
| `grantee_id` | Must be a **JSON integer** > 0; string `"2"`, null, boolean, float rejected |
| `resource` | Non-empty string; ≤ 500 UTF-8 chars; stored verbatim (opaque) |
| `scope` | Must be one of `read` / `write` / `admin` |
| `expires_at` | Valid ISO 8601; must be in the future; ≤ 30 days from now |
| Self-grant | `grantee_id == grantor X-User-Id` → 422 |

### Strict integer field parsing

A common vulnerability is implicit type coercion — accepting `"2"` (JSON string)
as `2` (int).  Use explicit type checking:

```php
private function intField(array $body, string $key): ?int
{
    if (!array_key_exists($key, $body)) {
        return null;
    }
    // is_int() returns false for "2", null, true, 2.5 — only true for PHP int
    return is_int($body[$key]) ? $body[$key] : null;
}
```

Note: `2.0` (PHP float) is indistinguishable from `2` (int) after `json_encode` — use `2.5`
to test float rejection in unit tests.

---

## State machine

```
         revoke()
active ─────────────→ revoked   (409 on second revoke)
  │
  │ expires_at ≤ now
  ↓
expired

revoked + expired → revoked wins (check revoked first)
```

Double-revoke must be rejected with **409**, not silently accepted.
The `revoked_at` timestamp must not change on the second call.

---

## IDOR protection pattern

```php
// DELETE /grants/{id}
$grant = $this->repository->find($id);

// Return 404 for both "not found" AND "not your grant"
// Never return 403 here — that would leak existence
if ($grant === null || $grant->grantorId !== $callerId) {
    return $this->responseFactory->create(['error' => "Grant #{$id} not found."], 404);
}
```

Same pattern applies to `POST /grants/{id}/use` — return 404 if caller is not the grantee.

---

## Multi-party confusion prevention

| Scenario | Expected |
|----------|----------|
| Grantor calls `POST /grants/{id}/use` (own grant) | 404 — grantor is not the grantee |
| Grantee calls `DELETE /grants/{id}` | 404 — grantee is not the grantor |
| User 3 calls either on a grant between user 1 and 2 | 404 — IDOR |
| `X-User-Id: 0` or `X-User-Id: -1` | 401 — non-positive IDs rejected |
| Missing `X-User-Id` | 401 |

---

## Security checklist (ATK-01 through ATK-12)

| # | Attack vector | Mitigation |
|---|---|---|
| ATK-01 | Expired grant (clock-boundary) | `isExpired()` comparison; DB `expires_at` back-dated in test |
| ATK-02 | Revoked grant state bypass | `isRevoked()` check before use |
| ATK-03 | Self-grant (grantor == grantee) | App-layer 422 + DB `CHECK` |
| ATK-04 | Wrong grantee uses grant (IDOR) | 404, not 403 |
| ATK-05 | Non-grantor revokes grant (IDOR) | 404, not 403; original grant remains active |
| ATK-06 | Past `expires_at` on creation | `strtotime($expiresAt) <= strtotime($now)` → 422 |
| ATK-07 | Type confusion on `grantee_id` | `is_int()` strict check; rejects `"2"`, `null`, `true`, `2.5` |
| ATK-08 | Path traversal in `resource` | Opaque storage; no filesystem access |
| ATK-09 | SQL injection in `resource`/`scope` | Parameterised queries; scope enum rejects injected value |
| ATK-10 | Unicode/BIDI in `resource` | Stored verbatim; homoglyph and BIDI are distinct resources |
| ATK-11 | Double revoke (state-machine) | 409 on second revoke; `revoked_at` immutable after first |
| ATK-12 | Grantor uses own grant as grantee | 404 — party roles enforced strictly |

---

## Testing approach

- **ATK-01, ATK-02**: Force DB state directly (`UPDATE grants SET expires_at/revoked_at`)
  to simulate time-travel without sleeping.
- **ATK-07**: Test `"2"` (string), `null`, `true`, `2.5` (float) — not `2.0` (indistinguishable
  from int after PHP json_encode).
- **ATK-10**: Use `"\u{202E}"` (BIDI override) and Cyrillic homoglyphs to confirm verbatim storage.
- **ATK-11**: Assert `revoked_at` value is unchanged in DB after second revoke attempt.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE (grantor_id, grantee_id, resource)` | Same pair can create duplicate grants; grantee has stale and active grants for same resource |
| Hard delete on revocation | Loses audit history; cannot tell when access was removed or how many times it was used |
| Return 403 instead of 404 for ownership check | Reveals grant existence to unauthorized callers; IDOR enumeration surface |
| No `CHECK (grantor_id != grantee_id)` | Defense-in-depth missing; self-grants could slip through if app-layer check is bypassed |
| Accept free-form scope string | Typos silently default to `read`; use `GrantScope::tryFrom()` to reject unknown values |
| Scope check without `satisfies()` hierarchy | `write` user must separately pass `read` checks; use hierarchy to check all lower levels |
| No max TTL on `expires_at` | Grantor creates 100-year grants; effectively permanent access with no review |
| No resource length limit | 10MB resource string causes slow index lookups and memory allocation |
| Check expiry before revocation | Revoked + expired grant should show "revoked" — revocation wins in state machine |
| Track `used_count` client-side | Client reports usage count; server must own the counter |
