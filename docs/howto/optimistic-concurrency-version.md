---
title: "How-to: Optimistic Concurrency Control (Version Field)"
category: database
tags: [optimistic-locking, concurrency, version-field, lost-update]
difficulty: intermediate
related: [optimistic-locking, optimistic-lock-patch-version, optimistic-locking-etag]
---

# How-to: Optimistic Concurrency Control (Version Field)

> **FT reference**: FT323 (`NENE2-FT/optimisticlog`) — Document API with version field in PUT body, 409 on stale version, lost-update prevention, 18 tests / 34 assertions PASS.

This guide shows how to implement optimistic concurrency control by passing a `version` field in the request body, as an alternative to HTTP ETag/If-Match headers.

## Schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/documents` | Create (version starts at 1) |
| `GET`  | `/documents` | List |
| `GET`  | `/documents/{id}` | Get with version |
| `PUT`  | `/documents/{id}` | Update (version required in body) |

## Create

```php
POST /documents  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}
```

## Update with Version

The client reads the current `version` and includes it in the PUT body:

```php
// Read
GET /documents/1
→ 200  {"id": 1, "title": "Hello", "version": 1}

// Update with correct version
PUT /documents/1
{"title": "Updated", "body": "new body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

Version increments on every successful update.

## Stale Version — 409 Conflict

```php
// Alice and Bob both read version 1
// Alice updates first → version becomes 2
// Bob tries to update with version 1 → rejected
PUT /documents/1
{"title": "Bob's edit", "version": 1}
→ 409 Conflict  {"current_version": 2, "submitted_version": 1}

// Bob re-reads, gets version 2, retries
PUT /documents/1
{"title": "Bob's edit", "version": 2}
→ 200  {"version": 3}
```

## Implementation

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    if (!is_int($version) || $version < 1) {
        return $this->json->create(['error' => 'version is required'], 422);
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($doc['version'] !== $version) {
        return $this->problems->create('conflict', 'Stale version', 409, [
            'current_version'   => $doc['version'],
            'submitted_version' => $version,
        ]);
    }

    $newVersion = $version + 1;
    // UPDATE documents SET ... WHERE id = ? AND version = ?
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated);
}
```

The `WHERE version = ?` clause in the UPDATE query is the atomic guard against concurrent writes.

## Version vs ETag

| Aspect | Version Field (this guide) | ETag / If-Match (see `optimistic-locking-etag.md`) |
|--------|---------------------------|-----------------------------------------------------|
| Protocol | Body field | HTTP header |
| Client UX | Explicit `"version": N` in JSON | `If-Match: "vN"` header |
| 409 payload | Can return `current_version` | 412 — no body standard |
| Missing check | 422 (missing `version`) | 428 (missing `If-Match`) |

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept PUT without `version` field | Lost updates: last write wins silently |
| Return 200 on stale version | Silent overwrite of concurrent changes |
| Only check version in application code (not WHERE clause) | Race condition between read and write |
| Not include `current_version` in 409 response | Client must re-GET to recover; include it for faster retry |
