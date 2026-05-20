# Field Trial 72 — Dead Letter Queue with Retry and Replay (deadletterlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/deadletterlog/`
**Theme**: Message queue with exponential backoff retry, dead-letter promotion on max-retries exhaustion, and replay from dead state.

---

## What was built

A queue-based message processing API where failed messages are retried with exponential backoff. After exhausting all retries, messages are promoted to "dead" status. Dead messages can be replayed (reset to pending with cleared retry state).

### Domain

- `MessageStatus` — string-backed enum: `pending`, `processing`, `succeeded`, `failed` (→ retry), `dead`
- `Message` — holds `queue`, `payload`, `status`, `retry_count`, `max_retries`, `retry_after`, `last_error`

### Schema

```sql
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default',
    payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/queues/{queue}/messages` | Enqueue message (payload, max_retries) |
| GET | `/queues/{queue}/messages?status=` | List messages in queue |
| GET | `/queues/{queue}/messages/{id}` | Get single message |
| POST | `/queues/{queue}/claim` | Claim next eligible pending message |
| POST | `/queues/{queue}/messages/{id}/succeed` | Mark as succeeded |
| POST | `/queues/{queue}/messages/{id}/fail` | Mark as failed (schedules retry or promotes to dead) |
| POST | `/queues/{queue}/messages/{id}/replay` | Replay dead message (reset to pending) |

### Key design decisions

**Exponential backoff**: `fail()` computes `retry_after = now + 2^retry_count seconds` (capped at 3600s). The `claim()` query filters `retry_after <= now` so messages aren't claimed before their backoff window expires.

**Dead-letter promotion**: When `retry_count + 1 >= max_retries`, status transitions to `dead` instead of back to `pending`. This is a terminal state until explicitly replayed.

**Replay resets retry counter**: `replay()` sets `retry_count = 0`, `last_error = null`, `retry_after = null`. The replayed message gets a fresh slate — max_retries attempts available again.

**Claim respects retry_after**: `WHERE status = 'pending' AND (retry_after IS NULL OR retry_after <= ?)` ensures initial messages (no retry_after) and eligible retries are claimed, but backoff-cooling messages are skipped.

**Queue isolation via path param**: `/queues/{queue}/` scopes all operations to a named queue. Multiple queues share the same table but are fully isolated by the `queue` column.

**Action endpoints as sub-paths**: `/messages/{id}/succeed`, `/messages/{id}/fail`, `/messages/{id}/replay` follow the resource + action path pattern used in FT65 (job queue). Works cleanly with NENE2's router.

### Test results

```
OK (18 tests, 35 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.22 handled all patterns cleanly:

- Queue-scoped path prefix (`/queues/{queue}/messages`): ✅
- Action sub-paths (`/messages/{id}/succeed`): ✅
- `?status=` query param with `MessageStatus::tryFrom()` validation: ✅
- Nullable `retry_after` / `last_error` column hydration: ✅ (checked via `isset()` before casting)

---

## Summary

Dead letter queue with exponential backoff retry and replay works cleanly with NENE2 v1.5.22. No framework changes needed. The backoff logic and dead-letter promotion live entirely in the repository layer, keeping the route handlers thin.
