---
title: "How to enforce resource ownership (IDOR prevention)"
category: security
tags: [idor, ownership, access-control, authorization, security]
difficulty: intermediate
related: [delegated-access-grants, mass-assignment-defence, tenant-isolation]
---

# How to enforce resource ownership (IDOR prevention)

Insecure Direct Object Reference (IDOR) is the #1 API vulnerability (OWASP API Security Top 10).
It occurs when a user can access or modify another user's resources by guessing or enumerating IDs.

NENE2 provides no automatic ownership enforcement — every repository and handler must implement
it explicitly. This guide shows the recommended patterns.

---

## 1. The core rule: 404, not 403

When a user accesses a resource that belongs to another user, return `404 Not Found` — **not** `403 Forbidden`.

- **403** tells the attacker: "this resource exists, but you can't access it." — information leak
- **404** tells the attacker: "this resource does not exist." — no confirmation

```php
// WRONG — leaks existence
if ($note->ownerId !== $authUserId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403, '');
}

// CORRECT — reveals nothing
if ($note === null) {
    return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
}
```

The practical way to achieve this: make the repository **unable to return a resource that doesn't
belong to the caller** — see the next section.

---

## 2. Enforce ownership at the SQL level

The safest pattern is to include `owner_id` in every query. The method literally cannot return
another user's data, regardless of how the caller uses the result.

```php
public function findByIdAndOwner(int $id, string $ownerId): ?Resource
{
    $row = $this->db->fetchOne(
        'SELECT * FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );
    return $row !== null ? $this->hydrate($row) : null;
}

public function update(int $id, string $ownerId, string $newValue): bool
{
    $updated = $this->db->execute(
        'UPDATE resources SET value = ? WHERE id = ? AND owner_id = ?',
        [$newValue, $id, $ownerId],
    );
    return $updated > 0;
}

public function delete(int $id, string $ownerId): bool
{
    return $this->db->execute(
        'DELETE FROM resources WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    ) > 0;
}
```

**Why SQL-level is better than application-level:**
- An app-level check can be bypassed if a developer forgets to call it
- A SQL-level check cannot be skipped — the wrong-owner row will simply not be returned
- Returning `null` for "not found" and "wrong owner" prevents the caller from accidentally branching on a case they shouldn't know about

---

## 3. Handler pattern

```php
private function show(ServerRequestInterface $request): ResponseInterface
{
    $authUserId = $this->resolveAuthUser($request);
    if ($authUserId === null) {
        return $this->unauthorized($request);
    }

    $id       = $this->resolveId($request);
    $resource = $this->repo->findByIdAndOwner($id, $authUserId);

    if ($resource === null) {
        // 404 covers both "not found" and "belongs to another user"
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    return $this->json->create($resource->toArray());
}
```

---

## 4. Listing: filter by owner in the query

```php
public function listByOwner(string $ownerId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM resources WHERE owner_id = ? ORDER BY id DESC',
        [$ownerId],
    );
}
```

Never fetch all rows and filter in PHP. It leaks other users' data if filtering logic is wrong
and is also an N+1 problem.

---

## 5. Test cross-owner access explicitly

Add dedicated tests that verify IDOR is prevented:

```php
public function testCannotReadAnotherUsersResource(): void
{
    $bobId = $this->decode($this->create('bob', 'Bob content'))['id'];

    // Alice tries to read Bob's resource — must get 404
    $res = $this->request('GET', '/resources/' . $bobId, authUser: 'alice');
    self::assertSame(404, $res->getStatusCode());
    // Specifically not 403 — which would leak the resource's existence
    self::assertNotSame(403, $res->getStatusCode());
}

public function testListDoesNotLeakCrossTenantData(): void
{
    $this->create('alice', 'Alice content');
    $this->create('bob', 'Bob content');

    $aliceList = $this->decode($this->request('GET', '/resources', authUser: 'alice'));
    $titles    = array_column($aliceList['items'], 'content');

    self::assertNotContains('Bob content', $titles);
}
```

---

## Notes

- **Why 404 feels wrong**: Returning 404 for a resource you can see in the URL feels "dishonest".
  It is — but OWASP explicitly recommends it to prevent ID enumeration attacks. The tradeoff
  is accepted security practice.
- **Admin bypass**: If you have admin routes that can see any resource, keep those on a separate
  path prefix with a separate ownership check (or no check). Don't complicate the ownership
  methods with "is admin" flags.
- **Database schema**: always add an index on `owner_id` (and on `(owner_id, id)` for compound
  lookups). Without an index, every per-user query is a full table scan.
