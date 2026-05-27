# How-to: Notification Inbox API

> **FT reference**: FT271 (`NENE2-FT/notificationlog`) — Notification inbox: type-allowlisted notification creation, per-user IDOR protection (404 not 403), admin fail-closed pattern, bulk mark-as-read, is_read idempotency, pagination clamping with PDO::PARAM_INT binding, 31 tests / 98 assertions PASS.
>
> Also validated in FT222 (`NENE2-FT/notificationlog`) — VULN assessment on the same pattern.

This guide shows how to build a notification inbox system with type-allowlisted push notifications,
per-user IDOR protection, and bulk mark-as-read using NENE2.

## Features

- Admin-only notification creation with type allowlist
- Per-user IDOR protection: users see only their own notifications (404 on unauthorized access)
- Single and bulk mark-as-read with ownership verification
- Unread count returned on every listing
- Optional unread-only filter and pagination
- Admin fail-closed

## Schema

```sql
CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    read_at    TEXT
);

CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, id DESC);
```

No separate `users` table — the API trusts `X-User-Id` header (replace with real auth in production).

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/notifications` | Admin | Create notification for a user |
| `GET` | `/users/{userId}/notifications` | Self / Admin | List notifications |
| `POST` | `/notifications/{id}/read` | Self / Admin | Mark one notification as read |
| `POST` | `/users/{userId}/notifications/read-all` | Self / Admin | Mark all as read |

## Type Allowlist

Free-form type strings are rejected to prevent injection and enumeration attacks:

```php
public const array ALLOWED_TYPES = [
    'system',
    'promotion',
    'social',
    'account',
    'security',
    'reminder',
];
```

Route handler validates before any DB access:

```php
if (!in_array($type, NotificationRepository::ALLOWED_TYPES, true)) {
    $allowed = implode(', ', NotificationRepository::ALLOWED_TYPES);
    return $this->problem(422, 'validation-failed', "type must be one of: {$allowed}.");
}
```

## IDOR Protection

Users can only read their own notifications. A 404 (not 403) is returned on unauthorized access
to prevent user ID enumeration:

```php
private function isSelfOrAdmin(ServerRequestInterface $req, int $ownerId): bool
{
    if ($this->isAdmin($req)) {
        return true;
    }
    $uid = $this->requestUserId($req);
    return $uid !== null && $uid === $ownerId;
}
```

Mark-as-read also verifies ownership before acting:

```php
// POST /notifications/{id}/read handler
$notification = $this->repo->findById($id);
if ($notification === null) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
// IDOR: only the owner or admin may mark as read
if (!$this->isSelfOrAdmin($req, (int) $notification['user_id'])) {
    return $this->problem(404, 'not-found', 'Notification not found.');
}
```

## Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;   // fail-closed: no admin if key not configured
    }
    $key = $req->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals($this->adminKey, $key);
}
```

## Pagination

`limit` and `offset` are clamped in the repository — never trusted raw from the client:

```php
private const int MAX_LIMIT = 100;

$limit  = max(1, min(self::MAX_LIMIT, $limit));
$offset = max(0, $offset);
```

PDO integer binding prevents SQL injection in LIMIT / OFFSET:

```php
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

## Mark-as-Read Idempotency

```php
/** @return 'ok'|'not_found'|'already_read' */
public function markAsRead(int $id): string
{
    $notification = $this->findById($id);
    if ($notification === null) return 'not_found';
    if ((bool) $notification['is_read']) return 'already_read';

    // ... UPDATE SET is_read = 1, read_at = :now ...
    return 'ok';
}
```

The route handler returns 200 for both `ok` and `already_read` — making the endpoint safe to call
multiple times without side effects.

## Security Patterns

| Pattern | Implementation |
|---------|----------------|
| **Type allowlist** | `in_array($type, ALLOWED_TYPES, true)` — strict match |
| **IDOR → 404** | Return 404 (not 403) to hide user/notification existence |
| **Ownership verification** | Fetch notification, check `user_id` before marking as read |
| **Admin fail-closed** | `if ($this->adminKey === '') return false;` |
| **`ctype_digit()`** | Path param ID validation — ReDoS-safe |
| **Pagination clamping** | `max(1, min(100, $limit))` + `PDO::PARAM_INT` binding |
| **`is_int()` + `> 0`** | Strict user_id check — rejects floats, strings, negatives |

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept free-form `type` string | Unvalidated types pollute the inbox; no way to filter by meaningful categories |
| Return 403 on unauthorized notification access | Reveals whether the notification or user exists — IDOR information leak |
| Return 404 from mark-as-read before ownership check | An attacker learns the notification exists and belongs to someone |
| Allow empty `adminKey` to mean "admin allowed" | Fail-open; any request becomes admin if no key is configured |
| Trust raw `limit` from query string | A request with `limit=999999` causes full table scan |
| Use string interpolation in LIMIT/OFFSET | `"LIMIT {$limit}"` with unvalidated input enables SQL injection |
