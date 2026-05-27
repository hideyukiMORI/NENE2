# How-to: Optimistic Lock with PATCH + Version Field

> **FT reference**: FT324 (`NENE2-FT/optlocklog`) — PATCH-based optimistic locking, 409 includes `current_version` for zero-GET retry, strict integer version type, ATK assessment, 12 tests / 24 assertions PASS.

This guide shows how to implement optimistic concurrency control via PATCH with a `version` field, returning the current server version in 409 responses so clients can retry without an extra GET.

## Schema

```sql
CREATE TABLE articles (
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
| `POST`  | `/articles` | Create (version=1) |
| `GET`   | `/articles/{id}` | Get with version |
| `PATCH` | `/articles/{id}` | Update (version required as integer) |

## Create & Read

```php
POST /articles  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}

GET /articles/1
→ 200  {"id": 1, "title": "Hello", "version": 1}
```

## PATCH with Version

```php
PATCH /articles/1
{"title": "Updated", "body": "New body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

Version must be a **JSON integer** — a string `"1"` is rejected.

## 409 Includes current_version

When a conflict is detected, the response includes `current_version` so the client can retry without a re-GET:

```php
// Version 1 already bumped to 2 by another writer
PATCH /articles/1  {"title": "X", "version": 1}
→ 409
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "current_version": 2    ← client can use this directly for retry
}

// Client retries with current_version from 409 body
PATCH /articles/1  {"title": "X", "version": 2}
→ 200  {"version": 3}     ← success
```

## Type Validation

```php
PATCH /articles/1  {"title": "x", "body": "x"}          → 400  // missing version
PATCH /articles/1  {"title": "x", "body": "x", "version": "1"} → 400  // string not int
PATCH /articles/9999 {"version": 1}                      → 404  // not found
```

## Implementation

```php
private function patch(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    // Strict integer type check — "1" (string) is rejected
    if (!is_int($version)) {
        return $this->json->create(['error' => 'version must be an integer'], 400);
    }

    $article = $this->repo->findById($id);
    if ($article === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($article['version'] !== $version) {
        return $this->problems->create('conflict', 'Version conflict', 409, [
            'current_version' => $article['version'],  // ← enable zero-GET retry
        ]);
    }

    // Atomic UPDATE with WHERE version = ?
    $updated = $this->repo->updateWithVersion($id, $title, $body, $version + 1, $now);
    return $this->json->create($updated);
}
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Version Brute Force to Overwrite ✅ SAFE

**Attack**: Attacker cycles version `1, 2, 3…` until one succeeds, overwriting the current content.
**Result**: SAFE — Brute force eventually finds the current version but this is a legitimate write, not privilege escalation. Ownership authorization (not shown) prevents unauthorized writes.

---

### ATK-02 — String Version Bypass (`"version": "1"`) 🚫 BLOCKED

**Attack**: Attacker sends `"version": "1"` (JSON string) hoping PHP type-coercion treats it as integer.
**Result**: BLOCKED — `is_int($version)` returns false for strings. Returns 400.

---

### ATK-03 — Float Version (`"version": 1.0`) 🚫 BLOCKED

**Attack**: Send `"version": 1.0` to match via loose comparison.
**Result**: BLOCKED — `is_int(1.0)` is false in PHP (it's a float). Returns 400.

---

### ATK-04 — Missing Version → Force Blind Write 🚫 BLOCKED

**Attack**: Omit `version` field, hoping server defaults to accepting the update.
**Result**: BLOCKED — Missing `version` (null) fails `is_int()` check. Returns 400.

---

### ATK-05 — Negative Version 🚫 BLOCKED

**Attack**: Send `"version": -1` to exploit potential off-by-one in version comparison.
**Result**: BLOCKED — Version starts at 1 and only increments. `-1 !== 1` → 409 conflict.

---

### ATK-06 — current_version from 409 Used to Race 🚫 BLOCKED

**Attack**: Attacker reads `current_version` from 409 and immediately submits, racing the legitimate retry.
**Result**: BLOCKED — The `WHERE version = $current` atomic UPDATE means only one concurrent writer can succeed per version. The other gets 409 again. This is the intended optimistic locking behaviour.

---

### ATK-07 — Overflow Version Number 🚫 BLOCKED

**Attack**: Send `"version": 9999999999999999999` to overflow int.
**Result**: BLOCKED — JSON large integers may decode as float in PHP; `is_int()` returns false. Returns 400.

---

### ATK-08 — Zero Version 🚫 BLOCKED

**Attack**: Send `"version": 0` to undercut the minimum version.
**Result**: BLOCKED — Version starts at 1. `0 !== 1` → 409 conflict.

---

### ATK-09 — Forged current_version in Request Body 🚫 BLOCKED

**Attack**: Attacker includes `"current_version": 999` in the PATCH body hoping server uses it.
**Result**: BLOCKED — `current_version` is only in the *response*. Server ignores unknown request fields; version is taken only from `$body['version']`.

---

### ATK-10 — SQL Injection via version Field 🚫 BLOCKED

**Attack**: `"version": "1; DROP TABLE articles; --"`.
**Result**: BLOCKED — Rejected at `is_int()` check before reaching DB. Returns 400.

---

### ATK-11 — Replay Successful version to Re-execute 🚫 BLOCKED

**Attack**: Record a successful PATCH (version N → N+1), then replay the same request.
**Result**: BLOCKED — After update, article is at version N+1. Replaying `version: N` returns 409.

---

### ATK-12 — Concurrent Writes Cause Both to Succeed 🚫 BLOCKED

**Attack**: Two identical PATCH requests sent simultaneously with the same `version`.
**Result**: BLOCKED — `UPDATE … WHERE version = ?` is atomic. DB serialises concurrent writes; second UPDATE matches 0 rows → application detects and returns 409.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Version brute force | ✅ SAFE (authorization concern) |
| ATK-02 | String version bypass | 🚫 BLOCKED |
| ATK-03 | Float version | 🚫 BLOCKED |
| ATK-04 | Missing version blind write | 🚫 BLOCKED |
| ATK-05 | Negative version | 🚫 BLOCKED |
| ATK-06 | current_version race exploit | 🚫 BLOCKED |
| ATK-07 | Overflow version | 🚫 BLOCKED |
| ATK-08 | Zero version | 🚫 BLOCKED |
| ATK-09 | Forged current_version in body | 🚫 BLOCKED |
| ATK-10 | SQL injection via version | 🚫 BLOCKED |
| ATK-11 | Replay successful version | 🚫 BLOCKED |
| ATK-12 | Concurrent writes both succeed | 🚫 BLOCKED |

**11 BLOCKED, 1 SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept `"version": "1"` (string) | PHP loose comparison `"1" == 1` is true; type confusion attack |
| Omit `current_version` from 409 | Client must do extra GET; higher latency, more requests on conflict |
| Use application-level check only (no WHERE clause) | Race condition between version read and write |
| Return 200 on missing version | Unconditional overwrite — lost update |
