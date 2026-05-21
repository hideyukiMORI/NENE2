# Notification Inbox

Deliver notifications to users and let them track read/unread status. State is stored as a `read_at` timestamp — `NULL` means unread, a timestamp means read.

## Overview

A notification inbox consists of:
- **Create**: push a notification to a user
- **List**: retrieve all notifications (optionally filter to unread only)
- **Mark as read**: set `read_at` on a single notification (idempotent)
- **Bulk mark as read**: set `read_at` on all unread notifications for a user
- **Unread count**: cheap badge counter

Notifications are never deleted — they are permanent records of what was communicated to the user.

## Database Schema

```sql
CREATE TABLE notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    read_at    TEXT    DEFAULT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`read_at IS NULL` means unread. `read_at IS NOT NULL` means read. No boolean column is needed — the timestamp is the state.

## Read State Pattern

Use a single nullable `read_at` column instead of a boolean `is_read`. This gives you:

- **When it was read** (useful for analytics)
- **Idempotent update**: `UPDATE ... WHERE read_at IS NULL` is safe to call multiple times
- **Cheap unread count**: `SELECT COUNT(*) WHERE read_at IS NULL`

```php
public function isRead(): bool
{
    return $this->readAt !== null;
}
```

## Appending a Notification

Notifications are written by the server (admin, system event) and pushed to a user:

```php
public function create(int $userId, string $title, string $body, string $now): Notification
{
    $this->executor->execute(
        'INSERT INTO notifications (user_id, title, body, read_at, created_at) VALUES (?, ?, ?, NULL, ?)',
        [$userId, $title, $body, $now],
    );

    $id = (int) $this->executor->lastInsertId();

    return new Notification($id, $userId, $title, $body, null, $now);
}
```

`read_at` is always `NULL` on insert — a new notification is always unread.

## Listing Notifications

Return notifications newest-first. Include the unread count in the response so clients can update badge UI without a separate request:

```php
public function findByUserId(int $userId, ?bool $unreadOnly = null): array
{
    if ($unreadOnly === true) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY id DESC',
            [$userId],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );
    }
    ...
}
```

`ORDER BY id DESC` (not `created_at DESC`) — insertion order is stable; two notifications with the same timestamp keep a deterministic order.

## Mark as Read (Single, Idempotent)

```php
public function markAsRead(int $id, string $now): ?Notification
{
    $existing = $this->findById($id);

    if ($existing === null) {
        return null;
    }

    if ($existing->isRead()) {
        return $existing;  // already read — no UPDATE, preserve original read_at
    }

    $this->executor->execute(
        'UPDATE notifications SET read_at = ? WHERE id = ?',
        [$now, $id],
    );

    return $this->findById($id);
}
```

Check `isRead()` before updating so `read_at` is never overwritten. A second PATCH call returns the same `read_at` value as the first.

## Bulk Mark as Read

```php
public function markAllAsRead(int $userId, string $now): int
{
    $this->executor->execute(
        'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
        [$now, $userId],
    );

    return $this->countUnread($userId);
}
```

`WHERE read_at IS NULL` makes the bulk update idempotent — already-read notifications keep their original `read_at`. Returns the remaining unread count (should be 0).

## Cross-User Ownership Check

When marking a single notification as read, verify the notification belongs to the requesting user:

```php
$notification = $this->repo->findById($id);

if ($notification === null || $notification->userId !== $userId) {
    return $this->responseFactory->create(['error' => 'notification not found'], 404);
}
```

Return 404 (not 403) — this prevents enumeration of notification IDs belonging to other users.

## Unread Count

A lightweight endpoint for badge counters — no need to load all notifications:

```php
public function countUnread(int $userId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND read_at IS NULL',
        [$userId],
    );
    ...
}
```

Include this count in list responses as well so clients can keep badges in sync without extra round-trips.

## Security Properties

| Property | Implementation |
|---|---|
| User isolation | All queries filter by `user_id` |
| Cross-user read attempt | Returns 404 — prevents notification ID enumeration |
| Idempotent mark-as-read | Checks `isRead()` before UPDATE; `read_at` never overwritten |
| Bulk update safety | `WHERE read_at IS NULL` — only unread rows are touched |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/users` | Create a user |
| `POST` | `/users/{userId}/notifications` | Push a notification to a user |
| `GET` | `/users/{userId}/notifications` | List all notifications (`?unread=true` for unread only) |
| `GET` | `/users/{userId}/notifications/unread-count` | Get unread badge count |
| `PATCH` | `/users/{userId}/notifications/{id}/read` | Mark a single notification as read |
| `POST` | `/users/{userId}/notifications/read-all` | Mark all notifications as read |
