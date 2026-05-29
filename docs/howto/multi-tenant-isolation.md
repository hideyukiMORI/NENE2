---
title: "How-To: Multi-Tenant Isolation"
category: security
tags: [multi-tenant, isolation, idor, authorization, jwt]
difficulty: advanced
related: [tenant-isolation, jwt-tenant-isolation, tenant-isolation-idor]
---

# How-To: Multi-Tenant Isolation

This guide covers building a multi-tenant API with NENE2 where each tenant's data is
strictly isolated. Skipping any step creates a silent IDOR (Insecure Direct Object Reference)
that exposes every tenant's data.

---

## The core rule: `tenant_id` filter in every query

Omitting the tenant filter from a single query silently returns all tenants' data:

```sql
-- ❌ No tenant filter — returns every tenant's records
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ Always include the tenant filter
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

Name repository methods with a `ForTenant` suffix to make the contract visible:

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}

/** @return list<Note> */
public function findAllForTenant(int $tenantId): array
{
    /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
    $rows = $this->executor->fetchAll(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
        [$tenantId],
    );

    return array_map($this->hydrate(...), $rows);
}

public function delete(int $id, int $tenantId): bool
{
    $note = $this->findByIdForTenant($id, $tenantId);

    if ($note === null) {
        return false;
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

    return true;
}
```

The `ForTenant` suffix forces callers to supply the tenant ID. It also makes
code-review straightforward: any method without that suffix is a candidate for
IDOR review.

---

## Embed `tenant_id` in the JWT

Resolve tenant membership once at login and embed it in the token. This avoids
a DB round-trip on every request and keeps the tenant context tamper-evident
(the JWT signature covers it).

```php
$now   = time();
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // must be int
    'email'     => $user->email,
    'iat'       => $now,
    'exp'       => $now + self::TOKEN_TTL_SECONDS,
]);
```

Extract and validate the claim in handlers. Use `is_int()` — `is_string()` alone
is not safe; MySQL/PostgreSQL may reject string-to-int comparisons silently:

```php
private function tenantId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
        return null;  // trigger 401
    }

    return $claims['tenant_id'];
}
```

`BearerTokenMiddleware` stores verified claims in `nene2.auth.claims`. The
middleware rejects expired tokens, tampered signatures, and `alg: none` attacks
before the handler runs.

---

## Return 404 for cross-tenant access (not 403)

Returning 403 Forbidden reveals that the resource exists but the caller lacks
permission — information that crosses tenant boundaries. Always return 404:

```php
// ❌ 403 leaks cross-tenant information
if ($note->tenantId !== $tenantId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403);
}

// ✅ Tenant filter in SQL — cross-tenant records simply return null
$note = $this->notes->findByIdForTenant($id, $tenantId);

if ($note === null) {
    return $this->problems->create(
        $request,
        'not-found',
        'Note Not Found',
        404,
        "Note {$id} does not exist.",
    );
}
```

When `WHERE id = ? AND tenant_id = ?` matches nothing, the repository returns
`null` and the handler returns 404 — no explicit cross-tenant check needed.

---

## Exclude `tenant_id` from responses

`tenant_id` is an infrastructure identifier. Exposing it in responses lets
attackers enumerate all tenant IDs and serves as a starting point for targeted
attacks:

```php
// ❌ tenant_id leaks in response
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // remove this
    'title'     => $note->title,
    'body'      => $note->body,
]);

// ✅ Only fields the client needs
return $this->json->create([
    'id'         => $note->id,
    'title'      => $note->title,
    'body'       => $note->body,
    'created_at' => $note->createdAt,
]);
```

---

## PHPStan: `assertIsList()` for `list<>` return types

`json_decode()` returns `mixed`. After `assertIsArray()`, PHPStan narrows the
type to `array<mixed>`, but that does not satisfy `list<array<string, mixed>>`.
Add `assertIsList()` to narrow further:

```php
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    $this->assertIsArray($data);
    $this->assertIsList($data);  // narrows array<mixed> → list<mixed>

    return $data;
}
```

PHPUnit's `assertIsList()` also validates at runtime that the array has
sequential integer keys starting from 0 — a useful correctness check for API
list responses.

---

## Schema design

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

Every tenant-scoped table carries a `tenant_id NOT NULL` foreign key. This is
enforced at the DB level in addition to the application-level filters.

---

## Code-review checklist

When reviewing multi-tenant code, verify:

1. Every `SELECT`, `UPDATE`, and `DELETE` includes `WHERE tenant_id = ?`
2. `tenant_id` is sourced from the JWT claim, not a URL parameter or request body
3. Cross-tenant access returns 404, not 403
4. Responses do not include `tenant_id`
5. No `JOIN` crosses tenant boundaries without a tenant filter
6. `is_int($claims['tenant_id'])` type check is present

---

## Testing isolation

Unit tests are insufficient — write cross-tenant integration tests that actually
attempt to access another tenant's data:

```php
public function testCrossTenantGetReturns404NotForbidden(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
    $noteId = $this->json($res)['id'];

    // Bob tries to access Alice's note
    $crossRes = $this->get('/notes/' . $noteId, $bobToken);

    // Must be 404 — NOT 403
    $this->assertSame(404, $crossRes->getStatusCode());
}

public function testListNotesShowsOnlyCurrentTenantNotes(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
    $this->post('/notes', ['title' => 'Bob Note',   'body' => 'Beta'], $bobToken);

    $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
    $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

    $this->assertCount(1, $aliceNotes);
    $this->assertSame('Alice Note', $aliceNotes[0]['title']);

    $this->assertCount(1, $bobNotes);
    $this->assertSame('Bob Note', $bobNotes[0]['title']);
}
```

Happy-path tests only verify that your own tenant's data works. Cross-tenant
tests are the only way to catch isolation failures.

---

## See also

- `docs/howto/jwt-authentication.md` — JWT issuance and verification
- `docs/howto/rbac.md` — Role-based access control on top of JWT
- `docs/howto/enforce-resource-ownership.md` — Per-user ownership checks
- `docs/field-trials/2026-05-field-trial-112.md` — Multi-tenant isolation field trial
