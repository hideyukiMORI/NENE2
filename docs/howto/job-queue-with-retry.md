# How-to: Background Job Queue with Retry and Idempotency

> **FT reference**: FT255 (`NENE2-FT/queuelog`) — Background Job Queue with Retry and Idempotency
> **VULN**: FT255 — vulnerability assessment (V-01 through V-10)

Demonstrates a persistent job queue backed by SQLite. Jobs have priority levels,
move through a `pending → running → completed|failed` state machine, and support
automatic retry on failure with a configurable retry limit. An idempotency key
prevents duplicate job creation. Includes a full vulnerability assessment.

---

## Routes

| Method | Path                    | Description                               |
|--------|-------------------------|-------------------------------------------|
| `POST` | `/jobs`                 | Enqueue a job (optional idempotency key)  |
| `GET`  | `/jobs`                 | List jobs (filterable by status)          |
| `GET`  | `/jobs/{id}`            | Get a single job                          |
| `POST` | `/jobs/claim`           | Worker claims the next pending job        |
| `POST` | `/jobs/{id}/complete`   | Worker marks a job completed              |
| `POST` | `/jobs/{id}/fail`       | Worker marks a job failed (with retry)    |

> **Route order**: `/jobs/claim` must be registered before `/jobs/{id}` so the literal
> segment `claim` is not captured as a path parameter.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key TEXT UNIQUE` enforces uniqueness at the DB level. `claimed_at`,
`worker_id`, and `error` are nullable — set only when a job enters `running` or `failed`.

---

## Priority: numeric enum for SQL ordering

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low' => self::Low, 'medium' => self::Medium,
            'high' => self::High, 'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }
}
```

Numeric values allow direct `ORDER BY priority DESC` sorting. A string enum would require
a `CASE` expression or a priority lookup table. Gaps between values (0, 10, 20, 30) allow
inserting future priority levels without renumbering.

---

## Claim: highest-priority FIFO

```php
public function claim(string $workerId, string $now): ?Job
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
        [],
    );
    if ($rows === []) {
        return null;
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
        [$now, $workerId, $now, $id],
    );

    return $this->findById($id);
}
```

`ORDER BY priority DESC, created_at ASC` picks the highest-priority job, and among
equal-priority jobs, the oldest one (FIFO). `LIMIT 1` ensures only one job is selected.

This claim is **non-atomic** (see V-06). For a single-worker setup this is acceptable.
For concurrent workers, use SQLite's `BEGIN IMMEDIATE` + `SELECT … LIMIT 1 FOR UPDATE`
(MySQL) or a `status = 'pending' AND id = ?` conditional UPDATE with `changes()` check.

---

## Retry logic: requeue vs fail

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        // Requeue: reset to pending with incremented retry_count
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        // Exhausted: permanent failure
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`retry_count < max_retries` checks whether the job has retries remaining. If yes,
the job returns to `pending` (cleared `claimed_at`/`worker_id`) and can be claimed again.
If exhausted, it transitions to the terminal `failed` state.

On requeue, `claimed_at = NULL` and `worker_id = NULL` are cleared so the job appears
as a fresh pending job to the next worker that claims it.

---

## Idempotency key: deduplication on create

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}

$job = $this->repo->create($type, ..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

If a job with the same `idempotency_key` already exists, the existing job is returned
with `200 OK` instead of creating a duplicate. A new job returns `201 Created`.
The `UNIQUE` constraint on `idempotency_key` provides a second-level guard against
race conditions.

---

## State machine

```
pending ──(claim)──→ running ──(complete)──→ completed (terminal)
                        │
                        └──(fail, retries remain)──→ pending
                        │
                        └──(fail, retries exhausted)──→ failed (terminal)
```

`complete()` and `fail()` both check `status = Running` before applying the transition.
A `null` return from either indicates the job was not found or not in the correct state,
mapped to `409 Conflict` by the controller.

---

## VULN — Vulnerability assessment (FT255)

### V-01 — No authentication: any caller can enqueue, claim, or complete any job

**Risk**: All endpoints are unauthenticated.

**Impact**: An attacker can enqueue arbitrary jobs with any type and payload, claim
legitimate jobs to prevent real workers from processing them, and mark jobs complete
or failed without executing the actual work.

**Verdict**: **EXPOSED** — add authentication. Worker endpoints (`/jobs/claim`,
`/jobs/{id}/complete`, `/jobs/{id}/fail`) should require a worker API key or JWT.
Enqueueing should be restricted to authenticated producers.

---

### V-02 — Job type is any string: no allowlist enforced

**Risk**: `type` accepts any non-empty string. An attacker can enqueue jobs of types
the system does not handle (e.g., `"DROP TABLE"`, `"shutdown"`, `"admin_task"`).

**Impact**: If the worker dispatches based on `type` (e.g., `match($job->type) { ... }`),
unknown types are silently skipped or trigger unexpected default handlers.

**Verdict**: **EXPOSED** — validate `type` against an allowlist of known job types.
Return `422` for unknown types. Example:

```php
if (!in_array($type, ['email', 'pdf', 'sync'], true)) {
    return $this->problems->create($request, 'validation-failed', '...', 422, ...);
}
```

---

### V-03 — Priority manipulation: attacker sets `critical` priority

**Attack**: Enqueue a job with `"priority": "critical"` to preempt all existing jobs.

```json
{"type": "spam", "payload": {}, "priority": "critical"}
```

**Observed**: The request succeeds with `201`. The spam job is now at the front of the
queue and is claimed before any legitimate high-priority jobs.

**Verdict**: **EXPOSED** — restrict who can set high-priority levels. Producers without
elevated trust should be limited to `low` or `medium`. Reject `critical` from
unauthenticated callers.

---

### V-04 — Worker ID spoofing: anyone can claim with any worker_id

**Attack**: Submit a claim with `"worker_id": "legitimate-worker-1"`.

**Observed**: The claim succeeds — the job is assigned to the spoofed worker ID.
The legitimate worker cannot distinguish this from its own claims.

**Verdict**: **EXPOSED** — `worker_id` should be derived from an authenticated identity
(API key → worker name), not supplied by the caller. Never trust caller-supplied worker IDs.

---

### V-05 — Job state takeover: any caller can complete/fail any running job

**Attack**: Complete or fail a job that a different worker claimed.

```bash
# Worker A claims job 1; attacker completes it before Worker A finishes:
POST /jobs/1/complete
```

**Observed**: `complete()` only checks `status = Running`. No ownership check verifies
that the caller is the worker that claimed the job.

**Verdict**: **EXPOSED** — add a `WHERE worker_id = $requestWorkerId` condition to
`complete()` and `fail()`. Return `409` if the worker does not own the job.

---

### V-06 — Race condition on claim: non-atomic SELECT + UPDATE

**Risk**: `claim()` performs `SELECT … LIMIT 1` then `UPDATE … WHERE id = ?`. Two
concurrent workers could select the same job before either updates it.

**Attack**: Two workers both see job 1 as `pending`, both update it to `running`,
both execute the job. The second update wins the `worker_id` column, but the job
runs twice.

**Verdict**: **EXPOSED** — use an atomic claim pattern:
```sql
UPDATE jobs SET status='running', worker_id=?, claimed_at=?
WHERE id = (SELECT id FROM jobs WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT 1)
  AND status = 'pending'
```
Then check `changes() = 1`. On SQLite, wrapping in `BEGIN IMMEDIATE` prevents
concurrent reads from seeing the same pending row.

---

### V-07 — Payload size: no limit on job payload

**Risk**: `payload` accepts any JSON object with no size validation.

**Impact**: A multi-megabyte payload consumes storage and memory when the job is
fetched by workers or listed in the queue.

**Verdict**: **EXPOSED** — add a payload size check (e.g., `strlen($json) > 65536 → 422`).
Rely on request-size middleware as the outer limit.

---

### V-08 — SQL injection via type or payload

**Attack**: Embed SQL metacharacters in `type` or `payload` fields.

```json
{"type": "'; DROP TABLE jobs; --", "payload": {}}
```

**Observed**: Values are bound as parameterized `?` placeholders. The injection is
stored as literal text in the database; the SQL is never executed.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection.

---

### V-09 — Idempotency key collision: attacker guesses a legitimate key

**Attack**: Guess or enumerate a legitimate caller's idempotency key and submit the
same job with a different payload.

**Observed**: The existing job is returned unchanged. The attacker's request does NOT
create a new job — the `UNIQUE` constraint and application-level check both prevent it.
The attacker learns the job exists (via the returned `200`) but cannot modify it.

**Verdict**: **PARTIALLY BLOCKED** — duplicate creation is blocked. However, the
attacker can enumerate job existence by probing idempotency keys. Use long random keys
(e.g., UUID v4) to make enumeration infeasible. The response to a matched key leaks
that the job exists and its status.

---

### V-10 — Error message disclosure in failed jobs

**Risk**: Worker error messages from `POST /jobs/{id}/fail` are stored in the `error`
column and returned in all list/get responses.

**Impact**: Internal error messages (stack traces, DB connection strings, internal file
paths) submitted by workers are visible to any caller of `GET /jobs`.

**Verdict**: **EXPOSED** — sanitize error messages before storing (strip sensitive
details). Limit `error` field visibility to admin roles in the list/get responses.

---

## VULN summary

| # | Vulnerability | Verdict |
|---|---------------|---------|
| V-01 | No authentication on any endpoint | EXPOSED |
| V-02 | Job type: no allowlist | EXPOSED |
| V-03 | Priority manipulation (critical jobs) | EXPOSED |
| V-04 | Worker ID spoofing | EXPOSED |
| V-05 | Job state takeover (no ownership check) | EXPOSED |
| V-06 | Race condition on claim (non-atomic) | EXPOSED |
| V-07 | Payload size: no limit | EXPOSED |
| V-08 | SQL injection via type/payload | BLOCKED |
| V-09 | Idempotency key collision / enumeration | PARTIALLY BLOCKED |
| V-10 | Error message disclosure in list | EXPOSED |

**Critical fixes before production**:
1. **V-01** — Add authentication for producers and workers (separate auth levels)
2. **V-02** — Validate `type` against a known allowlist
3. **V-03 / V-04 / V-05** — Derive worker identity from authenticated session; add `worker_id` ownership check
4. **V-06** — Use atomic claim (`UPDATE … WHERE … AND status='pending'` + `changes() = 1`)
5. **V-10** — Sanitize worker error messages before storage; restrict visibility

---

## Related howtos

- [`notification-queue.md`](notification-queue.md) — notification queue API (notiflog FT214)
- [`idempotency.md`](idempotency.md) — idempotency key pattern for POST requests
- [`dead-letter-queue.md`](dead-letter-queue.md) — dead letter queue with retry (deadletterlog FT72)
- [`transactions.md`](transactions.md) — wrapping queue operations in transactions
