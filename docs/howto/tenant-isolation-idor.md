# How-to: Tenant Isolation & IDOR Prevention

> **FT reference**: FT318 (`NENE2-FT/isolationlog`) — Multi-tenant data isolation, cross-tenant IDOR prevention, header type-confusion hardening, body tenant_id injection prevention, 34 tests / 133 assertions PASS.

This guide shows how to enforce strict tenant-level data isolation so that no tenant can read, modify, or enumerate another tenant's data — even if they manipulate headers or request bodies.

## Schema

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL,
    content    TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Authentication Model

```
Admin endpoints  → X-Admin-Key: <server_secret>       (e.g. env ADMIN_KEY)
Tenant endpoints → X-Tenant-Id: <int>  X-User-Id: <int>
```

### Header Validation Rules

`X-Tenant-Id` and `X-User-Id` must pass **strict positive-integer** validation:

| Input | Result |
|-------|--------|
| `"1"` (valid) | ✅ Accepted |
| `"0"` | ❌ 401 — must be > 0 |
| `"-1"` | ❌ 401 — negative rejected |
| `"1.5"` | ❌ 401 — float rejected |
| `"+1"` | ❌ 401 — sign prefix rejected |
| `"1 OR 1=1"` | ❌ 401 — SQL injection attempt rejected |
| `""` (absent) | ❌ 401 — missing header |
| `"99999999999999999999"` (20 digits) | ❌ 401 — overflow rejected |

```php
// Validation pattern using ctype_digit + range check
$raw = $request->getHeaderLine('X-Tenant-Id');
if (!ctype_digit($raw) || ($id = (int) $raw) <= 0 || strlen($raw) > 10) {
    return $this->json->create(['error' => 'Unauthorized'], 401);
}
```

## Admin Endpoints

```php
POST /tenants   X-Admin-Key: admin-secret
{"name": "Acme Corp"}
→ 201  {"id": 1, "name": "Acme Corp", "created_at": "..."}

GET  /tenants   X-Admin-Key: admin-secret
→ 200  {"total": 2, "tenants": [...]}

GET  /tenants/1  X-Admin-Key: admin-secret
→ 200  {"id": 1, "name": "Acme Corp", ...}

// No admin key
POST /tenants  (no X-Admin-Key)   → 401
POST /tenants  X-Admin-Key: wrong → 401
```

## Tenant Endpoints — IDOR Prevention

### Create Note (server-assigned tenant)

```php
POST /notes  X-Tenant-Id: 1  X-User-Id: 42
{"content": "Hello"}
→ 201  {"id": 1, "tenant_id": 1, "content": "Hello", ...}
```

**The `tenant_id` in the request body is ALWAYS ignored.** The server uses only the header value:

```php
// Attacker sends X-Tenant-Id: 1 but body tries to inject tenant 2
POST /notes  X-Tenant-Id: 1
{"content": "Injection", "tenant_id": 2}  // ← ignored

→ 201  {"tenant_id": 1, ...}   // assigned from header, not body
```

### Cross-Tenant IDOR — Returns 404

```php
// Note 5 belongs to Tenant 1
GET  /notes/5  X-Tenant-Id: 2  → 404   // IDOR blocked
DELETE /notes/5  X-Tenant-Id: 2 → 404  // IDOR blocked

// Owner can still access
GET  /notes/5  X-Tenant-Id: 1  → 200   ✅
```

All queries include `WHERE tenant_id = $tenantId`. A missing row returns 404 — **not 403** — to prevent existence enumeration.

### List Isolation

```php
// T1 has 2 notes, T2 has 1 note
GET /notes  X-Tenant-Id: 1  → {"data": [note_A, note_B], "tenant_id": 1}
GET /notes  X-Tenant-Id: 2  → {"data": [note_X],         "tenant_id": 2}
// T2 never sees T1's notes
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?
-- Always filter by tenant_id from the validated header
```

### Query Parameter Validation

```php
GET /notes?limit=-1       → 422  // negative
GET /notes?limit=10.5     → 422  // float
GET /notes?limit=999999   → 422  // exceeds max (e.g. 100)
GET /notes?limit=99999999999999999999  → 422  // overflow
GET /notes                → 200  // default limit applied
```

## Non-Existent Tenant Creation for Note

```php
POST /notes  X-Tenant-Id: 9999  X-User-Id: 1
{"content": "test"}
→ 422  // tenant 9999 does not exist
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Trust `tenant_id` from request body | Attacker assigns notes to any tenant |
| Return 403 instead of 404 on IDOR | 403 reveals the resource exists; 404 prevents enumeration |
| Cast header directly: `(int) $header` without ctype_digit | `-1`, `+1`, `1.5`, overflow all produce unexpected integers |
| No `WHERE tenant_id = ?` in list queries | Full cross-tenant data leak |
| Share admin key in client responses | Admin key must stay server-side only |
| Allow `X-Tenant-Id: 0` | Zero is often a default/unset state; accept only positive integers |
