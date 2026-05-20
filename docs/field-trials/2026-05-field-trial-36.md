# Field Trial 36 — Analytics Event Tracking API (statslog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/statslog/`
**NENE2 version**: 1.5.17
**Theme**: JSON properties stored as TEXT, `json_extract()` filtering, `strftime()` date aggregation, `GROUP BY` computed date expressions, `COUNT(DISTINCT …)` for unique users

## Overview

Built an analytics event tracking API where each event stores arbitrary JSON properties as a TEXT column. Used SQLite's `json_extract()` function to filter events by property value without deserializing at the PHP layer. Used `strftime('%Y-%m-%d', occurred_at)` for date-level grouping in aggregation queries.

## Endpoints Implemented

- `POST /events` — record event (event_type, user_id, session_id?, properties?, occurred_at?)
- `GET /events` — list all events, paginated, most recent first
- `GET /events/{id}` — show single event
- `GET /events/by-property?key=K&value=V` — filter events by JSON property value via `json_extract()`
- `GET /stats/per-day?from=…&to=…` — event count per day via `strftime('%Y-%m-%d', …)`
- `GET /stats/per-type?from=…&to=…` — event count grouped by type
- `GET /stats/unique-users?from=…&to=…` — `COUNT(DISTINCT user_id)` per day

## Test Results

20 tests, 49 assertions — all pass with 1 fix (route ordering).

---

## Frictions Found

### Friction 1 — Route ordering: static `/events/by-property` matched by `/events/{id}` [LOW]

**Symptom**: `GET /events/by-property?key=page&value=/home` returned 404 with body indicating `Event #0 not found` (after `(int)"by-property"` evaluated to `0`).

**Root cause**: `/events/by-property` was registered after `/events/{id}`. The router matched `by-property` as the `{id}` parameter value. `(int)"by-property"` evaluated to `0`, the repository threw `EventNotFoundException("Event #0 not found.")`, which the handler correctly mapped to 404.

**Fix**: Move static routes before parameterized routes:

```php
// WRONG — by-property matches {id} = "by-property"
$router->get('/events/{id}', $this->showEvent(...));
$router->get('/events/by-property', $this->eventsByProperty(...));

// CORRECT — static first
$router->get('/events/by-property', $this->eventsByProperty(...));
$router->get('/events/{id}', $this->showEvent(...));
```

**Notable aspect**: This friction is subtle because `/events/by-property` contains only alphabetic characters — it doesn't look like an ID. However, the router still matches it as `{id} = "by-property"`. The rule is absolute: any static route segment that shares a URL prefix with a parameterized route must be registered first.

**NENE2 impact**: The howto for routing (if one exists) should emphasize this rule explicitly with an example involving non-numeric static segments. Currently documented in FT30 projtrack and FT35 softlog — this is the third occurrence with a different flavour (property-filter endpoint that naturally has a query string, not an ID).

**Priority**: Low — documented pattern, correct behaviour.

---

## Patterns Validated

### json_extract() for property filtering

Filtering by a JSON property value works cleanly without deserializing at the PHP layer:

```sql
SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?
-- bound as: ['$.page', '/home', 50, 0]
```

The path argument (`$.key`) is constructed from user input with a `$.` prefix. No escaping needed beyond PDO binding.

### strftime() date aggregation

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

ISO 8601 strings (`2026-05-01T10:00:00Z`) compare lexicographically — `>=` / `<` for date ranges work correctly. The `to` bound is exclusive (uses `<` not `<=`), matching the typical half-open interval `[from, to)`.

### COUNT(DISTINCT …) for unique users

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

Works with PDO binding without any special treatment — `COUNT(DISTINCT …)` is not a HAVING clause, so no `CAST()` workaround needed (unlike the FT32 HAVING bug).

### Properties deserialization in PHP

Properties are stored as TEXT (`'{}'` default), returned deserialized in the response:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

`json_decode` returns `[]` (empty array) for `'{}'` — serializes back to `{}` in JSON. No friction.

---

## NENE2 Changes Required

| # | Change | Priority |
|---|--------|----------|
| 1 | Document static-before-parameterized rule with non-numeric segment example in routing howto | Low |

No version bump needed — the route ordering rule is correct existing behaviour; this is documentation only. Will bundle with next substantive change.
