# Field Trial 79 — Appointment Booking with Conflict Detection (bookinglog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.23
**Project**: `/home/xi/docker/NENE2-FT/bookinglog/`
**Theme**: Resource booking API with time-range overlap detection, cancellation, and re-booking of freed slots. Two-tier domain: Resources and Bookings.

---

## What was built

A booking API where named resources (rooms, meeting spaces, equipment) can be booked for time ranges. Overlapping bookings on the same resource are rejected. Cancellation frees the slot for re-booking.

### Domain

- `Resource` — `name` (unique per system)
- `Booking` — `resourceId`, `bookerId`, `startsAt`, `endsAt`, `status` (confirmed/cancelled), `note`
- `BookingStatus` — string enum: `confirmed`, `cancelled`

### Schema

```sql
CREATE TABLE IF NOT EXISTS resources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker_id TEXT NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'confirmed',
    note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_bookings_resource ON bookings(resource_id, starts_at, ends_at);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/resources` | Create a bookable resource |
| GET | `/resources` | List all resources |
| POST | `/resources/{resourceId}/bookings` | Book a time slot (409 on conflict) |
| GET | `/resources/{resourceId}/bookings` | List confirmed bookings (optional `?from=` / `?to=`) |
| GET | `/resources/{resourceId}/bookings/{id}` | Get a booking |
| DELETE | `/resources/{resourceId}/bookings/{id}` | Cancel a booking |

### Key design decisions

**Overlap detection via half-open interval SQL**: `starts_at < NEW.ends_at AND ends_at > NEW.starts_at` is the standard half-open interval overlap test. Adjacent slots (`ends_at = next.starts_at`) do NOT conflict — confirmed by test.

**Only confirmed bookings block**: `WHERE status = 'confirmed'` in the conflict query means cancelled bookings free the slot. Tested via `testCancelledSlotCanBeRebooked`.

**String comparison for ISO timestamps**: SQLite stores timestamps as TEXT; lexicographic ordering of ISO 8601 strings (`YYYY-MM-DD HH:MM:SS`) matches chronological order — the overlap SQL works correctly.

**Time range filter `?from` / `?to`**: `ends_at > from` (booking ends after window start) and `starts_at < to` (booking starts before window end) — consistent with the same half-open interval logic.

**Cancel-via-DELETE returns updated booking**: Returns the cancelled booking object (not 204) so callers can confirm the `cancelled` status without a follow-up GET.

### Test results

```
OK (18 tests, 24 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.23 handled all patterns cleanly:

- Two path parameters (`{resourceId}/{id}`): ✅
- Half-open interval overlap query: ✅ (framework-agnostic raw SQL)
- `?from=` / `?to=` query params: ✅
- Status-scoped conflict detection: ✅
- `DatabaseQueryExecutorInterface::insert()` returning ID: ✅

---

## Summary

Appointment booking with conflict detection works cleanly with NENE2 v1.5.23. The half-open interval overlap query is pure SQL; the framework does not interfere. No framework changes needed.
