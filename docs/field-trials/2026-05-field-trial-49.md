# Field Trial 49 — Soft Delete Pattern (softdelete)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/softdelete/`
**NENE2 version**: 1.5.19
**Theme**: Soft delete with `deleted_at` timestamp, trash listing, restore, hard (permanent) delete

## Overview

Built a notes API with full soft-delete lifecycle. Notes are never truly removed on `DELETE` — a `deleted_at` timestamp is set instead. A trash endpoint lists deleted notes. Individual notes can be restored (clearing `deleted_at`) or permanently destroyed (hard delete, only allowed on already-soft-deleted notes).

## Endpoints Implemented

- `POST /notes` — create note (title, body)
- `GET /notes` — list active (non-deleted) notes
- `GET /notes/trash` — list soft-deleted notes
- `GET /notes/{id}` — show active note (404 if deleted)
- `DELETE /notes/{id}` — soft delete (sets `deleted_at`, 404 if already deleted)
- `POST /notes/{id}/restore` — restore from trash (clears `deleted_at`, 404 if active)
- `DELETE /notes/{id}/permanent` — hard delete (only allowed on soft-deleted notes)

## Test Results

18 tests, 33 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

None. Zero frictions in this field trial.

---

## Patterns Validated

### `deleted_at IS NULL` / `IS NOT NULL` filtering

```sql
-- Active only
SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC

-- Trash only
SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC
```

SQLite NULL comparison requires `IS NULL` / `IS NOT NULL`. Using `= NULL` would always return false.

### Soft delete via UPDATE

```sql
UPDATE notes SET deleted_at = ? WHERE id = ?
```

Guard: first check `findActiveById()` (which uses `WHERE deleted_at IS NULL`) to confirm the note is not already deleted before updating.

### Restore via UPDATE to NULL

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ?
```

Guard: first check `WHERE deleted_at IS NOT NULL` to confirm it is in trash. Restoring an active note returns 404.

### Hard delete gate

```sql
-- Only delete if already soft-deleted
SELECT id FROM notes WHERE id = ? AND deleted_at IS NOT NULL
DELETE FROM notes WHERE id = ?
```

Prevents hard-deleting active notes — a common safety constraint in real applications.

### Separate repository methods for active vs. any

```php
// findActiveById — used by show, soft-delete
public function findActiveById(int $id): Note  // WHERE deleted_at IS NULL
// findById — used if you need to fetch regardless of state
public function findById(int $id): Note         // no filter
```

This keeps business intent clear at the call site.

### `Router::PARAMETERS_ATTRIBUTE` — using the documented pattern

Applied `Router::PARAMETERS_ATTRIBUTE` throughout (no regression from FT48 friction):

```php
$params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$id     = (int) ($params['id'] ?? 0);
```

---

## NENE2 Changes Required

None. This field trial validated existing patterns without discovering new frictions.
