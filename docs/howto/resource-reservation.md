# How-to: Resource Reservation / Time Slot Booking API

This guide shows how to build a time-slot booking system with overlap prevention using NENE2.
Pattern demonstrated by the **reservationlog** field trial (FT216).

## Features

- Create named resources (meeting rooms, equipment, etc.) — admin only
- Book time slots with automatic overlap detection
- List bookings per resource (admin) or per user (self)
- Cancel bookings with ownership verification
- Public responses exclude `user_id` (IDOR prevention)
- Admin view includes `user_id` for auditing

## Schema

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,   -- ISO 8601 UTC
    note        TEXT,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
);

-- Index for fast overlap queries
CREATE INDEX IF NOT EXISTS idx_bookings_resource_time
    ON bookings (resource_id, starts_at, ends_at);
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/resources` | Admin | Create resource |
| `GET` | `/resources/{id}/bookings` | Admin | List all bookings for resource |
| `POST` | `/resources/{id}/book` | User | Book a time slot |
| `GET` | `/bookings` | User | List own bookings |
| `DELETE` | `/bookings/{id}` | User | Cancel own booking |

## Overlap Detection

Two time ranges `[A.start, A.end)` and `[B.start, B.end)` overlap iff:

```
A.start < B.end AND A.end > B.start
```

This correctly handles all overlap cases (contains, overlaps, identical) while allowing
adjacent slots (A.end = B.start is OK — half-open interval semantics).

```sql
SELECT COUNT(*) FROM bookings
WHERE resource_id = :rid
  AND starts_at < :ends_at
  AND ends_at   > :starts_at
```

```php
public function book(int $resourceId, int $userId, string $startsAt, string $endsAt, ?string $note): ?Booking
{
    $overlap = $this->countOverlaps($resourceId, $startsAt, $endsAt, excludeId: null);
    if ($overlap > 0) {
        return null; // → 409 Conflict
    }
    // ... INSERT
}
```

## Value Objects

Using readonly value objects for domain clarity:

```php
final readonly class Booking
{
    public function __construct(
        public int     $id,
        public int     $resourceId,
        public int     $userId,
        public string  $startsAt,
        public string  $endsAt,
        public ?string $note,
        public string  $createdAt,
    ) {}

    /** Public view: excludes user_id (IDOR prevention) */
    public function toPublicArray(): array { ... }

    /** Admin view: includes user_id for auditing */
    public function toAdminArray(): array { ... }
}
```

## IDOR Prevention

Bookings expose public and admin views with different fields:

```php
// User: GET /bookings — public view (no user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toPublicArray(), $bookings),
    'total' => count($bookings),
]);

// Admin: GET /resources/{id}/bookings — admin view (includes user_id)
return $this->responseFactory->create([
    'data'  => array_map(fn(Booking $b) => $b->toAdminArray(), $bookings),
    'total' => count($bookings),
]);
```

Cancel returns 403 (not 404) when a user tries to cancel someone else's booking,
since the booking ID is already visible (not hidden existence):

```php
/** @return 'cancelled'|'not_found'|'not_owner' */
public function cancel(int $id, int $userId): string
{
    $booking = $this->findBookingById($id);
    if ($booking === null) return 'not_found';     // → 404
    if ($booking->userId !== $userId) return 'not_owner'; // → 403
    // DELETE ...
    return 'cancelled'; // → 200
}
```

## Security Patterns

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` before `hash_equals()`
- **`ctype_digit()`**: ReDoS-safe integer validation for path IDs
- **ISO 8601 validation**: Regex pattern + lexicographic comparison (works in UTC)
- **Note length guard**: `mb_strlen($note) > 500` returns 422
- **Cascade delete**: `ON DELETE CASCADE` ensures bookings are removed with resource

## VULN + ATK Assessment (FT216)

This FT passes full VULN-A through VULN-L and ATK-01 through ATK-12 evaluations:

- **VULN-B**: No mass assignment — resource/booking fields are explicitly bound
- **VULN-C**: Cancel returns 403 for wrong owner; resource/booking lookups use typed IDs
- **VULN-D**: Admin fail-closed — empty admin key always returns false
- **VULN-F**: ISO 8601 regex prevents datetime injection
- **VULN-G**: `ctype_digit()` guards all integer path params
- **ATK-01**: SQL injection blocked via parameterized queries
- **ATK-02/03**: Integer overflow in IDs blocked by `strlen > 18` guard
- **ATK-06**: Authentication bypass blocked by fail-closed admin check
- **ATK-09**: Overlap logic correctly prevents double-booking
