# Field Trial 52 — Bulk Update with Partial Success (bulkupdatelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/bulkupdatelog/`
**NENE2 version**: 1.5.19
**Theme**: Bulk PATCH with per-item partial success, `WHERE id IN (...)` dynamic placeholders, backed enum tryFrom for validation, `execute()` return value for affected row counts

## Overview

Built a task management API with two bulk update patterns:
1. **Per-item status update** (`PATCH /tasks/status`): each `{id, status}` pair is processed independently; invalid status strings or missing IDs are recorded as failures. Returns `updated_count`, `failed_count`, `updated_ids`, `failed[]`.
2. **Single-status bulk update** (`PATCH /tasks/done`): marks all tasks with given IDs as done using `WHERE id IN (?)` with dynamic placeholders; silently skips unknown IDs.

## Endpoints Implemented

- `POST /tasks` — create task
- `GET /tasks` — list all tasks
- `PATCH /tasks/status` — per-item bulk status update (partial success)
- `PATCH /tasks/done` — bulk mark as done (IN clause)

## Test Results

14 tests, 33 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — Empty PHP array `[]` encodes as JSON array, causing 400 instead of 422 [LOW]

**Symptom**: Test sending `$this->req('POST', '/tasks', [])` expected 422 but got 400.

**Root cause**: PHP `[]` serializes to JSON `[]` (array), not `{}` (object). `JsonRequestBodyParser::parse()` rejects JSON arrays with a 400 `JsonBodyParseException` before the handler runs. The handler's own 422 validation is never reached.

**Fix**: Send a proper JSON object in the test: `['title' => '']` (empty string triggers handler's 422 validation).

**NENE2 impact**: This is an existing documented behavior of `JsonRequestBodyParser` (rejects non-object JSON). Good to note in test authoring: **always send `['key' => value]` not `[]` when testing missing-field 422 validation** — otherwise you test the wrong layer.

---

## Patterns Validated

### Dynamic IN placeholders for bulk update

```php
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$this->executor->execute(
    "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
    [$status->value, $now, ...$ids],
);
```

Spread operator `...$ids` unpacks the list into positional parameters. Works cleanly.

### `TaskStatus::tryFrom()` for validation

```php
$status = TaskStatus::tryFrom($item['status']);
if ($status === null) {
    $failed[] = ['id' => $id, 'error' => 'invalid status value'];
    continue;
}
```

Backed enum `tryFrom()` returns `null` for unknown strings — clean validation without a match/exception.

### Partial-success response (HTTP 200 even when all fail)

```php
return $this->json->create($result->toArray()); // HTTP 200
```

Bulk operations return HTTP 200 with a structured result including `updated_count`, `failed_count`, and per-item failure details. The caller inspects the body to determine success, not the HTTP status. This is the standard pattern for batch APIs.

### PHPStan: unused constructor parameter

Included `$txManager` in the constructor but never used it. PHPStan level 8 catches this:
```
Property SqliteTaskRepository::$txManager is never read, only written.
```
Fix: removed the unused parameter. PHPStan's `property.onlyWritten` rule is helpful for catching dead dependencies.

---

## NENE2 Changes Required

None. This field trial validated existing patterns without discovering actionable NENE2 changes. The JSON array vs object friction is a developer education issue, not a framework issue.
