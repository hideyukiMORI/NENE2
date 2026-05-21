# Background Job Queue with Retry and Idempotency

This guide covers implementing a persistent background job queue in NENE2 applications. The pattern supports priority queues, automatic retry with backoff counters, and idempotent job creation.

## Core concepts

A job queue decouples work from HTTP request cycles. The HTTP handler enqueues a job and returns immediately; a separate worker process claims and executes jobs.

Key states: `pending` → `running` → `completed` or `failed` (with automatic requeue when retries remain).

## Schema design

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

`idempotency_key UNIQUE` is enforced at the database level, not just application level. This prevents races where two concurrent HTTP requests both pass the application-layer check and both attempt INSERT.

## Job lifecycle

```
POST /jobs                  → pending (retry_count=0)
POST /jobs/claim            → running (worker_id, claimed_at set)
POST /jobs/{id}/complete    → completed
POST /jobs/{id}/fail        → pending (retry_count+1) if retries remain
                            → failed if retry_count >= max_retries
```

## Retry logic

When a worker calls `fail`, the repository decides whether to requeue or permanently fail:

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

The `error` field stores the **most recent** failure reason even on requeue, giving operators a diagnostic trail on the job record.

## Idempotency

Pass an `idempotency_key` when creating a job to make the operation safe to retry from the HTTP client:

```http
POST /jobs
Content-Type: application/json

{
  "type": "send-invoice",
  "payload": {"invoice_id": 42},
  "idempotency_key": "invoice-42-send-2026-05"
}
```

- First call: `201 Created` — job is created.
- Subsequent calls with same key: `200 OK` — existing job returned, no duplicate created.

The database `UNIQUE` constraint on `idempotency_key` is the safety net. Check at the application layer first to avoid relying on exception handling as the primary code path:

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}
$job = $this->repo->create(..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

## Priority queue

Jobs are claimed by priority DESC, then created_at ASC (FIFO within a tier):

```sql
SELECT * FROM jobs
WHERE status = 'pending'
ORDER BY priority DESC, created_at ASC
LIMIT 1
```

Priority levels (integer values stored, human labels exposed):

| Label    | Value |
|----------|-------|
| low      | 0     |
| medium   | 10    |
| high     | 20    |
| critical | 30    |

## Worker pattern

Workers are stateless processes that loop: claim → execute → complete or fail.

```
loop:
  job = POST /jobs/claim { worker_id: "worker-1" }
  if job is null → sleep, continue

  try:
    execute(job.type, job.payload)
    POST /jobs/{job.id}/complete {}
  catch error:
    POST /jobs/{job.id}/fail { error: error.message }
```

Workers identify themselves with `worker_id` so operators can see which worker holds a job and diagnose stalled workers.

## Stalled job detection

Jobs in `running` status with a `claimed_at` timestamp older than a threshold are stalled (worker crashed). A maintenance process should detect and requeue them:

```sql
UPDATE jobs
SET status = 'pending', retry_count = retry_count + 1,
    claimed_at = NULL, worker_id = NULL, updated_at = ?
WHERE status = 'running'
  AND claimed_at < ?             -- older than timeout threshold
  AND retry_count < max_retries
```

## max_retries=0 for non-retryable jobs

Some jobs must not be retried (e.g., payments, external webhooks where replay would cause harm). Set `max_retries: 0` when creating:

```json
{ "type": "charge-card", "max_retries": 0, "idempotency_key": "charge-order-99" }
```

The first `fail` call immediately transitions the job to `failed`.

## Design decisions

**Why retry logic in the repository, not the worker?** The decision to requeue is a data-layer invariant (retry_count < max_retries), not business logic. Placing it in the repository keeps workers simple and prevents inconsistency from workers that implement the check differently.

**Why UNIQUE constraint on idempotency_key at DB level?** Application-level checks have race conditions under concurrent requests. The DB constraint is the authoritative guard; the application-layer check is an optimization to avoid relying on exception handling.

**Why store priority as an integer?** Allows adding intermediate priority levels later without schema changes. The human-readable label is derived, not stored.
