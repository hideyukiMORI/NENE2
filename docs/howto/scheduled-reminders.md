# How-to: Scheduled Reminders API

> **FT reference**: FT235 (`NENE2-FT/reminderlog`) — Scheduled Reminders API

Demonstrates a reminder scheduling API with timezone-aware future datetime validation,
lightweight per-request user identification via a header, IDOR prevention through
ownership-scoped queries, and a 404/409 distinction when cancelling a reminder.

---

## Routes

| Method  | Path                        | Description                                           |
|---------|-----------------------------|-------------------------------------------------------|
| `POST`  | `/reminders`                | Create a reminder (future `remind_at` required)       |
| `GET`   | `/reminders`                | List reminders for the caller (filterable by status)  |
| `PATCH` | `/reminders/{id}/cancel`    | Cancel a pending reminder                             |

All routes require the `X-User-Id` header.

---

## Lightweight user identification via header

Rather than Bearer JWT, this API uses an `X-User-Id` integer header as a minimal
authentication/identification mechanism:

```php
$userId = V::userId($request->getHeaderLine('X-User-Id'));

if ($userId === null) {
    return $this->responseFactory->create(
        ['error' => 'X-User-Id header must be a positive integer.'],
        401,
    );
}
```

`V::userId()` validates the header value:

```php
public static function userId(string $header): ?int
{
    // ctype_digit('') === false — empty string already rejected.
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

Key properties:
- `ctype_digit()` — ReDoS-immune, rejects `0`, `-1`, `1.5`, `abc`, empty string.
- `strlen > 18` — overflow guard before `(int)` cast (PHP_INT_MAX is 19 digits).
- `$id > 0` — rejects the parsed integer zero.

For production, replace with JWT or session validation. The `X-User-Id` pattern is
suitable for internal services where the upstream gateway has already authenticated
the user and forwards their ID.

---

## Future datetime validation (timezone-aware)

`remind_at` must be a valid ISO 8601 datetime with an explicit timezone offset **and**
must be strictly in the future relative to now:

```php
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);

if ($remindAt === null) {
    return $this->responseFactory->create(
        ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone offset and must be in the future.'],
        422,
    );
}
```

`V::futureDatetime()` composes two checks:

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);   // Step 1: format + range validation

    if ($dt === null) {
        return null;
    }

    // Step 2: timezone-aware future check
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // Object comparison normalises to UTC
}
```

`V::isoDatetime()` performs the format check first:

```php
public static function isoDatetime(mixed $raw): ?string
{
    // Strict regex: requires ±HH:MM offset — rejects 'Z', date-only, missing offset.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Validate timezone offset range: valid UTC offsets are −14:00 … +14:00.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];

    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }
    // ... round-trip validation for overflow dates (Feb 30 etc.)
}
```

The `DateTimeImmutable` object comparison (`>`) converts both sides to UTC before
comparing — so `2026-06-01T09:00:00+09:00` (00:00 UTC) is correctly compared to
`2026-06-01T01:00:00+01:00` (00:00 UTC) as equal.

---

## IDOR prevention: ownership-scoped find

All operations that touch a specific reminder use `WHERE id = ? AND user_id = ?`:

```php
public function findForUser(int $id, int $userId): ?Reminder
{
    $stmt = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $this->hydrate($row) : null;
}
```

If the reminder belongs to another user, `findForUser()` returns `null` — the caller
receives `404 Not Found`, indistinguishable from "reminder does not exist". Returning
`403 Forbidden` would confirm that the ID exists, leaking enumeration information.

---

## 404 vs 409: fetch-first cancel

The cancel handler fetches the reminder before checking status. This two-step approach
allows the correct HTTP status to be returned for each failure mode:

```php
// Fetch first to distinguish 404 (not found/wrong owner) from 409 (wrong status)
$reminder = $this->repository->findForUser($id, $userId);

if ($reminder === null) {
    return $this->responseFactory->create(['error' => 'Reminder not found.'], 404);
}

if ($reminder->status !== ReminderStatus::Pending) {
    return $this->responseFactory->create(
        ['error' => sprintf('Cannot cancel a reminder with status "%s".', $reminder->status->value)],
        409,
    );
}

$this->repository->cancel($id, $userId);
```

The DB-level cancel includes the status guard as a safety backstop:

```php
public function cancel(int $id, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}
```

`WHERE status = 'pending'` in the UPDATE ensures that a race condition (two concurrent
cancel requests) results in only one row being updated.

---

## Query parameter validation (`?limit=` and `?status=`)

`limit` uses `V::queryInt()` which distinguishes absent key (use default) from invalid
value (return 422):

```php
$limit = V::queryInt(
    $params,
    'limit',
    ReminderRepository::MIN_LIMIT,   // 1
    ReminderRepository::MAX_LIMIT,   // 100
    ReminderRepository::DEFAULT_LIMIT, // 20 — returned when key absent
);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between %d and %d.', MIN_LIMIT, MAX_LIMIT)],
        422,
    );
}
```

`?status=` uses `V::enum()` to validate against the backed enum:

```php
$status = V::enum($rawStatus, ReminderStatus::class);

if ($status === null) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: pending, triggered, cancelled.'],
        422,
    );
}
```

`V::enum()` calls `BackedEnum::tryFrom()` internally, returning `null` for unknown
values.

---

## Schema

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- ISO 8601 with timezone offset, stored as-is
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

`remind_at` is stored as the original ISO 8601 string with the submitter's timezone
offset (e.g. `2026-06-01T09:00:00+09:00`). The DB does not normalise to UTC — the
application is responsible for correct comparison (see `V::futureDatetime()`).

Two indexes:
- `(user_id, id)` — covers per-user list and cancel lookups
- `(status, id)` — covers a poller query that fetches `pending` reminders due to fire

---

## Status enum

```php
enum ReminderStatus: string
{
    case Pending   = 'pending';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';
}
```

Only `pending` reminders can be cancelled (`409` otherwise). `triggered` is set by
a background job when the reminder fires — this API does not include the trigger
endpoint, which would run on a scheduled task outside the HTTP server.

---

## Related howtos

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601 datetime validation patterns
- [`content-scheduling.md`](content-scheduling.md) — scheduled publish with future `publish_at`
- [`approval-workflow.md`](approval-workflow.md) — 404/409 distinction in status transitions
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR prevention patterns
