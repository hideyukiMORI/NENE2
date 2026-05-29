---
title: "How-to: Dead Letter Queue (DLQ)"
category: infrastructure
tags: [queue, dead-letter-queue, retry, backoff, reliability]
difficulty: advanced
related: [job-queue-with-retry, job-queue, notification-queue]
---

# How-to: Dead Letter Queue (DLQ)

> **FT reference**: FT72 (`NENE2-FT/deadletterlog`) — Dead Letter Queue API

Demonstrates a reliable message queue with exponential backoff retries and a dead letter
queue. Failed messages are automatically rescheduled with increasing delays; after
exhausting all retries they move to a `dead` state where they can be inspected and
replayed. Supports multiple named queues via path parameter.

---

## Message lifecycle

```
enqueue ──▶ pending ──claim──▶ processing
                                    │
                        ┌──succeed──┤──fail (retries left)──▶ pending (retry_after)
                        │           │
                        ▼           └──fail (exhausted)──▶ dead ──replay──▶ pending
                    succeeded
```

| Status | Description |
|--------|-------------|
| `pending` | Ready to be claimed (or waiting until `retry_after`) |
| `processing` | Claimed by a worker, being processed |
| `succeeded` | Completed successfully |
| `dead` | Exhausted all retries — in the dead letter queue |

---

## Routes

| Method | Path                                          | Description                          |
|--------|-----------------------------------------------|--------------------------------------|
| `POST` | `/queues/{queue}/messages`                    | Enqueue a message                    |
| `GET`  | `/queues/{queue}/messages`                    | List messages in a queue             |
| `GET`  | `/queues/{queue}/messages/{id}`               | Get a single message                 |
| `POST` | `/queues/{queue}/claim`                       | Claim the next pending message       |
| `POST` | `/queues/{queue}/messages/{id}/succeed`       | Mark as succeeded                    |
| `POST` | `/queues/{queue}/messages/{id}/fail`          | Mark as failed (retry or DLQ)        |
| `POST` | `/queues/{queue}/messages/{id}/replay`        | Replay a dead message                |

---

## Enqueuing a message

```php
// POST /queues/emails/messages
$body = [
    'payload'     => '{"to":"alice@example.com","subject":"Welcome"}',  // required string
    'max_retries' => 5,  // optional, default 3, range 1–10
];
```

`max_retries` is validated to be between 1 and 10:

```php
$maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

if ($maxRetries < 1 || $maxRetries > 10) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'max_retries', 'code' => 'invalid', 'message' => 'max_retries must be between 1 and 10.']],
    ]);
}
```

---

## Claiming the next pending message

A worker calls `POST /queues/{queue}/claim` to dequeue one message atomically:

```php
public function claim(string $queue, string $now): ?Message
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM messages
         WHERE queue = ? AND status = 'pending'
           AND (retry_after IS NULL OR retry_after <= ?)
         ORDER BY created_at ASC LIMIT 1",
        [$queue, $now],
    );

    if ($rows === []) {
        return null;  // no message available
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_after <= now` filters out messages that are waiting between retries. Messages
are claimed in FIFO order (`ORDER BY created_at ASC`).

> **Atomicity note**: Without a transaction, two concurrent workers can claim the same
> message if they both read the same row before either UPDATE runs. Wrap the SELECT +
> UPDATE in a transaction with `SELECT ... FOR UPDATE` (MySQL/PostgreSQL) or use
> `UPDATE ... WHERE status = 'pending' RETURNING id` for true atomic claim.

---

## Failure handling with exponential backoff

When a worker reports failure (`POST .../fail`), the repository either schedules a retry
or promotes the message to the dead letter queue:

```php
public function fail(int $id, string $error, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Processing) {
        return null;
    }

    $newRetryCount = $msg->retryCount + 1;

    if ($newRetryCount >= $msg->maxRetries) {
        // Exhausted — move to DLQ
        $this->executor->execute(
            "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $now, $id],
        );
    } else {
        // Schedule retry with exponential backoff
        $backoffSeconds = min(2 ** $newRetryCount, 3600);
        $retryAfter     = (new \DateTimeImmutable($now))
            ->modify("+{$backoffSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $this->executor->execute(
            "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
             retry_after = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $retryAfter, $now, $id],
        );
    }

    return $this->findById($id);
}
```

### Backoff schedule (max_retries = 5)

| Attempt | Backoff seconds | Formula |
|---------|-----------------|---------|
| 1st failure | 2 s | 2^1 |
| 2nd failure | 4 s | 2^2 |
| 3rd failure | 8 s | 2^3 |
| 4th failure | 16 s | 2^4 |
| 5th failure | → dead | retries exhausted |

`min(2 ** $newRetryCount, 3600)` caps the maximum backoff at 1 hour. For large retry
counts this prevents multi-day delays while still giving the service time to recover.

---

## Replaying dead messages

A dead message can be replayed by resetting it to `pending` with cleared retry state:

```php
public function replay(int $id, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Dead) {
        return null;  // 409 Conflict
    }

    $this->executor->execute(
        "UPDATE messages SET status = 'pending', retry_count = 0,
         last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_count` resets to 0 so the message gets the full `max_retries` budget again.
The original `max_retries` value is preserved.

> **Best practice**: before replaying, fix the underlying cause of failure. Replaying
> into a broken system will just re-populate the DLQ.

---

## Multiple named queues

The `{queue}` path parameter routes messages by name. Any non-empty string is valid:

```
POST /queues/emails/messages
POST /queues/notifications/messages
POST /queues/webhooks/messages
```

All queries filter by `queue = ?`, so each queue is isolated. No queue registration
step is needed — queues are created implicitly on first enqueue.

---

## Schema

```sql
CREATE TABLE messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- NULL when not scheduled for retry
    last_error  TEXT,           -- NULL until first failure
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

Key design choices:
- `payload` is an opaque string — the queue does not inspect or validate message content.
- `last_error` stores the most recent failure message for debugging.
- `retry_after` is `NULL` for new messages and cleared on replay, allowing `retry_after <= now` to work without special-casing.

---

## Worker pattern

A worker polls and processes one message at a time:

```php
// Worker loop (pseudo-code)
while (true) {
    $msg = claim('/queues/emails/messages');
    if ($msg === null) {
        sleep(5);  // no messages, back off
        continue;
    }

    try {
        sendEmail(json_decode($msg->payload));
        succeed($msg->id);
    } catch (Exception $e) {
        fail($msg->id, $e->getMessage());
    }
}
```

Keep claim-to-succeed/fail cycles short. Long-running processing without timeouts
leaves messages in `processing` state forever if the worker crashes. Add a
`processing_timeout` column and a reaper job to reclaim timed-out messages.

---

## Related howtos

- [`job-queue.md`](job-queue.md) — basic job queue without DLQ
- [`notification-queue.md`](notification-queue.md) — notification queue patterns
- [`idempotency.md`](idempotency.md) — idempotent processing for at-least-once delivery
- [`webhook-delivery.md`](webhook-delivery.md) — webhook retry patterns
