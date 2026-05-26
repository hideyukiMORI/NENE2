# How to Build System Announcement Management

> **Pattern proven by FT190 announcelog** — Time-based system announcements with admin key authentication, per-user dismissal, and priority ordering. 38 tests / 93 assertions PASS。

---

## What This Covers

A system announcement API for broadcasting maintenance notices, feature updates, and alerts:

1. **Create/Update/Delete** — admin-only operations via constant-time key comparison
2. **List active** — UTC time-filtered by `starts_at` / `ends_at`
3. **Dismiss** — per-user opt-out persisted as idempotent UPSERT

---

## Schema

```sql
CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    starts_at  TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at    TEXT    NOT NULL,   -- ISO 8601 UTC
    priority   INTEGER NOT NULL DEFAULT 0,  -- higher = shown first
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE announcement_dismissals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL,
    announcement_id INTEGER NOT NULL,
    dismissed_at    TEXT    NOT NULL,
    UNIQUE(user_id, announcement_id)
);
```

`UNIQUE(user_id, announcement_id)` enables idempotent dismissal. `starts_at` / `ends_at` are ISO 8601 strings — lexicographic comparison works correctly for UTC datetimes.

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/announcements` | `X-Admin-Key` | Create announcement (201) |
| `PUT` | `/announcements/{id}` | `X-Admin-Key` | Update announcement (200) |
| `DELETE` | `/announcements/{id}` | `X-Admin-Key` | Delete announcement (200) |
| `GET` | `/announcements` | optional `X-User-Id` | List currently active announcements |
| `POST` | `/announcements/{id}/dismiss` | `X-User-Id` | Dismiss for this user (200) |

---

## Core Pattern: Constant-Time Admin Key Verification

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    // Empty adminKey config means no admin access — fail closed
    if ($this->adminKey === '') {
        return false;
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    // hash_equals: constant-time — prevents timing attacks on key comparison
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

**Why not `===`:** String comparison short-circuits on first mismatch. An attacker can measure timing differences to find partial prefix matches, then brute-force character-by-character. `hash_equals()` takes constant time regardless of where the mismatch is.

**Fail-closed:** An empty `adminKey` configuration always returns `false` — there is no accidental "open admin" mode.

---

## Core Pattern: UTC Time-Based Filtering

```php
// List announcements active right now
$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

SELECT ... FROM announcements
WHERE starts_at <= :now AND ends_at > :now
ORDER BY priority DESC, id DESC
```

ISO 8601 strings in UTC lexicographically sort correctly — `'2025-06-01T...' > '2025-05-01T...'`. Always use UTC in the database.

The `ends_at > :now` (strict greater-than) means an announcement expires precisely at `ends_at`, not one second after.

---

## Core Pattern: Per-User Dismissal (Idempotent)

```php
// UNIQUE(user_id, announcement_id) enables safe repeated dismiss calls
INSERT INTO announcement_dismissals (user_id, announcement_id, dismissed_at)
VALUES (:user_id, :announcement_id, :now)
ON CONFLICT(user_id, announcement_id) DO NOTHING
```

A user calling `POST /announcements/5/dismiss` twice is safe — the second call succeeds silently. The client never needs to check first.

---

## Core Pattern: Optional User Context on List

```php
// Without X-User-Id: show all active announcements
// With X-User-Id: exclude dismissed ones for that user

// Without user:
WHERE a.starts_at <= :now AND a.ends_at > :now

// With user (LEFT JOIN + IS NULL filter):
LEFT JOIN announcement_dismissals d
  ON d.announcement_id = a.id AND d.user_id = :user_id
WHERE a.starts_at <= :now AND a.ends_at > :now
  AND d.id IS NULL
```

This single `GET /announcements` endpoint handles both unauthenticated (monitoring, admin view) and authenticated (UI showing relevant banners) use cases.

---

## Core Pattern: ends_at Must Be After starts_at

```php
// Server-side validation — not just client trust
if ($body['ends_at'] <= $body['starts_at']) {
    return 'ends_at must be after starts_at.';
}
```

An announcement with `ends_at <= starts_at` is invisible immediately upon creation — validate and reject rather than silently accept broken data.

---

## Response Design

| Scenario | Status | Body |
|---|---|---|
| Create successful | 201 | `{announcement: {id, title, body, starts_at, ends_at, priority}}` |
| Update successful | 200 | `{announcement: {...}}` |
| Delete successful | 200 | `{deleted: true}` |
| List active | 200 | `{data: [...], total: N}` |
| Dismiss | 200 | `{dismissed: true}` |
| Admin key missing/wrong | 401 | `{error: "Admin key required."}` |
| Not found | 404 | `{error: "Announcement not found."}` |
| Validation failed | 422 | `{error: "..."}` |

`created_at` / `updated_at` are **not** in the public response — they are internal metadata.

---

## Test Results (FT190)

```
38 tests / 93 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

Source: [`../NENE2-FT/announcelog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/announcelog)
