---
title: "How-to: Tenant Isolation & Cross-Tenant IDOR Prevention"
category: security
tags: [multi-tenant, idor, isolation, authorization]
difficulty: advanced
related: [tenant-isolation-idor, jwt-tenant-isolation, enforce-resource-ownership]
---

# How-to: Tenant Isolation & Cross-Tenant IDOR Prevention

**FT179 — isolationlog**

Preventing cross-tenant data leakage in multi-tenant APIs —
scoped SQL queries, header-based identity, and body injection prevention.

---

## The threat: Cross-Tenant IDOR

In a multi-tenant system, every resource belongs to a tenant.
An attacker who controls one tenant account probes IDs from other tenants:

```
GET /notes/42          X-Tenant-Id: 2   ← attacker is tenant 2
                                         note 42 belongs to tenant 1
```

If the server returns the note, the attacker has read another tenant's data —
an **Insecure Direct Object Reference (IDOR)** at the tenant boundary.

---

## The isolation pattern

### 1. Scope all reads at the SQL level

Never query by ID alone. Always add `AND tenant_id = ?`:

```php
// ❌ WRONG — ID alone, cross-tenant readable
'SELECT * FROM notes WHERE id = ?'

// ✅ CORRECT — ID + tenant enforced in SQL
'SELECT * FROM notes WHERE id = ? AND tenant_id = ?'
```

This returns `null` for cross-tenant access, which becomes a 404.
The attacker learns nothing about note 42 — not even whether it exists.

### 2. List queries are always scoped

```php
// ❌ WRONG — could be augmented with ?tenant_id=... injection
'SELECT * FROM notes ORDER BY id DESC LIMIT ?'

// ✅ CORRECT — WHERE tenant_id = ? is never optional
'SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?'
```

### 3. Delete uses the same pattern

```sql
DELETE FROM notes WHERE id = ? AND tenant_id = ?
```

`rowCount()` returns 0 if the note doesn't belong to the tenant → 404.

---

## Header-based tenant identity

Use `X-Tenant-Id` + `X-User-Id` headers for tenant-scoped endpoints.
Validate both with `V::userId()` (ctype_digit + overflow guard + > 0):

```php
private function resolveTenantUser(ServerRequestInterface $request): array
{
    $tenantId = V::userId($request->getHeaderLine('X-Tenant-Id'));
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));

    return [$tenantId, $userId];
}
```

`V::userId()` rejects:
- Empty string (`ctype_digit('') === false`)
- Zero (`id <= 0`)
- Negative (`'-'` fails `ctype_digit`)
- Float string (`'1.5'` fails `ctype_digit`)
- 20+ digit overflow (strlen > 18 guard)
- SQL injection attempts (`'1 OR 1=1'` fails `ctype_digit`)

---

## Body injection prevention

Attackers may include `tenant_id` in the POST body to try to assign
a resource to a different tenant:

```json
POST /notes
X-Tenant-Id: 1
{ "content": "Injection", "tenant_id": 99 }
```

**Never read `tenant_id` from the body.** Always use the server-validated header:

```php
// ATK-04: body['tenant_id'] is never read — always use $tenantId from header
$note = $this->notes->create($tenantId, $userId, $content, date('c'));
//                            ^^^^^^^^^
//                            from V::userId(X-Tenant-Id), not from $body
```

---

## Tenant existence check on write

Before creating a resource, verify the tenant exists:

```php
if (!$this->tenants->exists($tenantId)) {
    return $this->responseFactory->create(['error' => 'Tenant not found.'], 422);
}
```

Without this check, notes would be created for ghost tenant IDs that don't
exist in the tenants table, breaking referential integrity.

---

## Attack checklist (ATK-01 through ATK-12)

| # | Test | Expectation |
|---|------|-------------|
| ATK-01 | No auth headers | 401 |
| ATK-02 | Cross-tenant GET (IDOR) | 404 — note exists but not for this tenant |
| ATK-03 | X-Tenant-Id: `"1"`, `1.5`, `+1`, `1 OR 1=1` | 401 — V::userId rejects |
| ATK-04 | POST body contains `tenant_id: 99` | 201 — body tenant_id ignored |
| ATK-05 | Cross-tenant DELETE | 404 — note not deleted |
| ATK-06 | X-Tenant-Id: `0`, `-1` | 401 |
| ATK-07 | X-Tenant-Id: 20-digit overflow | 401 |
| ATK-08 | Tenant creation without X-Admin-Key | 401 |
| ATK-09 | Wrong X-Admin-Key | 401 |
| ATK-10 | Note for non-existent tenant ID | 422 |
| ATK-11 | List: T1 sees only T1 notes, not T2's | Enforced by SQL WHERE tenant_id |
| ATK-12 | `?limit=-1`, `?limit=10.5`, 20-digit limit | 422 — V::queryInt guards |

---

## Response strategy: 404 not 403

When a cross-tenant IDOR is detected, return **404** — not 403 Forbidden.

- `403` leaks existence: "the resource exists but you can't access it"
- `404` reveals nothing: "no such resource for this tenant"

This prevents tenant enumeration attacks.
