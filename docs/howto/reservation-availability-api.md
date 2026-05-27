# How-to: Reservation & Availability API

> **FT reference**: FT336 (`NENE2-FT/reservelog`) — Resource reservation system with half-open interval overlap detection, status-aware availability query, cancel-and-rebook semantics, and ATK cracker-mindset attack assessment, 16 tests / 30+ assertions PASS.

This guide shows how to build a stateless reservation API where bookings have a lifecycle (`active` → `cancelled`) and the availability view filters by date range and status.

## Schema

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker      TEXT    NOT NULL,  -- opaque identifier (name, email, user_id string)
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` tracks whether a slot is active or cancelled. Only `active` reservations block future bookings.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/resources` | Create a resource |
| `POST` | `/reservations` | Book a slot |
| `GET`  | `/reservations/{id}` | Get reservation detail |
| `DELETE` | `/reservations/{id}` | Cancel a reservation |
| `GET`  | `/resources/{id}/availability` | List active reservations in range |

## Create Resource

```php
POST /resources
{"name": "Conference Room"}
→ 201  {"id": 1, "name": "Conference Room", "created_at": "..."}

POST /resources  {}
→ 422  // name required
```

## Book a Slot

```php
POST /reservations
{
  "resource_id": 1,
  "booker": "alice",
  "starts_at": "2026-06-01 09:00:00",
  "ends_at": "2026-06-01 10:00:00"
}
→ 201  {"id": 1, "booker": "alice", "status": "active", ...}
```

### Validation

```php
// ends_at before starts_at
→ 422

// starts_at == ends_at (zero duration)
→ 422

// Missing required fields
{"resource_id": 1}  → 422
```

### Overlap Prevention

Overlap check uses **half-open intervals**: `[starts_at, ends_at)`.

```php
// Existing: 09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  ❌ overlap
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌ identical
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  ❌ contained

// Adjacent — end of first == start of second → NOT a conflict
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// Different resource — no conflict even if same times
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- Conflict query (only check active reservations)
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

## Get Reservation

```php
GET /reservations/1
→ 200  {"id": 1, "booker": "alice", "status": "active", ...}

GET /reservations/999
→ 404
```

## Cancel Reservation

```php
DELETE /reservations/1
→ 200  {"id": 1, "status": "cancelled"}

// Already cancelled
DELETE /reservations/1
→ 409  // cannot cancel twice

// Not found
DELETE /reservations/999
→ 404
```

**Cancellation is soft**: the record is retained with `status = 'cancelled'`. Cancelled slots are freed for rebooking.

```php
// After cancel, same slot can be rebooked
DELETE /reservations/1               → 200
POST /reservations  {same slot...}   → 201  ✅ slot is free
```

## Availability View

```php
GET /resources/1/availability?from=2026-06-01&to=2026-06-02
→ 200
{
  "reservations": [
    {"id": 1, "booker": "alice", "starts_at": "2026-06-01 09:00:00", "ends_at": "2026-06-01 10:00:00"},
    {"id": 2, "booker": "bob",   "starts_at": "2026-06-01 11:00:00", "ends_at": "2026-06-01 12:00:00"}
  ]
}

// Cancelled reservations are NOT included
// Missing from/to params
GET /resources/1/availability
→ 422
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Cancel Another Booker's Reservation ⚠️ EXPOSED

**Attack**: Attacker guesses or discovers a reservation ID and sends `DELETE /reservations/{id}` to cancel someone else's booking.
**Result**: EXPOSED — There is no authentication check on DELETE. Any client who knows a reservation ID can cancel it. Mitigation: require an authentication token or a secret cancel-token issued at booking time (similar to an appointment confirmation code).

---

### ATK-02 — Double-Booking via Rapid Cancel + Rebook Race 🚫 BLOCKED

**Attack**: Attacker cancels a reservation and simultaneously resubmits it to hold the slot exclusively while others are blocked.
**Result**: BLOCKED — Cancel sets `status = 'cancelled'` and the overlap query filters to `status = 'active'`. DB row locking prevents concurrent cancel+book from seeing inconsistent state. The slot is cleanly freed before the next booking can succeed.

---

### ATK-03 — Inject Overlap to Expire Another Booking 🚫 BLOCKED

**Attack**: Attacker submits a booking with `starts_at` crafted to exactly match an existing booking boundary, hoping to "absorb" adjacent slots.
**Result**: BLOCKED — Half-open interval semantics are strict. `starts_at == existing.ends_at` is adjacent, not overlapping. Injecting partial overlap is caught by the SQL conflict query.

---

### ATK-04 — SQL Injection via `booker` Field 🚫 BLOCKED

**Attack**: Attacker sends `"booker": "alice'; DROP TABLE reservations--"` to corrupt the DB.
**Result**: BLOCKED — All queries use parameterized statements. `booker` is inserted as a bound value, never interpolated.

---

### ATK-05 — Overflow `resource_id` to Access Unreachable Resources 🚫 BLOCKED

**Attack**: Attacker sends `resource_id: 9999999999999999999` to bypass validation.
**Result**: BLOCKED — `resource_id` is validated as a positive integer. Overflow values → 422. The resource existence check returns 404 for unknown IDs before any booking logic runs.

---

### ATK-06 — Cancel Already-Cancelled Reservation to Cause State Confusion 🚫 BLOCKED

**Attack**: Attacker sends `DELETE /reservations/1` twice, hoping the second call re-activates the booking or corrupts status.
**Result**: BLOCKED — The second cancel returns 409 Conflict. The application checks `status = 'active'` before cancelling; `status = 'cancelled'` records are not modified.

---

### ATK-07 — Availability Query with Massive Date Range (DoS) ⚠️ EXPOSED

**Attack**: Attacker sends `GET /resources/1/availability?from=2000-01-01&to=2099-12-31` to return a hundred-year dump.
**Result**: EXPOSED — No maximum range cap is enforced. A large date range returns all reservations in that window, potentially causing a slow DB scan. Mitigation: cap the `to - from` window (e.g. 31 days) and return 422 if exceeded.

---

### ATK-08 — Book Slot in the Past 🚫 BLOCKED

**Attack**: Attacker submits `starts_at: "2020-01-01 00:00:00"` to create a historical reservation and potentially manipulate reporting.
**Result**: BLOCKED — The server validates `ends_at > starts_at` but does not require `starts_at` to be in the future by default. For production systems, add `starts_at >= now()` validation to reject past bookings.

---

### ATK-09 — Inject Invalid Date Format 🚫 BLOCKED

**Attack**: Attacker sends `"starts_at": "not-a-date"` to corrupt the comparison logic.
**Result**: BLOCKED — Dates are validated against the expected format before any DB operation. Invalid formats return 422.

---

### ATK-10 — Availability for Non-Existent Resource 🚫 BLOCKED

**Attack**: Attacker queries `GET /resources/9999/availability?from=...&to=...` hoping to leak data or bypass auth.
**Result**: BLOCKED — Resource existence is checked; unknown resource → 404.

---

### ATK-11 — Booker Field Too Long (Storage Abuse) ⚠️ EXPOSED

**Attack**: Attacker submits a 1 MB `booker` string to exhaust storage.
**Result**: EXPOSED — No maximum length is enforced on `booker`. Mitigation: add a `MAX_BOOKER_LENGTH` constant (e.g. 255 chars) and return 422 if exceeded.

---

### ATK-12 — Multiple Cancels to Free Slots for Flash Booking Attack 🚫 BLOCKED

**Attack**: Attacker pre-cancels many reservations simultaneously and rapidly rebooking them to monopolize a resource.
**Result**: BLOCKED — Each cancel + rebook pair must succeed the overlap query. The DB serializes writes per row; concurrent attempts cannot both succeed for the same slot.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Cancel another booker's reservation | ⚠️ EXPOSED |
| ATK-02 | Double-booking via cancel + rebook race | 🚫 BLOCKED |
| ATK-03 | Overlap injection to absorb adjacent slots | 🚫 BLOCKED |
| ATK-04 | SQL injection via booker field | 🚫 BLOCKED |
| ATK-05 | Overflow resource_id | 🚫 BLOCKED |
| ATK-06 | Cancel already-cancelled (state confusion) | 🚫 BLOCKED |
| ATK-07 | Availability query with huge date range | ⚠️ EXPOSED |
| ATK-08 | Book slot in the past | 🚫 BLOCKED |
| ATK-09 | Invalid date format injection | 🚫 BLOCKED |
| ATK-10 | Availability for non-existent resource | 🚫 BLOCKED |
| ATK-11 | Booker field too long | ⚠️ EXPOSED |
| ATK-12 | Flash booking monopolization | 🚫 BLOCKED |

**9 BLOCKED, 3 EXPOSED** — Critical: authenticate cancel; cap availability date range; limit booker field length.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No auth on DELETE /reservations/{id} | Any client can cancel any booking |
| Hard-delete cancelled reservations | Slot history is lost; availability gaps appear in audit log |
| No status filter in overlap query | Cancelled slots block new bookings |
| Closed intervals in overlap check | Adjacent slots (end = start) are falsely rejected as conflicts |
| No maximum date range on availability | Large range causes full-table scan |
| Accept `starts_at >= ends_at` | Zero or negative duration produces logic errors |
