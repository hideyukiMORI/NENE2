# Field Trial 50 — Notification System (notificationlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/notificationlog/`
**NENE2 version**: 1.5.19
**Theme**: Notification system with per-user listing, unread/read filtering via `QueryStringParser::bool()`, bulk mark-as-read, PATCH for state transition, `DatabaseQueryExecutorInterface::execute()` return value for affected rows

## Overview

Built a notification API with user-scoped notifications, read/unread filtering, per-item and bulk mark-as-read, and unread count. Key patterns: `QueryStringParser::bool()` for `?is_read=true/false` query params, `PATCH` for state transition (mark read), and using the `execute()` return value (affected row count) for bulk update responses.

## Endpoints Implemented

- `POST /notifications` — create notification (user_id, type, title, body)
- `GET /users/{userId}/notifications` — list all for user (optional `?is_read=true|false`)
- `GET /users/{userId}/notifications/unread-count` — count of unread for user
- `GET /notifications/{id}` — show single notification
- `PATCH /notifications/{id}/read` — mark single notification as read (idempotent)
- `PATCH /users/{userId}/notifications/read-all` — bulk mark all unread as read

## Test Results

18 tests, 32 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: 1 import ordering fix (auto-fixed).

---

## Frictions Found

None. Zero frictions in this field trial. All patterns from recent FTs applied correctly on first attempt.

---

## Patterns Validated

### `QueryStringParser::bool()` for is_read filter

```php
$isRead = QueryStringParser::bool($request, 'is_read');
// null = no filter, true = read only, false = unread only
```

`QueryStringParser::bool()` returns `null` when absent (no `?is_read=` param), `true` for "true"/"yes"/"1", `false` for "false"/"no"/"0". Perfect for optional boolean filter.

### SQLite boolean storage as INTEGER 0/1

```sql
WHERE is_read = ?   -- binds 0 or 1
```

PHP `$isRead ? 1 : 0` correctly maps to SQLite INTEGER. Reading back: `(bool) $row['is_read']`.

### `execute()` return value for affected row count

`DatabaseQueryExecutorInterface::execute()` returns the number of affected rows. Useful for bulk operations:

```php
$updated = $this->executor->execute(
    'UPDATE notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0',
    [$now, $userId],
);
return $this->json->create(['marked_read' => $updated]); // tells client how many were updated
```

### Idempotent mark-as-read

```php
public function markRead(int $id, string $now): Notification {
    $notification = $this->findById($id);
    if ($notification->isRead) {
        return $notification; // already read — no-op
    }
    // UPDATE ...
}
```

Calling `PATCH /notifications/{id}/read` multiple times returns 200 each time, only updating once.

### Route ordering: static sub-path before parameterized

`/users/{userId}/notifications/unread-count` is registered before `/users/{userId}/notifications` to avoid `unread-count` being matched as a `userId` value. Router matches in registration order.

### Router::PARAMETERS_ATTRIBUTE — confirmed pattern from FT48 howto

```php
$params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$userId = (int) ($params['userId'] ?? 0);
```

Applied without friction — the FT48 howto addition was directly useful here.

---

## NENE2 Changes Required

None. This field trial validated existing patterns without discovering new frictions.
