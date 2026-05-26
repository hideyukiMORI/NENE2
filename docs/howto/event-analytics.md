# How-to: Event Analytics API

> **FT reference**: FT51 (`NENE2-FT/statslog`) — Event Analytics API with JSON property
> filtering and aggregation queries

Demonstrates an event tracking API that stores analytics events with arbitrary JSON
properties and exposes aggregation endpoints for per-day counts, per-type breakdowns,
and unique user metrics. Key patterns: `json_extract()` property filtering, `strftime()`
date bucketing, static routes before parameterised routes, and string-typed user IDs.

---

## Routes

| Method | Path                      | Description                                         |
|--------|---------------------------|-----------------------------------------------------|
| `POST` | `/events`                 | Record an event                                     |
| `GET`  | `/events`                 | List events (paginated)                             |
| `GET`  | `/events/by-property`     | Filter by JSON property key/value                   |
| `GET`  | `/events/{id}`            | Get a single event                                  |
| `GET`  | `/stats/per-day`          | Event count per calendar day (`?from=&to=`)         |
| `GET`  | `/stats/per-type`         | Event count per event type (`?from=&to=`)           |
| `GET`  | `/stats/unique-users`     | Unique user count per day (`?from=&to=`)            |

---

## Recording events

```php
// POST /events
$body = [
    'event_type'  => 'page_view',          // required, non-empty string
    'user_id'     => 'usr_abc123',          // required, string (UUID or opaque ID)
    'session_id'  => 'sess_xyz789',         // optional
    'properties'  => ['path' => '/pricing', 'referrer' => 'google'],  // optional object
    'occurred_at' => '2026-05-27T09:00:00Z', // optional, ISO 8601 (defaults to server time)
];
```

`properties` is stored as a JSON string. On output it is decoded back to an object:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

When `occurred_at` is omitted, the server fills it with the current UTC time:

```php
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

## Route ordering: static before parameterised

The router matches routes in registration order. A static path like `/events/by-property`
must be registered **before** the parameterised `/events/{id}`, otherwise the segment
`by-property` would be captured as `{id}`:

```php
public function register(Router $router): void
{
    $router->post('/events', $this->createEvent(...));
    $router->get('/events', $this->listEvents(...));

    // ✓ Static route first — or "by-property" is swallowed by {id}
    $router->get('/events/by-property', $this->eventsByProperty(...));
    $router->get('/events/{id}', $this->showEvent(...));

    $router->get('/stats/per-day', $this->statsPerDay(...));
    $router->get('/stats/per-type', $this->statsPerType(...));
    $router->get('/stats/unique-users', $this->statsUniqueUsers(...));
}
```

**Rule**: always register any concrete path segments before wildcard segments at the
same depth level.

---

## JSON property filtering with `json_extract()`

SQLite (≥ 3.38) and MySQL support `json_extract()` to query inside stored JSON columns.
The key is passed as a parameterised JSONPath expression:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

The JSONPath prefix `$.` is appended in PHP, so `key = "path"` becomes
`json_extract(properties, '$.path')`. Because both arguments are parameterised,
there is no SQL injection risk even if `$propertyKey` contains special characters.

> **Depth limit**: `$.path` accesses the top level. For nested access
> (`$.browser.name`) the caller passes `browser.name` as the key. Deep paths can
> be surprising — document the supported key shapes in your OpenAPI spec.

---

## Date aggregation with `strftime()`

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`strftime('%Y-%m-%d', ...)` truncates an ISO 8601 datetime string to its date component.
This works in SQLite when `occurred_at` is stored as UTC (e.g. `2026-05-27T09:00:00Z`).
Times stored with non-UTC offsets will be bucketed by their raw string, not converted to
local time — normalise to UTC at write time if day-boundary semantics matter.

---

## Counting unique users per day

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`COUNT(DISTINCT user_id)` returns the number of distinct `user_id` values that appear
in each bucket. This is an approximation of Daily Active Users (DAU) when `user_id` is
a stable external identifier (UUID, hashed device ID, etc.).

---

## String-typed user_id

`user_id` is stored as `TEXT NOT NULL`, not as an integer foreign key. This design
accommodates:

- UUID (`usr_01HQ...`)
- Opaque string identifiers from an identity provider
- Anonymous session tokens before account creation

Because the field is free-form text, the analytics layer does not couple to the user
data model. There is no `REFERENCES users(id)` foreign key — events can be recorded
before or after a user account is created.

---

## Default date range fallback

Aggregate endpoints accept `?from=` and `?to=` query parameters. When omitted, defaults
span a very wide range:

```php
$from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
$to   = QueryStringParser::string($request, 'to')   ?? '2100-01-01T00:00:00Z';
```

This is convenient for demo use but could be expensive on a large production dataset.
In production, require explicit date ranges and cap the maximum span (see
[`shift-management.md`](shift-management.md) for a capping pattern).

---

## Schema and indexes

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_events_type     ON events(event_type);
CREATE INDEX idx_events_occurred ON events(occurred_at);
CREATE INDEX idx_events_user     ON events(user_id);
```

Three indexes cover the three main query shapes:
- `idx_events_occurred` — date-range aggregations (`WHERE occurred_at >= ? AND < ?`)
- `idx_events_type` — type filter (`WHERE event_type = ?`)
- `idx_events_user` — user history lookup (`WHERE user_id = ?`)

`json_extract()` queries on `properties` are not index-supported in SQLite without a
generated column. For high-volume property filtering, consider adding a generated column:

```sql
ALTER TABLE events ADD COLUMN prop_path TEXT GENERATED ALWAYS AS (json_extract(properties, '$.path')) STORED;
CREATE INDEX idx_events_prop_path ON events(prop_path);
```

---

## Properties encoding in PHP

The `properties` field accepts any JSON object from the caller and stores it as a string:

```php
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
```

`is_array($body['properties'])` rejects JSON scalars and arrays (which would decode
to a PHP array but are not an object). Storing `JSON_THROW_ON_ERROR` ensures encode
failures surface as exceptions rather than silent `false`.

On serialisation, properties are decoded back to a PHP array and embedded as a nested
object in the response:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## Related howtos

- [`admin-report-aggregation.md`](admin-report-aggregation.md) — SQL aggregation patterns for admin reports
- [`shift-management.md`](shift-management.md) — date range capping, aggregate queries
- [`pagination.md`](pagination.md) — `PaginationQueryParser` and `PaginationResponse`
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601 round-trip validation for `occurred_at`
