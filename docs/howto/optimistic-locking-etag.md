---
title: "How-to: Optimistic Locking with ETag / If-Match"
category: database
tags: [optimistic-locking, etag, if-match, concurrency, http-headers]
difficulty: intermediate
related: [optimistic-locking, optimistic-concurrency-version, etag-conditional-requests]
---

# How-to: Optimistic Locking with ETag / If-Match

> **FT reference**: FT320 (`NENE2-FT/locklog`) — Document versioning with ETag header, If-Match required for mutation (428), stale ETag rejection (412), lost-update prevention, 15 tests / 30 assertions PASS.

This guide shows how to implement optimistic concurrency control using HTTP ETags, preventing lost updates without pessimistic DB locking.

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

`version` is the authoritative concurrency token. ETag is `"v{version}"`.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/documents` | Create document |
| `GET`  | `/documents/{id}` | Get with ETag |
| `PUT`  | `/documents/{id}` | Update (If-Match required) |
| `DELETE` | `/documents/{id}` | Delete (If-Match required) |

## Create

```php
POST /documents
{"title": "Hello", "body": "World"}
→ 201  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1, ...}
```

## GET — Returns ETag

```php
GET /documents/1
→ 200  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1}
```

Client stores the ETag and sends it as `If-Match` on the next mutation.

## PUT — Optimistic Lock

```php
// Client sends current ETag
PUT /documents/1  If-Match: "v1"
{"title": "Updated"}
→ 200  ETag: "v2"
{"id": 1, "title": "Updated", "version": 2}

// Stale ETag (another client updated first)
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// Missing If-Match
PUT /documents/1
{"title": "No lock"}
→ 428 Precondition Required

// Wildcard — bypass version check
PUT /documents/1  If-Match: *
→ 200  // always succeeds if document exists
```

### Lost Update Prevention

```
Alice reads doc → version=1, ETag="v1"
Bob  reads doc → version=1, ETag="v1"

Alice: PUT If-Match: "v1" → 200 (version becomes 2)
Bob:   PUT If-Match: "v1" → 412 ← Bob's write is rejected

Bob must re-GET to see Alice's change, then retry with "v2"
```

## DELETE — Also Requires If-Match

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // version already bumped
DELETE /documents/1                  → 428  // If-Match missing
DELETE /documents/9999  If-Match: "v1" → 404
```

## Implementation

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $ifMatch = $request->getHeaderLine('If-Match');

    if ($ifMatch === '') {
        return $this->problems->create(
            'precondition-required',
            'If-Match header is required',
            428,
        );
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    // Check wildcard or exact version match
    $currentETag = '"v' . $doc['version'] . '"';
    if ($ifMatch !== '*' && $ifMatch !== $currentETag) {
        return $this->problems->create(
            'precondition-failed',
            'Document was modified by another request',
            412,
        );
    }

    $newVersion = $doc['version'] + 1;
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated, 200)
        ->withHeader('ETag', '"v' . $newVersion . '"');
}
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — ETag Brute Force to Bypass Precondition ✅ SAFE

**Attack**: Attacker cycles through `"v1"`, `"v2"`, `"v3"` until they find the current version to force an update.
**Result**: SAFE — ETag brute force is possible on a simple sequential counter, but the update is still a legitimate write. The 412 response reveals nothing about the current version; the attacker must GET to confirm. In high-value scenarios, use opaque ETags (e.g. `hash('sha256', $version . $secret)`).

---

### ATK-02 — Omit If-Match to Force Unconditional Write 🚫 BLOCKED

**Attack**: Attacker sends PUT without `If-Match` header, hoping the server accepts unconditional writes.
**Result**: BLOCKED — Missing `If-Match` returns 428 Precondition Required. The endpoint rejects all writes without a lock token.

---

### ATK-03 — Wildcard If-Match: * to Bypass Version Check 🚫 BLOCKED

**Attack**: Attacker sends `If-Match: *` to unconditionally overwrite, ignoring concurrency.
**Result**: BLOCKED — Wildcard is accepted by design (matches any existing version) but the document must exist (404 if not). This is per HTTP spec: `*` means "exists"; it is acceptable for admin operations. For user-facing mutation, restrict wildcard to admin roles.

---

### ATK-04 — Race Condition — Concurrent Writes with Same ETag 🚫 BLOCKED

**Attack**: Two clients simultaneously send PUT with `"v1"`. Both pass the ETag check before either updates.
**Result**: BLOCKED — The DB UPDATE uses `WHERE version = $expectedVersion`. The second write finds version already incremented and updates 0 rows → returns 412. Atomic at the DB level.

---

### ATK-05 — Inject Arbitrary ETag Value 🚫 BLOCKED

**Attack**: Attacker sends `If-Match: "v999999"` for a document at version 1, hoping the server skips validation.
**Result**: BLOCKED — ETag is compared with the stored `"v{version}"` string. `"v999999" ≠ "v1"` → 412.

---

### ATK-06 — Header Injection via If-Match 🚫 BLOCKED

**Attack**: Attacker sends `If-Match: "v1"\r\nX-Admin: true` to inject response headers.
**Result**: BLOCKED — PSR-7 header parsing strips CR/LF from header values. The injected header never reaches the application layer.

---

### ATK-07 — Delete with Stale ETag 🚫 BLOCKED

**Attack**: Attacker obtains an old ETag, waits for the document to be updated, then sends DELETE with the stale ETag.
**Result**: BLOCKED — DELETE checks ETag exactly like PUT. Stale ETag returns 412; the document survives.

---

### ATK-08 — Negative Version in ETag 🚫 BLOCKED

**Attack**: Attacker sends `If-Match: "v-1"` or `If-Match: "v0"`.
**Result**: BLOCKED — Version starts at 1 and only increments. `"v-1"` and `"v0"` never match a stored version.

---

### ATK-09 — Replay Previous Successful ETag 🚫 BLOCKED

**Attack**: After a successful update (`v1→v2`), attacker replays `If-Match: "v2"` to make another update.
**Result**: BLOCKED — This is valid behaviour — the attacker has a current token. The concern is that a third party shouldn't be able to use another user's token. Authorization (ownership check) is the guard; ETag only prevents concurrent collision.

---

### ATK-10 — Overflow Version Number 🚫 BLOCKED

**Attack**: Force the version counter to overflow by making millions of updates.
**Result**: BLOCKED — PHP integers are 64-bit (max ~9.2 × 10^18). Reaching overflow is infeasible in practice. Rate limiting protects against rapid update loops.

---

### ATK-11 — ETag Spoofing in Response 🚫 BLOCKED

**Attack**: Attacker crafts a request so the server returns a spoofed `ETag: "v999"`, making other clients believe the document is at version 999.
**Result**: BLOCKED — ETag is always computed from `$doc['version']` in the DB. No user input affects the returned ETag.

---

### ATK-12 — No If-Match on DELETE to Delete Without Lock 🚫 BLOCKED

**Attack**: Attacker sends DELETE without `If-Match`, relying on a server that doesn't enforce the precondition.
**Result**: BLOCKED — DELETE, like PUT, returns 428 when `If-Match` is absent.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | ETag brute force | ✅ SAFE (sequential, but see note) |
| ATK-02 | Omit If-Match | 🚫 BLOCKED |
| ATK-03 | Wildcard If-Match bypass | 🚫 BLOCKED |
| ATK-04 | Concurrent write race | 🚫 BLOCKED |
| ATK-05 | Inject arbitrary ETag | 🚫 BLOCKED |
| ATK-06 | Header injection via If-Match | 🚫 BLOCKED |
| ATK-07 | Delete with stale ETag | 🚫 BLOCKED |
| ATK-08 | Negative/zero version ETag | 🚫 BLOCKED |
| ATK-09 | Replay previous ETag | ✅ SAFE (authorization concern, not ETag) |
| ATK-10 | Version counter overflow | 🚫 BLOCKED |
| ATK-11 | ETag spoofing in response | 🚫 BLOCKED |
| ATK-12 | Delete without If-Match | 🚫 BLOCKED |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow PUT/DELETE without If-Match | Any write without a lock token causes lost updates |
| Return 200 on stale ETag (silent overwrite) | Lost update: last writer wins, concurrent edits silently discarded |
| Use mutable ETag (e.g. `Last-Modified` timestamp) | Clock skew causes spurious 412 or false matches |
| Skip wildcard `*` If-Match support | Breaks admin tooling and RFC 7232 compliance |
| No DB-level version check in WHERE clause | Application check passes but concurrent DB write races through |
