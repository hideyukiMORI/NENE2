# Field Trial Report — FT116: Background Job Queue with Retry and Idempotency

**Date**: 2026-05-21
**Release**: v1.5.50
**App**: `queuelog` (`/home/xi/docker/NENE2-FT/queuelog/`)
**Tests**: 27/27 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement a persistent background job queue using NENE2's database layer. The trial focused on three patterns:

1. **Priority queue** — claim highest-priority pending job (priority DESC, FIFO within tier)
2. **Automatic retry** — fail with retry_count < max_retries requeues to pending; exhausted retries transition to failed
3. **Idempotent job creation** — `idempotency_key` prevents duplicate jobs under retry/concurrent POST

## Schema additions to existing FT65 foundation

The `queuelog` project reused the FT65 job queue foundation (claim/complete/fail cycle, priority enum) and added:

```sql
retry_count     INTEGER NOT NULL DEFAULT 0,
max_retries     INTEGER NOT NULL DEFAULT 3,
idempotency_key TEXT    UNIQUE,
```

The `UNIQUE` constraint on `idempotency_key` is enforced at the database level, not only in application code, to prevent race conditions under concurrent requests.

## Key implementation decisions

### Retry logic belongs in the repository

The check `retry_count < max_retries` is a data-layer invariant. Placing it in `SqliteJobRepository::fail()` keeps workers stateless and prevents drift from multiple implementations:

```php
if ($job->retryCount < $job->maxRetries) {
    // requeue: increment retry_count, reset to pending, clear worker assignment
} else {
    // permanent failure
}
```

The `error` field is updated on every failure, including requeues, providing a diagnostic trail.

### Idempotency: check first, catch never

The application layer checks for an existing job by key before attempting INSERT. The DB UNIQUE constraint catches concurrent races but should not be the primary code path — exceptions from constraint violations are harder to distinguish from other DB errors and produce inconsistent HTTP responses if not handled explicitly.

### max_retries=0 for non-retryable jobs

Jobs where replay would cause harm (payments, external webhooks) can be created with `max_retries: 0`. The first `fail` call immediately sets status to `failed`.

## Test coverage (27 tests)

| Category | Tests |
|---|---|
| Job creation (defaults, payload, validation) | 5 |
| Get / List / Filter by status | 4 |
| Claim (priority, claimed_at, no-pending, missing worker_id) | 4 |
| Complete (happy path, 409 on non-running, cannot re-claim) | 3 |
| Fail (409 on non-running) | 1 |
| Retry: requeue on fail with retries remaining | 1 |
| Retry: requeued job can be claimed again | 1 |
| Retry: exhausts retries → failed | 1 |
| Retry: max_retries=0 → immediate fail | 1 |
| Idempotency: duplicate key returns existing | 1 |
| Idempotency: key stored and returned | 1 |
| Idempotency: different keys create separate jobs | 1 |
| Priority: FIFO within same priority | 1 |
| Priority: critical beats high | 1 |
| Custom max_retries | 1 |

## DX observations

- The claim/complete/fail API maps directly to worker loop logic — easy to reason about.
- Returning the job object from every state-transition endpoint (including fail/requeue) lets callers inspect the new state without a separate GET.
- `max_retries` configurable per job type is more flexible than a global default.
- Stalled job detection (running + claimed_at timeout) is a natural next concern not covered in this trial.
