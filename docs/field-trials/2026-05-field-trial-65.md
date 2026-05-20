# Field Trial 65 — Priority Job Queue with Claim/Complete/Fail

**Date**: 2026-05-20
**Theme**: Priority-ordered job queue with claim semantics, status lifecycle, and query param filtering
**Project**: `/home/xi/docker/NENE2-FT/queuelog/`
**NENE2 version**: 1.5.21

---

## Summary

Implemented a job queue API where workers claim the highest-priority pending job, then mark it as completed or failed. Tests priority ordering (critical > high > medium > low), FIFO tie-breaking at equal priority, and 409 rejection of transitions that violate the status lifecycle.

**Result**: 20 tests, 36 assertions — all pass. PHPStan level 8 clean. CS-Fixer clean. No NENE2 changes required.

---

## What Was Built

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/jobs` | Create a job (type, payload, priority) |
| `GET` | `/jobs` | List all jobs (optional `?status=` filter) |
| `GET` | `/jobs/{id}` | Get a single job |
| `POST` | `/jobs/claim` | Claim the highest-priority pending job |
| `POST` | `/jobs/{id}/complete` | Mark a running job as completed |
| `POST` | `/jobs/{id}/fail` | Mark a running job as failed (with error message) |

### Domain Model

```
jobs(id, type, payload JSON, priority INT, status TEXT, claimed_at, worker_id, error, created_at, updated_at)
```

### Enums

**JobPriority** (integer-backed): Low=0, Medium=10, High=20, Critical=30

Integer backing enables simple `ORDER BY priority DESC` SQL without mapping. A custom `fromLabel(string)` factory converts API string values to the enum.

**JobStatus** (string-backed): pending, running, completed, failed

`JobStatus::tryFrom()` provides clean validation of query param values.

### Claim Semantics

```sql
SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1
```

Fetch the best candidate, then immediately UPDATE to `running`. This is a simple read-then-update pattern (not true atomic SELECT FOR UPDATE, since SQLite doesn't support it). For production with concurrent workers, a transaction + SELECT FOR UPDATE with a real DB would be needed.

### Status Lifecycle

```
pending → running → completed
                 → failed
```

`complete()` and `fail()` check `$job->status !== JobStatus::Running` and return null → 409 if the job is not running. This enforces the transition contract without needing a state machine enum.

---

## Frictions Encountered

### None

FT65 was completely friction-free against NENE2 1.5.21.

**Routing coexistence** (`POST /jobs/claim` alongside `GET /jobs/{id}`): No conflict. The router correctly matches by both method and path — `GET /jobs/claim` would route to `GET /jobs/{id}` with id="claim" (casting to 0, returning 404), which is acceptable behavior.

**Query param filtering** (`?status=pending`): `$request->getQueryParams()` works as expected. `JobStatus::tryFrom()` provides clean validation — unknown values return null → 422.

**Integer-backed enum for priority ordering**: `ORDER BY priority DESC` works directly with integer values stored in SQLite. The `fromLabel()` / `label()` pair bridges the int storage to human-readable API strings cleanly.

---

## Patterns Validated

### Pattern: Integer-Backed Enum for Ordering

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self { ... }
    public function label(): string { ... }
}
```

The integer gap between levels (10, 20, 30) allows inserting new priorities without renumbering existing data.

### Pattern: Claim with Status Guard

```php
public function complete(int $id, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;  // controller returns 409
    }
    // ... UPDATE
}
```

Null return from repository → 409 in controller is a consistent pattern for "operation not applicable to this resource's current state."

### Pattern: Enum tryFrom() for Query Param Validation

```php
$status = JobStatus::tryFrom($params['status']);
if ($status === null) {
    return $this->problems->create($request, 'validation-failed', ..., 422, ...);
}
```

Avoids manual string comparison. Invalid enum value → 422 with helpful error message.

---

## No NENE2 Changes Required

All patterns implementable with NENE2 1.5.21 as-is:
- `getQueryParams()` for `?status=` filter
- `Router::PARAMETERS_ATTRIBUTE` for path params
- `JsonRequestBodyParser::parse()` for request bodies
- `JsonResponseFactory::create()` for all responses (job objects and `{"jobs": [...]}` envelope)
- `ProblemDetailsResponseFactory::create()` for 404/409/422 errors
