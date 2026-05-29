---
title: "How-to: Wish List API (VULN-A~L Security Assessment)"
category: product
tags: [wishlist, crud, idor, security-assessment]
difficulty: advanced
related: [wishlist-management, media-watchlist]
---

# How-to: Wish List API (VULN-A~L Security Assessment)

This guide demonstrates a personal wish list API with full CRUD, admin override, and security hardening covering VULN-A through VULN-L.

## Pattern Overview

- Users manage private wish lists via `POST /wishes`, `GET /wishes/{id}`, `PATCH /wishes/{id}`, `DELETE /wishes/{id}`.
- `GET /users/{userId}/wishes` lists a user's wishes (owner or admin only).
- IDOR: non-owners always get 404 (not 403) to avoid revealing resource existence.
- Admins identified by `X-Admin-Key` header; fail-closed when key is empty.

## Schema

```sql
CREATE TABLE IF NOT EXISTS wishes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL DEFAULT '',
    priority   INTEGER NOT NULL DEFAULT 0,
    fulfilled  INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_wishes_user ON wishes (user_id, priority DESC, id DESC);
```

## VULN-A: SQL Injection

All queries use PDO prepared statements with named placeholders. The title `'; DROP TABLE wishes; --` is stored verbatim with no damage:

```php
$this->pdo->prepare(
    'INSERT INTO wishes (user_id, title, ...) VALUES (:uid, :title, ...)'
)->execute([':uid' => $userId, ':title' => $title, ...]);
```

## VULN-B: Mass Assignment

The `update()` handler maintains an explicit field allowlist. Fields like `user_id`, `created_at`, or `id` sent by a client are silently ignored:

```php
$allowed = ['title', 'url', 'priority', 'fulfilled'];
foreach ($allowed as $field) {
    if (array_key_exists($field, $fields)) { ... }
}
```

## VULN-C: IDOR

Reads and deletes by non-owners return 404 (not 403) to hide resource existence:

```php
if (!$isAdmin && (int) $wish['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

The list endpoint similarly hides other users' lists:

```php
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## VULN-D: Admin Fail-Closed

An empty `adminKey` never grants admin privileges. Without this guard, an unconfigured deployment would treat every `X-Admin-Key: ` header as valid:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G: ReDoS

Path parameter IDs are validated with `ctype_digit()` instead of regex patterns that could be subject to ReDoS:

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'Wish not found.');
}
```

## VULN-I: Negative Values

Priority must be 0–100. Negative values and values over 100 return 422:

```php
if (!is_int($priorityRaw) || $priorityRaw < 0 || $priorityRaw > 100) {
    return $this->problem(422, 'validation-failed', 'priority must be an integer 0–100.');
}
```

## VULN-J: JSON Type Confusion

`is_int()` rejects string-encoded numbers (`"5"`) and floats (`1.5`) for the `priority` field. `is_bool()` rejects integer `1`/`0` for `fulfilled`:

```php
$p = $body['priority'];
if (!is_int($p) || $p < 0 || $p > 100) { return 422; }

$f = $body['fulfilled'];
if (!is_bool($f)) { return 422; }
```

## Routes

```
POST   /wishes                 Create a wish (X-User-Id required)
GET    /wishes/{id}            Get wish by ID (owner or admin)
PATCH  /wishes/{id}            Update wish fields (owner only)
DELETE /wishes/{id}            Delete wish (owner or admin)
GET    /users/{userId}/wishes  List user's wishes (owner or admin)
```

## Validation Summary

| Field | Rule |
|---|---|
| `X-User-Id` | Required for POST/PATCH; `ctype_digit`, >0 |
| `title` | Non-empty, max 200 chars |
| `url` | Optional, max 500 chars |
| `priority` | Integer 0–100 (not string/float); default 0 |
| `fulfilled` | Boolean only (not 1/0) on PATCH |
| `{id}` path | `ctype_digit`, max 18 chars, >0; else 404 |

## See Also

- FT207 source: `../NENE2-FT/wishlistlog/`
- Related: `docs/howto/booking-resource.md` (FT201, also VULN)
- Related: `docs/howto/coupon-redemption.md` (FT204, VULN + ATK)
