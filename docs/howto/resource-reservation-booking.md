# How-to: Resource Reservation & Booking API

> **FT reference**: FT335 (`NENE2-FT/reservationlog`) — Resource time-slot booking with half-open interval overlap prevention, user_id excluded from public responses, cancel IDOR protection (403), admin/user dual-level access, 30 tests / 70+ assertions PASS.

This guide shows how to build a room/resource booking system: create bookable resources (admin), book time slots (users), prevent overlaps atomically, and protect user privacy in public responses.

## Schema

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,
    note        TEXT,               -- optional
    created_at  TEXT    NOT NULL
);
```

Dates are stored as ISO 8601 UTC strings (`2026-06-01T09:00:00Z`). Lexicographic comparison is correct for UTC ISO strings.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/resources` | Admin | Create a bookable resource |
| `POST` | `/resources/{id}/book` | User | Book a time slot |
| `DELETE` | `/bookings/{id}` | User (owner) | Cancel own booking |
| `GET` | `/bookings` | User | List own bookings |
| `GET` | `/resources/{id}/bookings` | Admin | List all bookings for resource |

## Create Resource (Admin)

```php
POST /resources
X-Admin-Key: admin-secret
{"name": "Meeting Room 1"}
→ 201  {"resource": {"id": 1, "name": "Meeting Room 1", "created_at": "..."}}

// No admin key
POST /resources  {"name": "Room"}
→ 401

POST /resources  X-Admin-Key: admin-secret  {"name": ""}
→ 422  // name required

POST /resources  X-Admin-Key: admin-secret  {"name": "x".repeat(201)}
→ 422  // name too long (max 200 chars)
```

## Book a Time Slot

```php
POST /resources/1/book
X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z"}
→ 201
{
  "booking": {
    "id": 1,
    "resource_id": 1,
    "starts_at": "2026-06-01T09:00:00Z",
    "ends_at": "2026-06-01T10:00:00Z",
    "note": null,
    "created_at": "..."
    // user_id is NOT returned — IDOR prevention
  }
}

// Optional note
POST /resources/1/book  X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z", "note": "Team meeting"}
→ 201  {"booking": {..., "note": "Team meeting"}}
```

### Validation Errors

```php
// ends_at before starts_at
{"starts_at": "2026-06-01T10:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at == ends_at (zero-duration)
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// Missing starts_at
{"ends_at": "2026-06-01T10:00:00Z"}
→ 422

// Missing X-User-Id
POST /resources/1/book  (no header)
→ 400

// Zero or overflow X-User-Id
X-User-Id: 0    → 400
X-User-Id: 9999999999999999999  → 400

// Unknown resource
POST /resources/9999/book  X-User-Id: 101  {...}
→ 404
```

## Overlap Prevention — Half-Open Intervals

Slots are **half-open**: `[starts_at, ends_at)`. The end of one booking and the start of the next are equal but not overlapping.

```php
// Book 09:00–10:00
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201

// Overlapping — starts inside existing slot
POST /resources/1/book  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅ adjacent, allowed

// Overlapping — inside
POST /resources/1/book  {"starts_at": "09:30", "ends_at": "11:00"}  → 409  ❌ overlap

// Identical slot
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌

// Non-overlapping on same resource
POST /resources/1/book  {"starts_at": "14:00", "ends_at": "15:00"}  → 201  ✅

// Same slot on a DIFFERENT resource — always allowed
POST /resources/2/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

### Overlap SQL Query

```sql
-- Detect conflict: NOT (new.ends_at <= existing.starts_at OR new.starts_at >= existing.ends_at)
SELECT COUNT(*) FROM bookings
WHERE resource_id = ?
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

If count > 0, return 409 Conflict.

## Cancel Booking (IDOR Protection)

```php
DELETE /bookings/1
X-User-Id: 101
→ 200  {"cancelled": true}

// Wrong user → 403, NOT 404
DELETE /bookings/1
X-User-Id: 102
→ 403  // user 102 does not own booking 1

// Not found → 404
DELETE /bookings/9999
X-User-Id: 101
→ 404
```

**Return 403 (not 404) for wrong-user cancel** — returning 404 would let users probe other users' booking IDs. The booking exists; the requester is not the owner.

```php
// After cancellation, slot is free
DELETE /bookings/1  X-User-Id: 101  → 200
POST /resources/1/book  X-User-Id: 102  {"starts_at": "09:00", "ends_at": "10:00"}  → 201
```

### ID Validation

```php
DELETE /bookings/0                    → 422  // zero is invalid
DELETE /bookings/99999999999999999999 → 422  // overflow
POST /resources/0/book  X-User-Id: 101 {...} → 422  // resource_id zero invalid
```

## List Own Bookings (User)

```php
GET /bookings
X-User-Id: 101
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "resource_id": 1, "starts_at": "...", "ends_at": "...", "note": null, "created_at": "..."}
    // user_id is NOT included
  ]
}

// Other users' bookings are not returned
// User 101 only sees their own bookings even if user 102 has bookings
```

## List Resource Bookings (Admin)

```php
GET /resources/1/bookings
X-Admin-Key: admin-secret
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "user_id": 101, "starts_at": "2026-06-01T09:00:00Z", ...},  // user_id visible
    {"id": 2, "user_id": 102, "starts_at": "2026-06-01T14:00:00Z", ...}
  ]
}
// Ordered by starts_at ASC

GET /resources/1/bookings  (no admin key)  → 401
GET /resources/9999/bookings  X-Admin-Key: key  → 404
```

Admin receives `user_id` in the response; public user endpoints never return `user_id`.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Overlap check: `new.starts_at < existing.ends_at AND new.ends_at > existing.starts_at` (closed intervals) | Adjacent slots (end of A = start of B) are rejected as overlapping |
| Return `user_id` in public booking responses | Exposes who owns each booking, enabling user enumeration |
| Return 404 for wrong-user cancel | Attacker confirms the booking exists; use 403 to acknowledge ownership mismatch |
| Accept `starts_at >= ends_at` | Zero or negative duration bookings corrupt availability calculations |
| No resource_id scoping in overlap query | User A's booking on Resource 1 blocks Resource 2 (false conflict) |
| Trust `user_id` from request body | Attacker makes bookings on behalf of any user; always read identity from `X-User-Id` header |
