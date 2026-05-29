---
title: "How-to: PATCH Partial Update (JSON Merge Patch)"
category: api-design
tags: [patch, json-merge-patch, partial-update, etag, immutable-fields]
difficulty: intermediate
related: [implement-patch-endpoint, json-merge-patch, optimistic-lock-patch-version]
---

# How-to: PATCH Partial Update (JSON Merge Patch)

> **FT reference**: FT326 (`NENE2-FT/patchlog`) — JSON Merge Patch (RFC 7396) partial update: null field reset, immutable field rejection, ETag/If-Match, owner-only mutation, 42 tests / 141 assertions PASS.

This guide shows how to implement a `PATCH` endpoint following JSON Merge Patch semantics: only provided fields are updated, `null` resets to default, and immutable fields are rejected.

## Schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST`  | `/documents` | Create (requires `X-User-Id`) |
| `GET`   | `/documents` | List |
| `GET`   | `/documents/{id}` | Get with ETag header |
| `PATCH` | `/documents/{id}` | Partial update (requires `X-User-Id`) |
| `DELETE`| `/documents/{id}` | Delete (owner only) |

## Create

```php
POST /documents  X-User-Id: 1
{"title": "My Doc", "body": "Content"}
→ 201  {"id": 1, "owner_id": 1, "title": "My Doc", "status": "draft", "version": 1}

// No X-User-Id → 401
// Missing title → 422
// Empty title  → 422
// body is optional → defaults to ""
```

## GET with ETag

```php
GET /documents/1
→ 200  ETag: "doc-1-1"
{"id": 1, "title": "My Doc", "version": 1, ...}
```

ETag format: `"doc-{id}-{version}"`.

## PATCH — JSON Merge Patch Semantics

```php
// Update only title — body unchanged
PATCH /documents/1  X-User-Id: 1
{"title": "Updated"}
→ 200  {"title": "Updated", "body": "Content", ...}

// Update body only
PATCH /documents/1  X-User-Id: 1
{"body": "New content"}
→ 200  {"title": "Updated", "body": "New content", ...}

// Empty {} — no-op (valid per RFC 7396 §3)
PATCH /documents/1  X-User-Id: 1
{}
→ 200  (unchanged document)

// null resets field to default
PATCH /documents/1  X-User-Id: 1
{"status": null}
→ 200  {"status": "draft"}   // reset to default
```

## Immutable Fields — Rejected

Some fields must never be changed via PATCH:

```php
PATCH /documents/1  {"id": 999}         → 422  // immutable
PATCH /documents/1  {"owner_id": 99}    → 422  // immutable
PATCH /documents/1  {"version": 999}    → 422  // immutable
PATCH /documents/1  {"created_at": "…"} → 422  // immutable
```

## Owner-Only Authorization

```php
// User 2 tries to patch user 1's document → 404 (not 403, to prevent enumeration)
PATCH /documents/1  X-User-Id: 2  {"title": "Stolen"}  → 404

// Owner can always patch their own
PATCH /documents/1  X-User-Id: 1  {"title": "Mine"}    → 200
```

## ETag / If-Match

```php
// Conditional PATCH — 412 if version changed
PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Updated"}
→ 200  // if version still 1

PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Stale"}
→ 412  // if version is now 2
```

## Type Validation

```php
PATCH /documents/1  {"title": 123}   → 422  // int instead of string
PATCH /documents/1  {"body": [1,2]}  → 422  // array instead of string
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Treat missing field same as `null` | Caller cannot clear a field; `undefined` ≠ `null` in Merge Patch |
| Allow patching `owner_id` | Ownership transfer via API without authorization flow |
| Return 403 for cross-owner access | Reveals existence of document; return 404 instead |
| Replace entire document on PATCH | Overwrites fields client didn't intend to change |
| Accept immutable fields silently (no-op) | Client believes they changed `id`; silent failure causes confusion |
