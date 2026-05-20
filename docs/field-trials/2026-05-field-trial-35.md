# Field Trial 35 — Soft-Deletion Pattern (softlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/softlog/`
**NENE2 version**: 1.5.17
**Theme**: Soft-deletion with `deleted_at`, restore, permanent delete, trash list, purge all

## Overview

Built a notes API demonstrating the soft-deletion pattern: `DELETE /notes/{id}` sets `deleted_at` without removing the row; `GET /notes` excludes deleted rows (`WHERE deleted_at IS NULL`); `GET /notes/trash` shows only deleted rows; `POST /notes/{id}/restore` clears `deleted_at`; `DELETE /notes/{id}/permanent` permanently removes the row; `POST /notes/trash/purge` bulk-deletes all trash rows and returns the count.

The repository uses `bool $includeDeleted = false` on `findById()` to allow restore/hardDelete to look up rows that are in the trash, while regular show/update operations get 404 for deleted notes.

## Endpoints Implemented

- `GET /notes` — list active notes, pinned first
- `POST /notes` — create note (title, body, is_pinned), 201
- `GET /notes/trash` — list deleted notes with `deleted_at` populated
- `POST /notes/trash/purge` — bulk delete all trash, returns `{ "purged": N }`
- `GET /notes/{id}` — show active note, 404 for deleted
- `PUT /notes/{id}` — full update (title, body, is_pinned), 404 for deleted
- `DELETE /notes/{id}` — soft delete (sets `deleted_at`), 204
- `POST /notes/{id}/restore` — restore from trash (clears `deleted_at`)
- `DELETE /notes/{id}/permanent` — hard delete (removes row), 204

## Test Results

26 tests, 46 assertions — all pass with no fixes needed (zero frictions encountered).

---

## Frictions Found

None. The soft-deletion pattern is straightforward to implement with NENE2's database layer:
- Nullable `deleted_at` column handled naturally in `DatabaseQueryExecutorInterface::fetchOne()` results
- `execute()` returning affected row count makes `purgeDeleted()` trivial
- `WHERE deleted_at IS NULL` / `IS NOT NULL` conditions work correctly in all queries

The `bool $includeDeleted = false` parameter on `findById()` cleanly separates concerns without requiring a second method, keeping the repository surface minimal.

---

## Route Ordering Note

Static routes must be registered before parameterized routes to avoid conflicts:

```php
$router->get('/notes/trash', ...);           // static — must come first
$router->post('/notes/trash/purge', ...);    // static — must come first
$router->get('/notes/{id}', ...);            // param — must come after
```

If `GET /notes/{id}` were registered first, a request to `GET /notes/trash` would match with `{id} = "trash"` and throw a `NoteNotFoundException` instead of listing the trash. This is consistent with all prior FTs (documented in FT30 projtrack).

---

## NENE2 Changes Required

None — zero frictions.

## Version

No NENE2 changes needed. Will bundle the FT34 documentation hint (unregistered handler → 500) with the next substantive change.
