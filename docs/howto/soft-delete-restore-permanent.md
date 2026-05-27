# How-to: Soft Delete, Restore, and Permanent Delete

> **FT reference**: `NENE2-FT/softdelete` — Soft delete via `deleted_at` timestamp, restore (only soft-deleted notes can be restored), permanent hard delete (only soft-deleted notes can be permanently deleted), 14 tests PASS.

This guide shows how to implement three deletion states: active, soft-deleted (recoverable), and permanently deleted (gone). Compare with `docs/howto/soft-delete-trash-restore.md` (FT340 softdeletelog) which adds a dedicated trash view and bulk purge.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    deleted_at TEXT             -- NULL = active; timestamp = soft-deleted
);

CREATE INDEX idx_notes_deleted ON notes(deleted_at);
```

`deleted_at IS NULL` → active. `deleted_at IS NOT NULL` → soft-deleted.

## Endpoints

| Method   | Path                     | Description                     |
|----------|--------------------------|---------------------------------|
| `POST`   | `/notes`                 | Create note                     |
| `GET`    | `/notes`                 | List active notes only          |
| `GET`    | `/notes/{id}`            | Get note (404 if deleted)       |
| `DELETE` | `/notes/{id}`            | Soft delete (sets deleted_at)   |
| `POST`   | `/notes/{id}/restore`    | Restore soft-deleted note       |
| `DELETE` | `/notes/{id}/permanent`  | Permanently delete soft-deleted note |

## Create Note

```php
POST /notes  {"title": "My Note", "body": "Some content"}

→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Some content",
  "deleted_at": null,    // ← null = active
  "created_at": "..."
}
```

## List Active Notes

```php
GET /notes
→ 200  {"items": [{...active notes...}], "total": 2}
```

Only returns notes with `deleted_at IS NULL`. Soft-deleted notes are invisible here.

## Soft Delete

```php
DELETE /notes/1
→ 200  // sets deleted_at = now

// Soft-deleted note disappears from active list
GET /notes
→ 200  {"items": [], "total": 0}

// And from direct GET
GET /notes/1
→ 404
```

```sql
UPDATE notes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL
```

## Restore

```php
// Restore a soft-deleted note
POST /notes/1/restore
→ 200  {"id": 1, "title": "My Note", "deleted_at": null, ...}  // back to active

// Restored note appears in active list again
GET /notes
→ 200  {"items": [{...}], "total": 1}
```

### Restore of Active Note → 404

```php
// Trying to restore an active (not soft-deleted) note → 404
POST /notes/2/restore   // note 2 was never deleted
→ 404
```

Only soft-deleted notes can be restored. Active notes return 404 on restore.

```sql
UPDATE notes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL
-- If 0 rows affected → note is active or doesn't exist → 404
```

## Permanent Delete

```php
// Must be soft-deleted first
DELETE /notes/1   // soft delete
POST /notes/1/restore  // restore (optional)

// Permanently delete a soft-deleted note
DELETE /notes/1          // soft delete it first
DELETE /notes/1/permanent
→ 200  {"permanent": true}

GET /notes/1
→ 404  // gone forever
```

### Permanent Delete of Active Note → 404

```php
// Permanently deleting an active note → 404
// Must soft-delete first, then permanently delete
DELETE /notes/2/permanent   // note 2 is active
→ 404
```

```sql
DELETE FROM notes WHERE id = ? AND deleted_at IS NOT NULL
-- If 0 rows affected → note is active or doesn't exist → 404
```

## State Diagram

```
Active
  │
  │ DELETE /notes/{id}     (soft delete)
  ▼
Soft-deleted
  │           │
  │ POST      │ DELETE
  │ /restore  │ /permanent
  ▼           ▼
Active      Gone (hard deleted)
```

**The key invariant**: permanent delete requires a prior soft delete. This prevents accidental hard deletes from active state.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow permanent delete of active note | Skips the soft-delete safety net; data is gone without recovery window |
| Return 200 on restore of active note | Callers cannot tell if the restore was needed; use 404 to signal "not in trash" |
| No index on `deleted_at` | Full table scan for every list query; `WHERE deleted_at IS NULL` is slow without index |
| Hard delete immediately on `DELETE /notes/{id}` | No recovery possible; use soft delete first |
| Expose `deleted_at` in active list | Clients see the field; visually clutters responses; filter it out or use `null` |
