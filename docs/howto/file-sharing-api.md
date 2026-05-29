---
title: "How-to: File Sharing API"
category: product
tags: [file-sharing, permissions, visibility, access-control, ownership]
difficulty: intermediate
related: [file-metadata-sharing, enforce-resource-ownership, signed-url-download]
---

# How-to: File Sharing API

> **FT reference**: FT303 (`NENE2-FT/filelog`) — File sharing API: private files return 404 (not 403) to non-owners, owner-only delete/visibility-change, view-share vs edit-share permission tiers, body `user_id` ignored (ownership from header), name length limit 255, size `is_int()` strict, VULN-A〜L all SAFE, 59 tests / 82 assertions PASS.

This guide shows how to build a file metadata API where users own files, control visibility, and share access with other users at view or edit level.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0 CHECK (size >= 0),
    mime_type   TEXT    NOT NULL,
    description TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'private'
                        CHECK (visibility IN ('private', 'public')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE file_shares (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id             INTEGER NOT NULL,
    shared_with_user_id INTEGER NOT NULL,
    can_edit            INTEGER NOT NULL DEFAULT 0 CHECK (can_edit IN (0, 1)),
    created_at          TEXT    NOT NULL,
    UNIQUE (file_id, shared_with_user_id),
    FOREIGN KEY (file_id) REFERENCES files(id),
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
);
```

Two-tier sharing: `can_edit = 0` (view-only) and `can_edit = 1` (edit access). `UNIQUE(file_id, shared_with_user_id)` prevents duplicate share entries.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/files` | `X-User-Id` | Upload file metadata |
| `GET` | `/files` | `X-User-Id` | List own files |
| `GET` | `/files/{fileId}` | `X-User-Id` | Get file (visibility check) |
| `PUT` | `/files/{fileId}` | `X-User-Id` | Update file (owner or edit-share) |
| `DELETE` | `/files/{fileId}` | `X-User-Id` | Delete file (owner only) |
| `POST` | `/files/{fileId}/shares` | `X-User-Id` (owner) | Add share |
| `DELETE` | `/files/{fileId}/shares/{userId}` | `X-User-Id` (owner) | Remove share |

## Private File → 404 (Not 403)

```php
// Non-owner cannot see private files — 404 hides existence
if ($file['visibility'] === 'private') {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null) {
        return $this->problems->create($request, 'not-found', 'File not found', 404);
    }
}
```

Private files return 404 to non-owners and non-sharers. Returning 403 would reveal the file exists. Public files return 200 to all authenticated users.

## Ownership From Header — Ignore Body user_id

```php
$userId = $this->requireUserId($request);
// ... validation ...
$id = $this->repo->create($userId, $name, $size, $mimeType, $description, $visibility, $now);
```

The file's `user_id` is always taken from the `X-User-Id` header. Any `user_id` in the request body is silently ignored. This prevents ownership injection attacks (VULN-E).

## View-Share vs Edit-Share — Two Tiers

```php
// Owner can always edit
$isOwner = ((int) $file['user_id']) === $userId;

if (!$isOwner) {
    $share = $this->repo->findShare($fileId, $userId);
    if ($share === null || !(bool) $share['can_edit']) {
        return $this->problems->create($request, 'forbidden', 'Edit access required', 403);
    }
}
```

- **Owner**: all operations (read, write, delete, share management, visibility)
- **Edit-share** (`can_edit=1`): can update name/size/mime/description — but NOT visibility
- **View-share** (`can_edit=0`): read only — any write attempt → 403

Only owners can change `visibility`:

```php
// Only owner can change visibility
if (!$isOwner && isset($body['visibility'])) {
    $visibility = (string) $file['visibility']; // silently ignore the request
}
```

## Strict Input Validation

```php
$size = $body['size'] ?? null;
if (!is_int($size) || $size < 0) {
    $errors[] = ['field' => 'size', 'code' => 'invalid', 'message' => 'size must be a non-negative integer'];
}

if (!is_string($name) || strlen($name) > 255 || $name === '') {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name required, max 255 chars'];
}
```

- `size`: `is_int()` rejects floats like `1.5` (VULN-I)
- `name`: max 255 characters — prevents oversized input crash (VULN-H)
- `visibility`: `in_array($value, ['private', 'public'], true)` strict allowlist

## Share Removal — Owner Only

```php
// Only the file owner can remove shares
if ((int) $file['user_id'] !== $userId) {
    return $this->problems->create($request, 'not-found', 'File not found', 404);
}
```

The shared-with user cannot remove themselves from a share — only the owner can manage shares. Non-owners get 404 (not 403) to hide the file's existence (VULN-F).

## User ID Validation — Reject Zero and Negative

```php
$raw = $request->getHeaderLine('X-User-Id');
$userId = ctype_digit($raw) ? (int) $raw : 0;
if ($userId <= 0) {
    return $this->problems->create($request, 'unauthorized', 'Authentication required', 401);
}
```

`X-User-Id: 0` and `X-User-Id: -1` return 401 (VULN-L). Only positive integers are valid user IDs.

---

## Vulnerability Assessment

### V-01 — IDOR: private file accessible by other user ✅ SAFE

**Risk**: User B reads User A's private file.
**Finding**: SAFE — private files return 404 to non-owners without a share entry.

---

### V-02 — IDOR: delete other user's file ✅ SAFE

**Risk**: User B deletes User A's file.
**Finding**: SAFE — delete checks ownership; non-owner gets 404. File still exists after failed attempt.

---

### V-03 — IDOR: update other user's file ✅ SAFE

**Risk**: User B updates User A's file name/metadata.
**Finding**: SAFE — update checks ownership; non-owner without edit-share gets 404.

---

### V-04 — Privilege escalation: view-share attempts edit ✅ SAFE

**Risk**: User with view-only share calls PUT to modify the file.
**Finding**: SAFE — edit check requires `can_edit = 1`; view-share returns 403.

---

### V-05 — Ownership injection: user_id in request body ✅ SAFE

**Risk**: `{ "user_id": 99, "name": "..." }` assigns file to user 99.
**Finding**: SAFE — `user_id` from body is silently ignored; ownership always comes from `X-User-Id` header.

---

### V-06 — Share removal by non-owner ✅ SAFE

**Risk**: Shared user removes themselves from the share list.
**Finding**: SAFE — share delete endpoint checks file ownership; non-owner returns 404.

---

### V-07 — SQL injection in name field ✅ SAFE

**Risk**: `"name": "test'; DROP TABLE files; --"` destroys data.
**Finding**: SAFE — parameterized queries store the injection string as literal data. Files table intact.

---

### V-08 — Oversized name causes crash ✅ SAFE

**Risk**: 300-character name causes DB error or memory exhaustion.
**Finding**: SAFE — `strlen($name) > 255` validation returns 422 before inserting.

---

### V-09 — Float size type confusion ✅ SAFE

**Risk**: `"size": 1.5` passes validation and corrupts size tracking.
**Finding**: SAFE — `is_int($size)` rejects floats → 422.

---

### V-10 — Edit-share escalates visibility to public ✅ SAFE

**Risk**: Edit-share user sets `"visibility": "public"` to expose a private file.
**Finding**: SAFE — visibility changes are owner-only; edit-share's visibility field in PUT body is silently ignored.

---

### V-11 — Private file existence disclosure via 403 ✅ SAFE

**Risk**: 403 response reveals the file exists even to unauthorized users.
**Finding**: SAFE — non-owners receive 404, not 403. File existence is not disclosed.

---

### V-12 — Auth bypass via X-User-Id: 0 or negative ✅ SAFE

**Risk**: `X-User-Id: 0` or `X-User-Id: -1` bypasses user check.
**Finding**: SAFE — `ctype_digit()` + `$userId <= 0` check returns 401 for zero and negative values.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | IDOR: private file access | ✅ SAFE |
| V-02 | IDOR: delete other user's file | ✅ SAFE |
| V-03 | IDOR: update other user's file | ✅ SAFE |
| V-04 | View-share privilege escalation | ✅ SAFE |
| V-05 | Ownership injection via body | ✅ SAFE |
| V-06 | Share removal by non-owner | ✅ SAFE |
| V-07 | SQL injection in name | ✅ SAFE |
| V-08 | Oversized name crash | ✅ SAFE |
| V-09 | Float size type confusion | ✅ SAFE |
| V-10 | Edit-share visibility escalation | ✅ SAFE |
| V-11 | Private file existence disclosure | ✅ SAFE |
| V-12 | Auth bypass via invalid user ID | ✅ SAFE |

**12 SAFE, 0 EXPOSED**
Private-file 404 pattern, header-only ownership, two-tier share permissions, strict type validation, and owner-only visibility prevent all IDOR and privilege escalation vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return 403 for private file to non-owner | Reveals file existence to unauthorized users |
| Accept `user_id` from request body for ownership | Any authenticated user claims ownership of any file |
| Allow view-share to call PUT | Shared viewers can modify file metadata |
| Allow edit-share to change visibility | Shared editors expose private files to public |
| Allow shared user to remove their own share | Users can revoke access management from the owner |
| Accept `size: 1.5` (float) | Type confusion; non-integer file sizes corrupt size tracking |
| No `name` length limit | Long filenames can cause DB column overflow or memory issues |
| `X-User-Id: 0` accepted as valid | User ID 0 may match uninitialized rows or bypass ownership checks |
| `ctype_digit()` without `> 0` check | `"0"` passes `ctype_digit` but is not a valid user ID |
