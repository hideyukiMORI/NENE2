---
title: "How-to: Notification Queue API"
category: infrastructure
tags: [notifications, queue, admin, messaging]
difficulty: intermediate
related: [notification-inbox, job-queue]
---

# How-to: Notification Queue API

This guide demonstrates a notification queue where admins send targeted notifications to users, who can list, read, and delete them.

## Pattern Overview

- Admins send notifications to specific users via `POST /notifications` (admin-only).
- Users receive and manage their own notifications via `GET`, `POST /read`, `DELETE`.
- `unread_count` is returned with every list response.
- `?unread=1` filters to unread-only notifications.
- Marking as read is idempotent (already-read notifications return 200, not error).

## Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL DEFAULT 'info',
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);
```

## Type Allowlist

```php
private const array ALLOWED_TYPES = ['info', 'warning', 'error', 'success'];
```

Unknown types return 422. Never use a free-text field for type without validation.

## Idempotent Mark-Read

```php
public function markRead(int $id, int $userId): bool
{
    $notif = $this->findById($id);
    if ($notif === null || (int) $notif['user_id'] !== $userId) {
        return false;
    }
    if ((int) $notif['is_read'] === 1) {
        return true;  // Already read — idempotent, return success
    }
    $this->pdo->prepare(
        'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id'
    )->execute([':now' => $this->now(), ':id' => $id]);
    return true;
}
```

## Unread Filter

```php
if ($unreadOnly === true) {
    $stmt = $this->pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = :uid AND is_read = 0 ORDER BY id DESC'
    );
}
```

The query parameter `?unread=1` activates this path; any other value lists all.

## IDOR: User Scoping

All read/delete/list operations check `user_id`:

```php
if (!$isAdmin && (int) $notif['user_id'] !== $userId) {
    return false;  // → 404
}
```

Non-admin users cannot read, mark, or delete other users' notifications.

## Admin-Only Send

```php
private function send(ServerRequestInterface $req): ResponseInterface
{
    if (!$this->isAdmin($req)) {
        return $this->problem(403, 'forbidden', 'Admin access required.');
    }
    ...
}
```

The target `user_id` is specified in the request body, validated as `is_int() && >= 1`.

## Validation Summary

| Field | Rule |
|---|---|
| `user_id` body | Integer >= 1 (not string/float) |
| `type` body | One of: info, warning, error, success |
| `title` body | Non-empty, max 200 chars |
| `X-User-Id` header | Required for read/delete; `ctype_digit`, >0 |
| `X-Admin-Key` header | Required for send; fail-closed when empty |

## Routes

```
POST   /notifications                  Send notification (admin only)
GET    /users/{userId}/notifications   List notifications (owner or admin)
POST   /notifications/{id}/read        Mark as read (owner only)
DELETE /notifications/{id}             Delete notification (owner or admin)
```

## See Also

- FT214 source: `../NENE2-FT/notiflog/`
- Related: `docs/howto/session-token-management.md` (FT208, admin-key pattern)
