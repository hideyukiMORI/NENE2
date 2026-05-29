---
title: "How-to: JWT Multi-Tenant Isolation"
category: auth
tags: [jwt, multi-tenant, isolation, idor]
difficulty: advanced
related: [jwt-authentication, multi-tenant-isolation, tenant-isolation]
ft: FT342
---

# How-to: JWT Multi-Tenant Isolation

> **FT reference**: FT342 (`NENE2-FT/tenantlog`) — Multi-tenant notes API with JWT Bearer authentication, tenant_id embedded in token claims, strict per-tenant query scoping, cross-tenant IDOR blocked with 404, tenant_id never exposed in responses, 13 tests / 30+ assertions PASS.

This guide shows how to use JWT tokens to carry `tenant_id` as a claim, scope all queries to the authenticated tenant, and prevent cross-tenant data access.

## Schema

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

## Authentication

```
POST /auth/login  →  Bearer token (JWT)
All other endpoints → Authorization: Bearer <token>
```

### Login

```php
POST /auth/login
{"email": "alice@acme.com", "password": "password"}
→ 200  {"token": "eyJhbGci..."}

// Wrong credentials or unknown email
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
// Both failures return the same message (user enumeration prevention)
```

### JWT Claims

```php
// Token payload (decoded)
{
  "sub": 1,           // user_id
  "tenant_id": 1,     // tenant the user belongs to
  "exp": 1748427600
}
```

The `tenant_id` claim is the authoritative source of tenant identity — never trust `tenant_id` from the request body or headers.

### Verification

```php
$verifier = new LocalBearerTokenVerifier($secret);
$claims   = $verifier->verify($token);
// $claims['tenant_id'] is the trusted tenant scope
```

A tampered token (invalid signature) → 401.

## Tenant-Scoped Endpoints

All note operations require a valid Bearer token. The `tenant_id` is extracted from the verified JWT claims.

### Create Note

```php
POST /notes
Authorization: Bearer <alice_token>
{"title": "Alice Note", "body": "Acme content"}
→ 201
{
  "id": 1,
  "title": "Alice Note",
  "body": "Acme content",
  "created_at": "..."
  // tenant_id is NOT returned — never leaked to client
}

// No token → 401
// Invalid token → 401
```

**`tenant_id` is always taken from the JWT claim, not the request body.**

### List Notes

```php
GET /notes
Authorization: Bearer <alice_token>
→ 200  [{"id": 1, "title": "Alice Note", ...}]

// Bob's token only sees Bob's notes — Alice's notes never appear
GET /notes
Authorization: Bearer <bob_token>
→ 200  [{"id": 2, "title": "Bob Note", ...}]
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY created_at DESC
-- tenant_id bound from JWT claims, never from request
```

### Get Note (IDOR Prevention)

```php
// Alice's note
GET /notes/1
Authorization: Bearer <alice_token>
→ 200  {"id": 1, "title": "Alice Note", ...}

// Bob tries to access Alice's note (note id 1 belongs to tenant 1)
GET /notes/1
Authorization: Bearer <bob_token>
→ 404  // NOT 403 — prevents cross-tenant existence enumeration
```

**Return 404, not 403, for cross-tenant access.** A 403 reveals the resource exists in another tenant.

### Delete Note

```php
DELETE /notes/1
Authorization: Bearer <alice_token>
→ 204

// Cross-tenant delete
DELETE /notes/1
Authorization: Bearer <bob_token>
→ 404  // note still intact; Bob's token cannot reach it
```

## Implementation Pattern

```php
// Middleware extracts and verifies JWT
$claims = $verifier->verify($bearerToken);
$request = $request->withAttribute('tenant_id', $claims['tenant_id']);
$request = $request->withAttribute('user_id', $claims['sub']);

// Controller reads from request attributes (never from body)
$tenantId = (int) $request->getAttribute('tenant_id');

// Repository always scopes to tenant
public function findById(int $id, int $tenantId): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    return $stmt->fetch() ?: null;
}

// Null return → 404 response (never 403)
if ($note === null) {
    return $this->json->create(['error' => 'Not found'], 404);
}
```

## Token Tampering Rejection

```php
// Attacker manually crafts a token with a different tenant_id
$fakeToken = 'eyJhbGciOiJIUzI1NiJ9.tampered.invalidsignature';

GET /notes/1
Authorization: Bearer $fakeToken
→ 401  // signature verification fails
```

The server rejects any token whose HMAC-SHA256 signature doesn't match the server secret.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Read `tenant_id` from request body or query params | Attacker sets `tenant_id=2` to access other tenant's data |
| Return 403 for cross-tenant access | Confirms the resource exists in another tenant — info leak |
| Include `tenant_id` in note responses | Exposes internal tenant topology; unnecessary for the client |
| Skip `AND tenant_id = ?` in queries | Cross-tenant leak — attacker with valid token sees all tenants' data |
| Store JWT secret in config alongside data | Secret compromise allows forging tokens for any tenant |
| Trust `tenant_id` from `X-Tenant-Id` header | Header can be set by any client; only trust verified JWT claims |
