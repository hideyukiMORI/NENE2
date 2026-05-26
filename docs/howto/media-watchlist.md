# How-to: Media Watchlist API

> **FT reference**: FT59 (`NENE2-FT/watchlog`) — Media Watch List API

Demonstrates a personal media watchlist with backed string enums for status and type,
optional nullable fields using `array_key_exists`, archive/restore via POST action
endpoints, and a 1–5 integer rating. All status and type validation uses PHP's
`BackedEnum::tryFrom()` to ensure only known values are accepted.

---

## Routes

| Method   | Path                       | Description                                   |
|----------|----------------------------|-----------------------------------------------|
| `GET`    | `/watch`                   | List entries (filtered and paginated)         |
| `POST`   | `/watch`                   | Add an entry to the watchlist                 |
| `GET`    | `/watch/{id}`              | Get a single entry                            |
| `PATCH`  | `/watch/{id}/status`       | Update status (and optionally rating/note)    |
| `POST`   | `/watch/{id}/archive`      | Move entry to archive                         |
| `POST`   | `/watch/{id}/restore`      | Restore an archived entry                     |
| `DELETE` | `/watch/{id}`              | Permanently delete an entry                   |

---

## Backed enum validation

Status and media type are validated with `BackedEnum::tryFrom()`. The enum also
doubles as the type in serialisation, so the string value written to the DB and
the string value in the JSON response stay in sync automatically.

```php
enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching    = 'watching';
    case Completed   = 'completed';
    case Dropped     = 'dropped';
}

enum MediaType: string
{
    case Movie = 'movie';
    case Tv    = 'tv';
}
```

In the controller, `tryFrom()` returns `null` for unknown values, which maps to a 422:

```php
$statusRaw = isset($body['status']) && is_string($body['status']) ? $body['status'] : null;
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw === null) {
    $errors[] = new ValidationError('status', 'status is required.', 'required');
} elseif ($status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

The two-step check distinguishes "field absent" (required) from "field present but
invalid" (invalid_value), producing better error messages.

---

## Listing with enum-typed filters

Query parameters are parsed through `QueryStringParser`, then validated via `tryFrom()`:

```php
$statusRaw = QueryStringParser::string($request, 'status');   // null if absent
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

This pattern — parse, attempt enum conversion, validate — keeps routing logic
out of domain code. The repository accepts `?WatchStatus` and `?MediaType` and
filters accordingly.

**Supported filters**:
- `?status=watching` — filter by status
- `?media_type=movie` — filter by media type
- `?include_archived=1` — include archived entries (excluded by default)
- `?limit=20&offset=0` — pagination

---

## Nullable fields with `array_key_exists`

`rating` and `note` are nullable — callers can explicitly set them to `null` to clear
them. Using `isset()` would miss an explicitly-sent `null`. Use `array_key_exists()`:

```php
// ✓ Correct: distinguishes absent from explicitly null
$rating = array_key_exists('rating', $body) ? $body['rating'] : null;

// ✗ Wrong: array_key_exists($body, 'rating') swallows intentional null
if ($rating !== null) {
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5.', 'out_of_range');
    }
}
```

`is_int($rating)` rejects JSON floats (`4.0` → PHP `float`) and strings (`"4"`).
Only a JSON integer literal (`4`) passes the strict type check.

---

## Archive / restore via POST action endpoints

Archive and restore are mutations (they change state and record a timestamp), so they
use `POST`, not `DELETE` or `PATCH`. This follows the action endpoint pattern:

```php
// POST /watch/{id}/archive
private function archive(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}

// POST /watch/{id}/restore
private function restore(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}
```

`archive()` sets `archived_at` to the current timestamp; `restore()` sets it back to
`null`. The list endpoint hides archived entries by default (`include_archived=false`).

Why `POST` and not `DELETE` for archive? `DELETE` implies permanent removal. Archive
is a soft state change — the entry remains in the DB and is recoverable. Naming the
endpoints after the action (`/archive`, `/restore`) makes the intent explicit.

---

## Schema: CHECK constraints match enum values

```sql
CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    media_type  TEXT NOT NULL CHECK(media_type IN ('movie', 'tv')),
    status      TEXT NOT NULL DEFAULT 'want-to-watch'
                              CHECK(status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK(rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    archived_at TEXT
);
```

DB `CHECK` constraints mirror the enum cases — if a new status is added to the enum
without updating the `CHECK`, the insert fails at the DB layer. Keep both in sync:
add the new case to the enum, the `CHECK`, and any migration.

`rating CHECK(rating IS NULL OR ...)` correctly allows the column to be `NULL` while
still enforcing the 1–5 range when a value is present.

`archived_at TEXT` (nullable) acts as the archival flag: `NULL` = active,
non-null = archived. This is the minimal soft-archive pattern — no separate
`is_archived BOOLEAN` column needed.

---

## Indexes for list performance

```sql
CREATE INDEX idx_watch_status      ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
```

`idx_watch_archived_at` supports the common `WHERE archived_at IS NULL` filter
(active entries). SQLite can use this index for `IS NULL` conditions via a partial
index pattern, but a plain index is sufficient for most watchlists.

---

## Serialisation

```php
/** @return array<string, mixed> */
private function serialize(WatchEntry $entry): array
{
    return [
        'id'          => $entry->id,
        'title'       => $entry->title,
        'media_type'  => $entry->mediaType->value,  // enum → string
        'status'      => $entry->status->value,      // enum → string
        'rating'      => $entry->rating,             // int|null
        'note'        => $entry->note,
        'created_at'  => $entry->createdAt,
        'updated_at'  => $entry->updatedAt,
        'archived_at' => $entry->archivedAt,         // string|null
    ];
}
```

`->value` on a backed enum returns the string case value (e.g. `'want-to-watch'`).
Serialise enums this way rather than calling `->name` — the name is the PHP identifier
(`WantToWatch`), not the API contract value.

---

## Related howtos

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — state machine with status transitions
- [`soft-delete.md`](soft-delete.md) — soft delete with `deleted_at` timestamp
- [`implement-patch-endpoint.md`](implement-patch-endpoint.md) — partial updates with `array_key_exists`
- [`add-custom-route.md`](add-custom-route.md) — POST action endpoint pattern (`/archive`, `/restore`, `/publish`)
