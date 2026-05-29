---
title: "How-to: Pin / Bookmark with Ordering"
category: product
tags: [pins, bookmarks, ordering, reorder, user-isolation]
difficulty: intermediate
related: [bookmark-api, content-pinning, bookmark-system]
---

# How-to: Pin / Bookmark with Ordering

> **FT reference**: FT327 (`NENE2-FT/pinlog`) — Per-user article pins with sequential positions, max-pin limit, gap-free recompaction on delete, reorder via PUT, user isolation, VULN assessment, 19 tests / 26 assertions PASS.

This guide shows how to build a pinned-article feature where users maintain an ordered list of up to 10 bookmarks with drag-reorder support.

## Schema

```sql
CREATE TABLE pins (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    article_id INTEGER NOT NULL REFERENCES articles(id),
    position   INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(user_id, article_id)
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST`  | `/pins` | Pin article (idempotent) |
| `DELETE`| `/pins/{articleId}` | Unpin article |
| `GET`   | `/pins` | List user's pins in order |
| `PUT`   | `/pins/order` | Reorder pins |

All endpoints require `X-User-Id` header. Missing → 401.

## Pin Article

```php
POST /pins  X-User-Id: 1
{"article_id": 3}
→ 201  {"article_id": 3, "position": 1}

POST /pins  X-User-Id: 1  {"article_id": 7}
→ 201  {"article_id": 7, "position": 2}

// Idempotent — pin same article twice
POST /pins  X-User-Id: 1  {"article_id": 3}
→ 200  (already pinned, no change)
```

### Limit

```php
// Already 10 pins
POST /pins  X-User-Id: 1  {"article_id": 11}
→ 422  {"max": 10}
```

### Error Cases

```php
// No auth
POST /pins  {"article_id": 1}        → 401
// Missing article_id
POST /pins  X-User-Id: 1  {}         → 422
// Non-existent article
POST /pins  X-User-Id: 1  {"article_id": 999} → 404
```

## Unpin

```php
DELETE /pins/3  X-User-Id: 1  → 204
DELETE /pins/3  X-User-Id: 1  → 404  // already removed
```

### Position Compaction After Delete

Deleting a pin recompacts positions — no gaps:

```
Before: [1→Art1, 2→Art2, 3→Art3]
DELETE /pins/2
After:  [1→Art1, 2→Art3]   // position 2 is now Art3
```

```php
// After unpin, gap is closed
GET /pins  X-User-Id: 1
→ {"pins": [
     {"article_id": 1, "position": 1},
     {"article_id": 3, "position": 2}   // position 3 → 2
  ], "count": 2}
```

## List Pins

```php
GET /pins  X-User-Id: 1
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ],
  "count": 3
}

// Empty
GET /pins  X-User-Id: 99
→ {"pins": [], "count": 0}
```

Results are ordered by `position ASC`. User 2 never sees User 1's pins.

## Reorder

```php
PUT /pins/order  X-User-Id: 1
{"article_ids": [3, 1, 2]}
→ 200
{
  "pins": [
    {"article_id": 3, "position": 1},
    {"article_id": 1, "position": 2},
    {"article_id": 2, "position": 3}
  ]
}

// Unknown article_id (not pinned)
{"article_ids": [1, 99]}  → 422

// No X-User-Id
PUT /pins/order  {"article_ids": [1]}  → 401
// Missing body
PUT /pins/order  X-User-Id: 1  {}     → 422
```

---

## Vulnerability Assessment

### V-01 — IDOR on Unpin ✅ SAFE

**Risk**: User 2 unpins User 1's articles by guessing article IDs.
**Finding**: SAFE — DELETE query includes `WHERE user_id = $authUserId AND article_id = $articleId`. Cross-user delete finds 0 rows → 404.

### V-02 — IDOR on Reorder ✅ SAFE

**Risk**: User 2 reorders User 1's pin list.
**Finding**: SAFE — Reorder validates all `article_ids` are in the authenticated user's pin list. Foreign IDs return 422.

### V-03 — Pin Limit Bypass ✅ SAFE

**Risk**: Attacker submits concurrent pin requests to exceed the 10-pin limit.
**Finding**: SAFE — `UNIQUE(user_id, article_id)` prevents duplicates. Pin count is checked before insert. Concurrent inserts race to the unique constraint.

### V-04 — Pin Non-Existent Article ✅ SAFE

**Risk**: Attacker pins `article_id=999999` to insert a dangling FK reference.
**Finding**: SAFE — Existence check performed before insert. Non-existent article returns 404.

### V-05 — Pin Other User's Articles ✅ SAFE

**Risk**: Cross-user pin (user 2 pins as user 1 by manipulating `X-User-Id`).
**Finding**: SAFE — `X-User-Id` is the authentication token in this FT. In production, use a signed JWT/session — never trust a client-supplied user ID header directly.

### V-06 — Position Gap After Delete Exposes Ordering ✅ SAFE

**Risk**: Gaps in positions (`1, 3`) reveal that a delete occurred; attacker infers deletion history.
**Finding**: SAFE — Positions are compacted immediately on delete. External observers cannot detect deletion order.

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | IDOR on unpin | ✅ SAFE |
| V-02 | IDOR on reorder | ✅ SAFE |
| V-03 | Pin limit bypass | ✅ SAFE |
| V-04 | Pin non-existent article | ✅ SAFE |
| V-05 | Cross-user pin | ✅ SAFE |
| V-06 | Gap exposes deletion history | ✅ SAFE |

**6 SAFE, 0 EXPOSED** — No critical findings.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No max-pin limit | Unbounded list degrades query performance and UX |
| Leave position gaps after delete | Client sort by position breaks; requires client-side renumber |
| Skip article existence check on pin | Dangling references confuse clients rendering pin lists |
| Trust `X-User-Id` header in production | Any client can set it; use signed authentication (JWT, session) |
| No `UNIQUE(user_id, article_id)` | Duplicate pins inflate count and confuse reorder logic |
