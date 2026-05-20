# Field Trial 78 — Notification System with Read/Unread State and Bulk Operations (notificationlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.23
**Project**: `/home/xi/docker/NENE2-FT/notificationlog/`
**Theme**: Per-user notification stream with read/unread state tracking, PATCH for individual mark-as-read, bulk mark-all-read, unread badge count, and DELETE (dismiss).

---

## What was built

A notification API where notifications are created per user, can be individually or bulk marked as read, filtered by read state, and dismissed (deleted). An unread count endpoint provides a badge count.

### Domain

- `Notification` — `userId`, `type`, `title`, `body`, `read` (bool), `createdAt`, `readAt` (nullable)

### Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL, type TEXT NOT NULL,
    title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '',
    read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, read_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/users/{userId}/notifications` | Create notification for user |
| GET | `/users/{userId}/notifications` | List (optional `?read=true\|false`) |
| GET | `/users/{userId}/notifications/unread-count` | Unread badge count |
| PATCH | `/users/{userId}/notifications/{id}` | Mark notification as read |
| POST | `/users/{userId}/notifications/mark-all-read` | Bulk mark all unread as read |
| DELETE | `/users/{userId}/notifications/{id}` | Dismiss (delete) notification |

### Key design decisions

**PATCH validates `read: true` explicitly**: Following the FT21 howto pattern, `array_key_exists('read', $body)` is checked before inspecting the value. Only `read: true` is accepted (not `false`) — attempting to un-read returns 422.

**Unread count via `COUNT(*)`**: Single SQL query `COUNT(*) WHERE user_id = ? AND read = 0` — no hydration of full notification objects.

**Bulk mark-all-read returns `updated` count**: `UPDATE ... WHERE read = 0` returns row count via `execute()` → useful for callers to know if any notifications were affected.

**`?read=true|false` string filter**: PHP `'true'`/`'false'` string comparison (not boolean cast) — natural for query string values.

**Route ordering**: `GET /users/{userId}/notifications/unread-count` (1 dynamic param) and `POST /users/{userId}/notifications/mark-all-read` (1 dynamic param) sort before `PATCH /users/{userId}/notifications/{id}` and `DELETE /users/{userId}/notifications/{id}` (2 dynamic params). NENE2 v1.5.22+ auto-sort ensures static-heavy routes win.

### Test results

```
OK (19 tests, 34 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No NENE2 frictions encountered. v1.5.23 handled all patterns cleanly:

- PATCH with `array_key_exists` partial update: ✅
- Unread count via COUNT(*): ✅
- `?read=true|false` string filter: ✅
- Route ordering for `/unread-count` and `/mark-all-read` vs `/{id}`: ✅
- `DatabaseQueryExecutorInterface::insert()` returning last insert ID: ✅

---

## Summary

Notification system with read/unread state, PATCH, bulk operations, and unread count works cleanly with NENE2 v1.5.23. PATCH handling with `array_key_exists` follows the documented howto from FT21. No framework changes needed.
