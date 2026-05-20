# Field Trial 39 — Optimistic Locking API (locklog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/locklog/`
**NENE2 version**: 1.5.17
**Theme**: Optimistic locking with a `version` column, conditional UPDATE, 409 Conflict detection, sequential-edit workflow

## Overview

Built a shared note API demonstrating the optimistic locking pattern. Each note carries an integer `version` field. When updating, the client must send its known version. If the server's current version differs, the update is rejected with 409 Conflict. Successful updates increment the version atomically.

## Endpoints Implemented

- `POST /notes` — create (title, content?); response includes `version: 1`
- `GET /notes` — list, paginated; each item includes `version`
- `GET /notes/{id}` — show with version
- `PUT /notes/{id}` — full update; requires `version` field; 409 on mismatch
- `DELETE /notes/{id}` — delete (no version check required)

## Test Results

17 tests, 42 assertions — all pass on first run with zero fixes needed.

---

## Frictions Found

None. The optimistic locking pattern is straightforward with NENE2's database layer:

- `execute()` returns the affected row count — checking `$affected === 0` after a conditional `UPDATE … WHERE id = ? AND version = ?` cleanly detects concurrent modification.
- `DomainExceptionHandlerInterface` for `ConflictException → 409` works exactly the same as for 404 exceptions.
- No transaction needed for the read-then-conditional-update, since the conditional update is itself the atomic check. (SQLite's single-writer model prevents true races in tests, but the pattern is correct for multi-writer production databases too.)

---

## Patterns Validated

### Optimistic locking: conditional UPDATE + affected-row check

```php
public function update(int $id, string $title, string $content, int $expectedVersion, string $now): Note
{
    $current = $this->findById($id); // raises NoteNotFoundException if gone

    if ($current->version !== $expectedVersion) {
        throw new ConflictException($id, $expectedVersion, $current->version);
    }

    $nextVersion = $current->version + 1;

    $affected = $this->executor->execute(
        'UPDATE notes SET title = ?, content = ?, version = ?, updated_at = ?
         WHERE id = ? AND version = ?',
        [$title, $content, $nextVersion, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // A concurrent writer changed the version between our read and this update
        $actual = $this->findById($id);
        throw new ConflictException($id, $expectedVersion, $actual->version);
    }

    return new Note($id, $title, $content, $nextVersion, $current->createdAt, $now);
}
```

The two-phase check (`read first, then conditional UPDATE`) defends against:
1. Reading a matching version and then seeing a concurrent write between read and UPDATE (second check)
2. Client sending a version older than the current one (first check, faster path)

### 409 Conflict via DomainExceptionHandlerInterface

```php
final class ConflictException extends \RuntimeException {}

final readonly class ConflictExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(Throwable $exception): bool
    {
        return $exception instanceof ConflictException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->probs->create(
            request: $request,
            type: 'conflict',
            title: 'Conflict',
            status: 409,
            detail: $exception->getMessage(),
        );
    }
}
```

### Version field validation

```php
if (!isset($body['version']) || !is_int($body['version']) || $body['version'] < 1) {
    $errors[] = new ValidationError('version', 'version (current integer) is required for optimistic locking.', 'required');
}
```

JSON integers decode as PHP `int` — `is_int()` is correct here. A version of `0` or negative is rejected at validation, not at the database level.

### Client workflow

```
GET /notes/42          → { ..., "version": 3 }
PUT /notes/42 { ..., "version": 3 }  → 200 { ..., "version": 4 }
PUT /notes/42 { ..., "version": 3 }  → 409 Conflict (version is now 4)
GET /notes/42          → { ..., "version": 4 }  (fetch fresh)
PUT /notes/42 { ..., "version": 4 }  → 200 { ..., "version": 5 }
```

---

## NENE2 Changes Required

None — zero frictions.

No version bump needed.
