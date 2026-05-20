# Field Trial 67 — Time Slot Reservation with Availability and Double-Booking Prevention

**Date**: 2026-05-20
**Theme**: Resource booking with temporal overlap detection, adjacency boundary handling, and availability queries
**Project**: `/home/xi/docker/NENE2-FT/reservelog/`
**NENE2 version**: 1.5.22

---

## Summary

Implemented a time slot reservation system: multiple bookable resources (e.g., meeting rooms), each with non-overlapping reservations. Double-booking is prevented via an overlap SQL query. Cancellation frees the slot. An availability endpoint lists active reservations in a given date/time range.

**Result**: 17 tests, 24 assertions — all pass. PHPStan level 8 clean. CS-Fixer clean. No NENE2 changes required.

---

## What Was Built

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/resources` | Register a bookable resource |
| `POST` | `/reservations` | Book a time slot |
| `GET` | `/reservations/{id}` | Get a reservation |
| `DELETE` | `/reservations/{id}` | Cancel a reservation |
| `GET` | `/resources/{resourceId}/availability?from=&to=` | List active reservations in range |

### Domain Model

```
resources(id, name UNIQUE)
reservations(id, resource_id FK, booker, starts_at, ends_at, status, created_at)
```

---

## Overlap Detection

Two intervals `[a, b)` and `[c, d)` overlap when `a < d AND b > c`.

```sql
SELECT id FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at > ?     -- existing.ends_at   > new.starts_at
```

**Adjacent slots do not conflict**: booking `09:00–10:00` and then `10:00–11:00` succeeds because `10:00 < 10:00` is false — the `starts_at < ends_at` condition correctly excludes touching intervals.

This is the standard Allen interval algebra "overlap" check. Once internalized, it reads naturally as: "any reservation whose window overlaps with the requested window."

---

## Frictions Encountered

### None

FT67 was friction-free with NENE2 1.5.22 as-is. The v1.5.22 router fix (static routes prioritized over parameterized) was not needed here — there were no static/parameterized siblings on the same method.

**One positive observation**: `Router::PARAMETERS_ATTRIBUTE` correctly disambiguates path parameters when a route has two different parameter names (`{id}` vs `{resourceId}`). The pattern `(array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE)` with named keys is clean and type-safe at the use site.

---

## Patterns Validated

### Pattern: Temporal Overlap Check via SQL

```php
public function hasConflict(int $resourceId, string $startsAt, string $endsAt): bool
{
    $rows = $this->executor->fetchAll(
        "SELECT id FROM reservations
         WHERE resource_id = ?
           AND status = 'active'
           AND starts_at < ?   -- new slot ends after existing starts
           AND ends_at   > ?", -- new slot starts before existing ends
        [$resourceId, $endsAt, $startsAt],
    );
    return $rows !== [];
}
```

The `excludeId` optional parameter allows checking overlaps while ignoring a specific reservation (useful for "move booking" use cases).

### Pattern: ISO 8601 String Comparison for Dates

SQLite has no native datetime type; ISO 8601 strings (`'YYYY-MM-DD HH:MM:SS'`) compare correctly with `<` and `>` because lexicographic order matches chronological order for fixed-format dates. No date functions needed for overlap detection.

### Pattern: Cancel via DELETE → 200 with Confirmation Body

```php
return $this->json->create(['id' => $id, 'status' => 'cancelled']);
```

Using 200 (not 204) on DELETE to return a confirmation body avoids the client needing a follow-up GET to confirm the state. Consistent with the "partial" pattern in FT64 bulk delete.

---

## No NENE2 Changes Required

All patterns implementable with NENE2 1.5.22 as-is.
