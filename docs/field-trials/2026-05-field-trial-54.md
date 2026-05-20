# Field Trial 54 — Optimistic Locking with Version Field (optimisticlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/optimisticlog/`
**NENE2 version**: 1.5.19
**Theme**: Optimistic locking — `version` integer field, 409 Conflict on stale version, Problem Details, concurrent edit simulation

## Overview

Built a document editing API to validate the optimistic locking pattern:
- Every document carries a `version` integer starting at 1
- `PUT /documents/{id}` requires `"version": N` in the request body
- If DB version != N, returns 409 Conflict (Problem Details) with a descriptive detail message
- On success, version increments atomically in the same UPDATE
- Tests simulate a concurrent edit scenario (Alice wins, Bob gets 409)

## Endpoints Implemented

- `POST /documents` — create document (version starts at 1)
- `GET /documents` — list all documents
- `GET /documents/{id}` — get single document (404 if not found)
- `PUT /documents/{id}` — update with optimistic lock check (409 on stale, 422 on missing fields)

## Test Results

18 tests, 34 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

None. All patterns composed cleanly from existing NENE2 public API.

---

## Patterns Validated

### Optimistic lock check in repository

```php
public function update(int $id, string $title, string $body, int $expectedVersion, string $now): Document
{
    $current = $this->findById($id);

    if ($current->version !== $expectedVersion) {
        throw new StaleVersionException($id, $expectedVersion, $current->version);
    }

    $newVersion = $current->version + 1;

    $this->executor->execute(
        'UPDATE documents SET title = ?, body = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $newVersion, $now, $id, $expectedVersion],
    );

    return new Document($id, $title, $body, $newVersion, $current->createdAt, $now);
}
```

The `WHERE id = ? AND version = ?` clause is the database-level guard. The PHP check before it provides a fast, descriptive error message. Both together are standard defense in depth.

### 409 Conflict via ProblemDetailsResponseFactory

```php
} catch (StaleVersionException $e) {
    return $this->problems->create($request, 'conflict', 'Conflict', 409, $e->getMessage());
}
```

`ProblemDetailsResponseFactory::create()` works for any HTTP error status — not just validation failures. 409 with a `conflict` type produces a clean Problem Details body:

```json
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "detail": "Document 1 has version 2 but client sent version 1."
}
```

### version=0 rejected at HTTP layer

```php
if ($version === null || $version < 1) {
    return $this->json->create(['error' => 'version (positive integer) is required'], 422);
}
```

Defensive: version 0 is never a valid optimistic lock token, so it's rejected before touching the repository.

### Concurrent edit simulation in tests

```php
// Alice reads version 1, Bob reads version 1
// Alice saves first (succeeds, version → 2)
$alice = $this->req('PUT', '/documents/' . $id, [..., 'version' => 1]);
// Bob tries to save with stale version 1
$bob   = $this->req('PUT', '/documents/' . $id, [..., 'version' => 1]);

$this->assertSame(200, $alice->getStatusCode()); // Alice wins
$this->assertSame(409, $bob->getStatusCode());   // Bob gets 409
```

`InMemoryRateLimitStorage`-style sequential requests in a single test cleanly model concurrent reads → one-wins-one-loses without actual concurrency.

### Path parameters via Router::PARAMETERS_ATTRIBUTE

```php
$params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$id     = (int) ($params['id'] ?? 0);
```

Standard pattern per `add-database-endpoint.md`. No friction.

---

## NENE2 Changes Required

None. The optimistic locking pattern is a pure application-level concern composable from existing public API.
