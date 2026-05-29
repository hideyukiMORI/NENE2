---
title: "How-to: Soft Delete, Trash & Restore API"
category: database
tags: [soft-delete, trash, restore, hard-delete, bulk-purge]
difficulty: intermediate
related: [soft-delete, soft-delete-trash-purge, soft-delete-restore-permanent]
---

# How-to: Soft Delete, Trash & Restore API

> **FT reference**: FT340 (`NENE2-FT/softlog`) — Notes API with soft delete (deleted_at), trash view, restore, permanent hard delete, bulk purge, pinned-first ordering, and ATK cracker-mindset attack assessment, 26 tests / 60+ assertions PASS.

This guide shows how to implement a two-stage deletion lifecycle: items are first soft-deleted (moved to trash) and can be restored, then permanently erased via explicit hard delete or bulk purge.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_pinned  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,               -- NULL = active; ISO 8601 when soft-deleted
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`deleted_at IS NULL` = active; `deleted_at IS NOT NULL` = soft-deleted (in trash).

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/notes` | Create note |
| `GET`  | `/notes` | List active notes (pinned first) |
| `GET`  | `/notes/{id}` | Get active note |
| `PUT`  | `/notes/{id}` | Update active note |
| `DELETE` | `/notes/{id}` | Soft delete (→ trash) |
| `GET`  | `/notes/trash` | List trashed notes |
| `POST` | `/notes/{id}/restore` | Restore from trash |
| `DELETE` | `/notes/{id}/permanent` | Hard delete (permanent) |
| `POST` | `/notes/trash/purge` | Purge all trash |

## Create Note

```php
POST /notes
{"title": "My Note", "body": "Content", "is_pinned": false}
→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Content",
  "is_pinned": false,
  "deleted_at": null,
  "created_at": "..."
}

POST /notes  {"body": "No title"}  → 422  // title required
```

## List Active Notes (Pinned First)

```php
GET /notes
→ 200
{
  "total": 3,
  "items": [
    {"id": 2, "title": "Pinned", "is_pinned": true, ...},
    {"id": 1, "title": "Normal A", ...},
    {"id": 3, "title": "Normal B", ...}
  ]
}
```

```sql
SELECT * FROM notes WHERE deleted_at IS NULL
ORDER BY is_pinned DESC, created_at DESC
```

Soft-deleted notes are never returned in the active list.

## Get Note

```php
GET /notes/1
→ 200  {"id": 1, "title": "My Note", ...}

// Soft-deleted or unknown → same 404
GET /notes/9999    → 404
GET /notes/1 (after DELETE /notes/1)  → 404
```

## Update Note

```php
PUT /notes/1
{"title": "Updated", "body": "New body", "is_pinned": true}
→ 200  {"title": "Updated", "is_pinned": true, ...}

// Soft-deleted note is not updatable
PUT /notes/1  (after DELETE /notes/1)  → 404
```

## Soft Delete

```php
DELETE /notes/1
→ 204  (no body)

// Note disappears from GET /notes and GET /notes/1
// But appears in GET /notes/trash

DELETE /notes/9999  → 404  // not found
```

## Trash View

```php
GET /notes/trash
→ 200
{
  "total": 1,
  "items": [
    {"id": 1, "title": "Gone", "deleted_at": "2026-05-27T10:00:00Z", ...}
  ]
}

// Active notes are NOT in trash
```

`deleted_at` is non-null for all trash items.

## Restore

```php
POST /notes/1/restore
→ 200  {"id": 1, "title": "Restore Me", "deleted_at": null, ...}

// Restored note reappears in GET /notes
// POST /notes/9999/restore  → 404
```

## Hard Delete (Permanent)

```php
DELETE /notes/1/permanent
→ 204  (no body; note is gone from DB)

// Gone from trash too
// DELETE /notes/9999/permanent  → 404
```

## Purge Trash

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// Empty trash
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` issues `DELETE FROM notes WHERE deleted_at IS NOT NULL` and returns the row count.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Hard Delete Without Prior Soft Delete 🚫 BLOCKED

**Attack**: Attacker calls `DELETE /notes/1/permanent` on an active (not-yet-soft-deleted) note.
**Result**: BLOCKED — `DELETE /notes/{id}/permanent` checks `deleted_at IS NOT NULL` before proceeding. Active notes return 404 to the permanent-delete endpoint; only trashed items can be hard-deleted.

---

### ATK-02 — Access Soft-Deleted Note via Direct GET ✅ SAFE

**Attack**: Attacker knows note ID 5 was soft-deleted and calls `GET /notes/5` hoping to read protected content.
**Result**: SAFE — `GET /notes/{id}` queries `WHERE id = ? AND deleted_at IS NULL`. Soft-deleted notes return 404 identically to unknown notes — no existence hint.

---

### ATK-03 — Purge Trash Without Auth (Mass Destruction) ⚠️ EXPOSED

**Attack**: Any client calls `POST /notes/trash/purge` to permanently destroy all trashed notes belonging to all users.
**Result**: EXPOSED — There is no authentication check on `POST /notes/trash/purge`. Without per-user scoping, an unauthenticated client can irreversibly delete all trashed data for all users. Mitigation: require authentication; scope purge to the authenticated user's own trash; require admin role for global purge.

---

### ATK-04 — Double Soft Delete to Corrupt deleted_at ✅ SAFE

**Attack**: Attacker sends `DELETE /notes/1` twice, hoping the second call resets `deleted_at` to a later timestamp.
**Result**: SAFE — The first delete sets `deleted_at`. The second delete finds `deleted_at IS NULL = false`, so the lookup returns 0 rows → 404. The timestamp is not modified.

---

### ATK-05 — Restore Active Note (Corrupt State) 🚫 BLOCKED

**Attack**: Attacker calls `POST /notes/1/restore` on an active (non-deleted) note to force `deleted_at = null` unconditionally.
**Result**: BLOCKED — `restore` queries `WHERE id = ? AND deleted_at IS NOT NULL`. Active notes do not match → 404. Idempotent: restoring an already-active note is a no-op 404.

---

### ATK-06 — SQL Injection via Title on Create ✅ SAFE

**Attack**: Attacker submits `{"title": "'; DROP TABLE notes; --"}` to corrupt the database.
**Result**: SAFE — All writes use parameterized statements. Title is stored as a literal string.

---

### ATK-07 — Overflow Note ID to Skip Validation 🚫 BLOCKED

**Attack**: Attacker sends `GET /notes/99999999999999999999` (20-digit) to overflow PHP integer and reach unintended IDs.
**Result**: BLOCKED — Note IDs are validated with `ctype_digit` + `strlen <= 18` before conversion. Overflow values → 422.

---

### ATK-08 — Update Deleted Note (Write to Ghost) 🚫 BLOCKED

**Attack**: Attacker holds a stale session reference to a deleted note and submits PUT to modify it.
**Result**: BLOCKED — `PUT /notes/{id}` queries `WHERE id = ? AND deleted_at IS NULL`. Soft-deleted notes fail this check → 404. The update is rejected.

---

### ATK-09 — Race: Restore Then Immediately Purge 🚫 BLOCKED

**Attack**: Attacker races `POST /notes/1/restore` and `POST /notes/trash/purge` to destroy a note mid-restore.
**Result**: BLOCKED — Each operation is a single atomic DB transaction. The purge issues `DELETE WHERE deleted_at IS NOT NULL`; the restore sets `deleted_at = NULL`. One wins and the note ends up in a consistent state.

---

### ATK-10 — Concurrent Soft Delete Leaves Orphan ✅ SAFE

**Attack**: Two requests simultaneously call `DELETE /notes/1`. Both check `deleted_at IS NULL`, both see null, and both try to set `deleted_at`.
**Result**: SAFE — The first update succeeds. The second finds `deleted_at IS NOT NULL` (or 0 rows updated) → 404. SQLite serializes writes; the second call is idempotent at the DB level.

---

### ATK-11 — Title Too Long (Storage Abuse) ⚠️ EXPOSED

**Attack**: Attacker submits a 10 MB title string to exhaust database storage.
**Result**: EXPOSED — No maximum length is enforced on `title` or `body`. Mitigation: add `MAX_TITLE_LENGTH` (e.g. 500 chars) and `MAX_BODY_LENGTH` (e.g. 100 000 chars), returning 422 if exceeded. Request size middleware provides a secondary guard.

---

### ATK-12 — Pin Overflow (Flood Pinned Notes) ⚠️ EXPOSED

**Attack**: Attacker creates thousands of pinned notes to push all real notes off the top of the active list.
**Result**: EXPOSED — No limit on pinned note count. Any note can be created with `is_pinned: true`. Mitigation: cap the maximum number of pinned notes per user (e.g. 10); return 422 if exceeded.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Hard delete without soft delete | 🚫 BLOCKED |
| ATK-02 | Access soft-deleted via GET | ✅ SAFE |
| ATK-03 | Purge trash without auth | ⚠️ EXPOSED |
| ATK-04 | Double soft delete | ✅ SAFE |
| ATK-05 | Restore active note | 🚫 BLOCKED |
| ATK-06 | SQL injection via title | ✅ SAFE |
| ATK-07 | Overflow note ID | 🚫 BLOCKED |
| ATK-08 | Update soft-deleted note | 🚫 BLOCKED |
| ATK-09 | Race: restore + purge | 🚫 BLOCKED |
| ATK-10 | Concurrent soft delete | ✅ SAFE |
| ATK-11 | Title too long | ⚠️ EXPOSED |
| ATK-12 | Pin flood | ⚠️ EXPOSED |

**7 BLOCKED, 2 SAFE, 3 EXPOSED** — Critical: authenticate purge and scope to the actor's own data; add title/body length caps; cap pinned note count per user.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Hard-delete on first DELETE | No recovery path; accidental deletion is permanent |
| No `deleted_at IS NULL` filter in list/get queries | Soft-deleted items reappear as if still active |
| Allow `PUT` on soft-deleted notes | Ghost writes — users editing data they thought was deleted |
| No auth on `POST /trash/purge` | Any client irreversibly destroys all trashed data |
| Return 403 for soft-deleted note GET | Reveals the note exists; 404 prevents existence enumeration |
| No row-count check after soft-delete | Silent 200 when note not found; always check affected rows |
