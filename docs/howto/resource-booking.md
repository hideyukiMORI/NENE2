# How-to: Resource Booking System

## Overview

This guide covers building a resource booking API with NENE2. Features include capacity enforcement, double-booking prevention, per-user IDOR isolation, and admin cancellation.

**Reference implementation**: `../NENE2-FT/bookinglog/`

---

## Schema Design

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    slot_date   TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    slot_hour   INTEGER NOT NULL,   -- 0-23
    created_at  TEXT    NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE (resource_id, user_id, slot_date, slot_hour)
);
```

Key constraints:
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — one booking per user per slot.
- `cancelled` soft-delete flag — preserve history while allowing rebooking.
- Capacity checked at query time (count active bookings vs resource.capacity).

---

## Route Table

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/resources` | None | List all resources |
| `POST` | `/resources` | Admin | Create a resource |
| `POST` | `/bookings` | User | Book a slot |
| `GET` | `/bookings` | User | List own bookings |
| `GET` | `/bookings/{id}` | User | Get one booking |
| `DELETE` | `/bookings/{id}` | User/Admin | Cancel a booking |

---

## Double-Booking Prevention

First, check if the user already has this slot (application-level):

```php
$stmt = $this->pdo->prepare(
    'SELECT id FROM bookings WHERE resource_id = :rid AND user_id = :uid
     AND slot_date = :d AND slot_hour = :h AND cancelled = 0'
);
$stmt->execute([...]);
if ($stmt->fetch() !== false) {
    return 'double_booking';
}
```

Then check capacity:

```php
$count = $this->countSlotBookings($resourceId, $date, $hour);
if ($count >= (int) $resource['capacity']) {
    return 'capacity_full';
}
```

---

## IDOR Isolation

Users can only read/cancel their own bookings. Return 404 (not 403) to avoid revealing existence:

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Booking not found.');
}
```

---

## Admin Cancel Without X-User-Id

Admin can cancel any booking without providing their own user ID:

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id required.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## Validation Rules

| Field | Rule |
|-------|------|
| `resource_id` | `is_int()` + positive |
| `slot_date` | regex `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0–23 |
| `capacity` | `is_int()` + positive |
| `name` | non-empty string |

---

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Resource created | 201 |
| Booking confirmed | 201 |
| Booking found / list | 200 |
| No X-User-Id | 400 |
| Invalid field type | 422 |
| Invalid date format | 422 |
| slot_hour out of 0–23 | 422 |
| Resource not found | 404 |
| Booking not found | 404 |
| No admin key | 403 |
| Cancel own booking | 200 |
| Cancel other's booking | 403 |
| Double booking | 409 |
| Capacity full | 409 |

---

## VULN Patterns Covered

| VULN | Pattern | Defence |
|------|---------|---------|
| A | IDOR: user sees other's booking | `WHERE user_id = :uid` + 404 |
| B | Negative resource_id | `is_int() + > 0` check |
| C | Zero slot_hour (midnight) | 0-23 range allows 0 |
| D | SQL injection in slot_date | Regex validation + parameterized query |
| E | String resource_id type juggling | `is_int()` strict check |
| F | Double booking | Existence check before INSERT |
| G | Capacity overflow | COUNT vs capacity check |
| H | No X-User-Id | 400 with message |
| I | Cancel other user's booking | `user_id` ownership check → 403 |
| J | List leaks other user's data | `WHERE user_id = :uid` |
| K | Admin cancels any booking | `isAdmin` bypass ownership |
| L | slot_hour = 24 (out of range) | `$hour > 23` → 422 |
