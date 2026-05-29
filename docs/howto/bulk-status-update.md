---
title: "How-to: Bulk Status Update API"
category: api-design
tags: [bulk, status-update, batch, state-machine]
difficulty: intermediate
related: [bulk-operations-partial-success, batch-api-partial-success, implement-bulk-endpoint]
---

# How-to: Bulk Status Update API

> **FT reference**: FT85 (`NENE2-FT/bulkupdatelog`) — Bulk Status Update API
> **VULN**: FT231 — security / vulnerability assessment (V-01 through V-10)

Demonstrates two patterns for bulk status mutation: per-item updates (each item gets its
own target status) and homogeneous bulk update (all items get the same status). Both
support partial success — the response reports which IDs succeeded and which failed.

---

## Routes

| Method  | Path             | Description                                         |
|---------|------------------|-----------------------------------------------------|
| `POST`  | `/tasks`         | Create a task                                       |
| `GET`   | `/tasks`         | List all tasks                                      |
| `PATCH` | `/tasks/status`  | Per-item bulk status update (mixed target statuses) |
| `PATCH` | `/tasks/done`    | Mark a set of IDs as done (single target status)    |

---

## Per-item bulk update (`PATCH /tasks/status`)

Each update item specifies its own target status:

```json
{
  "updates": [
    {"id": 1, "status": "done"},
    {"id": 2, "status": "cancelled"},
    {"id": 3, "status": "in_progress"}
  ]
}
```

The repository processes each item individually, accumulating successes and failures:

```php
public function bulkUpdateStatus(array $items, string $now): BulkUpdateResult
{
    $updatedIds = [];
    $failed     = [];

    foreach ($items as $item) {
        $itemArr = is_array($item) ? $item : [];
        $id      = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
        $status  = isset($itemArr['status']) && is_string($itemArr['status'])
            ? TaskStatus::tryFrom($itemArr['status'])
            : null;

        if ($id === null) {
            $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
            continue;
        }

        if ($status === null) {
            $failed[] = ['id' => $id, 'error' => 'invalid status value'];
            continue;
        }

        $affected = $this->executor->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $id],
        );

        if ($affected === 0) {
            $failed[] = ['id' => $id, 'error' => 'task not found'];
        } else {
            $updatedIds[] = $id;
        }
    }

    return new BulkUpdateResult($updatedIds, $failed);
}
```

### Response structure

```json
{
  "updated": [1, 3],
  "failed": [
    {"id": 2, "error": "task not found"}
  ]
}
```

HTTP status is always `200 OK` — even when all items fail. The caller must inspect
`failed` to detect per-item errors.

---

## Homogeneous bulk update (`PATCH /tasks/done`)

All IDs move to the same target status in a single `UPDATE ... WHERE id IN (?)`:

```php
// Body: {"ids": [1, 2, 3]}
$ids = isset($body['ids']) && is_array($body['ids'])
    ? array_values(array_filter($body['ids'], static fn (mixed $v): bool => is_int($v)))
    : [];

if ($ids === []) {
    return $this->json->create(['error' => 'ids array is required and must not be empty'], 422);
}
```

Non-integer values are silently filtered via `array_filter(..., is_int(...))`. After
filtering, if the result is empty, a 422 is returned.

```php
public function bulkSetStatus(array $ids, TaskStatus $status, string $now): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->executor->execute(
        "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
        [$status->value, $now, ...$ids],
    );

    // Return IDs that exist and now have the target status
    $rows = $this->executor->fetchAll(
        "SELECT id FROM tasks WHERE id IN ({$placeholders}) AND status = ?",
        [...$ids, $status->value],
    );

    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}
```

`implode(',', array_fill(0, count($ids), '?'))` generates the correct number of `?`
placeholders — safe, parameterised.

---

## Status allowlist (backed enum)

`TaskStatus` is a backed string enum with four cases:

```php
enum TaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';
}
```

`TaskStatus::tryFrom($string)` returns `null` for unknown status values, which the
bulk handler maps to a per-item failure. The schema adds `CHECK(status IN (...))` as
a DB-level backstop.

---

## Schema

```sql
CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending'
                             CHECK(status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

---

## VULN — Security assessment (FT231)

### V-01 — No authentication on any endpoint

**Attack**: Bulk-cancel all tasks without credentials.

```json
{"updates": [{"id": 1, "status": "cancelled"}, {"id": 2, "status": "cancelled"}]}
```

**Observed**: `200 OK` — no token required.

**Verdict**: **EXPOSED** (by design for FT85 demo). Add authentication and authorisation
in production. Restrict bulk mutations to the task owner or an admin role.

---

### V-02 — Mass update DoS (huge array)

**Attack**: Send an `updates` array with thousands of items to exhaust CPU or memory.

```python
{"updates": [{"id": i, "status": "done"} for i in range(100_000)]}
```

**Observed**: Processed in a loop — each item runs one `UPDATE` query. For 100,000
items, this executes 100,000 individual SQL statements in a tight loop without a batch
size limit.

**Verdict**: **EXPOSED** — add a maximum batch size limit:
```php
$maxBatchSize = 500;
if (count($updates) > $maxBatchSize) {
    return $this->json->create(['error' => "Batch size must not exceed {$maxBatchSize} items."], 422);
}
```

---

### V-03 — SQL injection via `IN` clause

**Attack**: Try to inject SQL through the `ids` array used in `IN (?)`.

```json
{"ids": ["1; DROP TABLE tasks; --", 1, 2]}
```

**Observed**: The string `"1; DROP TABLE tasks; --"` is rejected by the `is_int()` filter
in `array_filter()`. Only integers reach the `IN` clause. The `implode` + `array_fill`
pattern generates the correct number of `?` placeholders — no string concatenation of
user data.

**Verdict**: **BLOCKED** — `is_int()` filter + parameterised `IN` clause prevents injection.

---

### V-04 — Non-integer IDs in per-item updates

**Attack**: Send non-integer `id` values in the `updates` array.

```json
{"updates": [{"id": "1", "status": "done"}, {"id": null, "status": "done"}]}
```

**Observed**: Both items are added to `$failed` with `'error' => 'id must be an integer'`.
`is_int()` rejects strings and `null`.

**Verdict**: **BLOCKED** — strict `is_int()` type check per item.

---

### V-05 — Invalid status value

**Attack**: Send an unknown status string in the `updates` array.

```json
{"updates": [{"id": 1, "status": "hacked"}]}
```

**Observed**: Item added to `$failed` with `'error' => 'invalid status value'`. 
`TaskStatus::tryFrom("hacked")` returns `null`.

**Verdict**: **BLOCKED** — backed enum `tryFrom()` rejects unknown values.

---

### V-06 — Empty array

**Attack**: Send an empty `updates` or `ids` array.

```json
{"updates": []}
{"ids": []}
```

**Observed**: Both return `422 Unprocessable Entity` with an error message.

**Verdict**: **BLOCKED** — empty array check before processing.

---

### V-07 — Duplicate IDs in the same batch

**Attack**: Include the same `id` multiple times in one request.

```json
{"updates": [{"id": 1, "status": "done"}, {"id": 1, "status": "cancelled"}]}
```

**Observed**: Both updates succeed. The second UPDATE overwrites the first — the task ends
up as `cancelled`. No deduplication occurs.

**Verdict**: **ACCEPTED BY DESIGN** — last-write-wins semantics are consistent for
simple task management. If conflicts should be rejected, deduplicate `ids` before
processing and return an error on duplicates.

---

### V-08 — Negative and zero IDs

**Attack**: Send IDs `0` or `-1`.

```json
{"ids": [0, -1]}
```

**Observed**: `is_int(0)` = true, `is_int(-1)` = true — both pass the filter.
The UPDATE runs with `WHERE id IN (0, -1)`, which matches no rows. Response:
`{"requested": 2, "updated": 0, "ids": []}`.

**Verdict**: **BLOCKED** in effect (no rows affected). No error is returned for
non-existent IDs — this is consistent with the partial-success pattern. Add a
positive-integer guard if negative IDs should be rejected with 422.

---

### V-09 — Bulk update silently skips non-existent tasks

**Attack**: Include IDs that don't exist in the database.

```json
{"ids": [99999, 100000]}
```

**Observed**: `{"requested": 2, "updated": 0, "ids": []}` — no error, no indication
that the tasks don't exist.

**Verdict**: **ACCEPTED BY DESIGN** — partial-success model. Document this behaviour
in the API spec. If callers need to distinguish "no such task" from "task already in
target state", the response can include a `not_found` list.

---

### V-10 — Concurrent bulk updates on the same IDs

**Attack**: Send two simultaneous `PATCH /tasks/done` requests for the same set of IDs.

**Observed**: Both UPDATE statements run on the DB. SQLite's row-level locking means
one UPDATE completes first, then the second UPDATE runs on already-`done` rows. Both
responses return `updated` IDs (since the rows still exist with `status = done`).

**Verdict**: **BLOCKED** — idempotent writes. Both requests produce the same result
(all IDs set to `done`). For `status` updates where the target status differs per
caller, concurrent writes use last-write-wins.

---

## VULN summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| V-01 | No authentication | EXPOSED (by design) |
| V-02 | Mass update DoS (huge array) | EXPOSED |
| V-03 | SQL injection via `IN` clause | BLOCKED |
| V-04 | Non-integer IDs | BLOCKED |
| V-05 | Invalid status value | BLOCKED |
| V-06 | Empty array | BLOCKED |
| V-07 | Duplicate IDs in batch | ACCEPTED BY DESIGN |
| V-08 | Negative/zero IDs | BLOCKED |
| V-09 | Non-existent tasks silently skipped | ACCEPTED BY DESIGN |
| V-10 | Concurrent bulk updates | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **V-01** — Add authentication and authorisation
2. **V-02** — Add a maximum batch size limit (e.g. 500 items)

---

## Related howtos

- [`implement-bulk-endpoint.md`](implement-bulk-endpoint.md) — bulk create with per-item errors
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — partial success patterns
- [`approval-workflow.md`](approval-workflow.md) — status transitions with enum guard
