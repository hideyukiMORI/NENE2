---
title: "How-to: Event Analytics API"
category: infrastructure
tags: [analytics, event-tracking, aggregation, json-extract, statistics]
difficulty: intermediate
related: [event-analytics, api-usage-metering, aggregate-reporting]
---

# How-to: Event Analytics API

> **FT reference**: FT243 (`NENE2-FT/statslog`) — Event Analytics API
> **VULN**: FT243 — vulnerability assessment (V-01 through V-10)

Demonstrates an event ingestion and aggregation API where raw analytics events are
recorded with a JSON `properties` blob, queried with SQLite `json_extract()`, and
aggregated into per-day / per-type / unique-user statistics. Includes a full
vulnerability assessment of the unauthenticated design.

---

## Routes

| Method | Path                   | Description                                          |
|--------|------------------------|------------------------------------------------------|
| `POST` | `/events`              | Record an analytics event                            |
| `GET`  | `/events`              | List events (paginated)                              |
| `GET`  | `/events/by-property`  | Filter events by JSON property key+value             |
| `GET`  | `/events/{id}`         | Get a single event                                   |
| `GET`  | `/stats/per-day`       | Event count grouped by day                           |
| `GET`  | `/stats/per-type`      | Event count grouped by event type                    |
| `GET`  | `/stats/unique-users`  | Unique user count grouped by day                     |

> **Static routes before parameterized**: `/events/by-property` is registered before
> `/events/{id}` so the router dispatches the literal path correctly.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events(user_id);
```

`properties` is stored as a JSON string (`TEXT`). SQLite's `json_extract()` allows
querying into the blob at read time without a separate schema. Three indexes cover the
most common access patterns: by type, by time range, and by user.

---

## Event creation: JSON properties blob

`POST /events` accepts a flexible `properties` object alongside required `event_type`
and `user_id`:

```php
$eventType  = trim((string) $body['event_type']);
$userId     = trim((string) $body['user_id']);
$sessionId  = isset($body['session_id']) && is_string($body['session_id']) ? $body['session_id'] : '';
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

- `properties` must be a JSON object (`is_array()` check) — scalar values fall back to `'{}'`.
- `occurred_at` is caller-supplied or defaults to now — no server-side enforcement that
  it falls within a valid range.
- `JSON_THROW_ON_ERROR` ensures malformed intermediate JSON throws immediately rather
  than producing `false`.

Deserialization at read time:
```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## JSON property search with `json_extract()`

`GET /events/by-property?key=page&value=/home` filters events by a property key/value:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

`json_extract(properties, '$.page')` extracts the `page` field from the JSON blob.
The path `'$.' . $propertyKey` is constructed by concatenation, **not** parameterized
as the path itself — SQLite's `json_extract()` accepts only a literal path string, not
a bound parameter for the path expression. The key comes from a query string but is
not further validated (see V-05).

`= ?` compares the extracted value to the provided `$propertyValue` as a parameterized
binding — SQL injection via the value is blocked. The path concatenation is the
boundary to audit.

---

## Aggregation queries

### Per-day event count

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`strftime('%Y-%m-%d', occurred_at)` truncates the timestamp to a date. `GROUP BY`
on the same expression groups all events in the same day together. Both `$from` and
`$to` are parameterized — no string concatenation into the SQL.

### Per-type event count

```php
$rows = $this->executor->fetchAll(
    'SELECT event_type, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY event_type
     ORDER BY count DESC',
    [$from, $to],
);
```

`ORDER BY count DESC` shows the most-frequent event types first.

### Unique users per day

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`COUNT(DISTINCT user_id)` counts each `user_id` only once per day.

### Date range defaults

```php
private function parseDateRange(ServerRequestInterface $request): array
{
    $from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
    $to   = QueryStringParser::string($request, 'to') ?? '2100-01-01T00:00:00Z';

    return [$from, $to];
}
```

Wide defaults (`2000-01-01` to `2100-01-01`) ensure stats without a date range include
all events. In production, cap the default range to a reasonable window (e.g., last 30
days) to avoid full-table scans on large datasets.

---

## VULN — Vulnerability assessment (FT243)

### V-01 — No authentication: anyone can record events

**Risk**: Any caller can submit events with arbitrary `event_type` and `user_id`. There
is no API key, session, or token check.

**Impact**: An attacker can pollute the analytics dataset with millions of fake events,
skew statistics, and impersonate any user ID.

**Verdict**: **EXPOSED** — add API key or JWT authentication for the write endpoint.
Read-only stats may remain public, but ingestion must be authenticated.

---

### V-02 — No authorization on stats: stats are world-readable

**Risk**: `GET /stats/per-day`, `/stats/per-type`, `/stats/unique-users` return
aggregated data without any authentication.

**Impact**: Competitors or crawlers can monitor product usage trends, daily active users,
and feature adoption.

**Verdict**: **EXPOSED** — restrict stats endpoints to authenticated roles (admin,
analytics viewer). If stats are intentionally public, document this as a design decision.

---

### V-03 — `user_id` is user-supplied: no verification of identity

**Risk**: `user_id` is taken directly from the request body without any proof that the
caller owns that identity.

```json
{"event_type": "login", "user_id": "alice", "occurred_at": "2026-01-01T00:00:00Z"}
```

**Impact**: An attacker can fabricate activity for any user ID, manipulating per-user
statistics and unique-user counts.

**Verdict**: **EXPOSED** — for authenticated contexts, derive `user_id` from the
verified identity in the token/session, never from the request body.

---

### V-04 — `occurred_at` is user-supplied: backdating and future-dating events

**Risk**: The `occurred_at` field is accepted from the caller without range validation.

```json
{"event_type": "purchase", "user_id": "alice", "occurred_at": "2020-01-01T00:00:00Z"}
```

**Impact**: Attackers can insert events into any historical time slot (backdate) or
far in the future, distorting time-series statistics.

**Verdict**: **EXPOSED** — validate that `occurred_at` falls within an acceptable
window (e.g., last 24 hours to +5 minutes) and reject out-of-range timestamps.

---

### V-05 — `json_extract()` path concatenation: JSON path injection

**Risk**: The property key is concatenated directly into the JSON path expression:
`'$.' . $propertyKey`. There is no validation that `$propertyKey` is a safe identifier.

**Attack**:
```
GET /events/by-property?key=x%22%5D+OR+1%3D1+--&value=y
```
Becomes: `json_extract(properties, '$.x"] OR 1=1 --')` — SQLite interprets the path
argument as a string literal passed to `json_extract`, not as SQL. The path is not
executed as SQL — it is handled by SQLite's JSON functions as a string. Invalid paths
return `NULL`, so the query returns no rows rather than all rows.

**Observed**: `json_extract()` treats the entire second argument as a path expression.
Malformed paths (`$.x"] OR 1=1 --`) return `NULL` for every row — no SQL injection.
However, the behaviour depends on SQLite's JSON implementation — a defense-in-depth
approach would validate `$propertyKey` with `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)`.

**Verdict**: **PARTIALLY BLOCKED** — SQLite's `json_extract()` sandboxes the path
argument. Add explicit key validation (`[a-zA-Z_][a-zA-Z0-9_]*`) for defense in depth.

---

### V-06 — Unbounded event_type: no allowlist

**Risk**: `event_type` accepts any non-empty string. Very long strings or high-cardinality
types inflate the `countPerType` result set.

```json
{"event_type": "aaaa....(10000 chars)", "user_id": "x"}
```

**Impact**: Unbounded cardinality in `GROUP BY event_type` can cause memory pressure.
Storage bloat from very long strings.

**Verdict**: **EXPOSED** — add a max-length check (e.g., 100 characters) and optionally
an event-type allowlist or length limit.

---

### V-07 — SQL injection via `from`/`to` date parameters

**Attack**: Pass SQL metacharacters in the date range.

```
GET /stats/per-day?from=2000-01-01%27+OR+%271%27%3D%271&to=2100-01-01
```

**Observed**: Both `$from` and `$to` are bound as parameterized values (`?` placeholders).
The SQL engine treats them as literal strings, not SQL fragments.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection via date parameters.

---

### V-08 — Properties size: no limit on JSON blob

**Risk**: `properties` is stored as `TEXT` with no size validation. An attacker can
submit multi-megabyte JSON objects.

```json
{"event_type": "x", "user_id": "y", "properties": {"data": "AAAA....(1MB)"}}
```

**Impact**: Each large event consumes significant storage. Bulk insertion of large events
can exhaust disk space.

**Verdict**: **EXPOSED** — add a size check on the raw `properties` value
(e.g., `strlen($raw) > 65535 → 422`). Rely on request-size middleware as the outer limit.

---

### V-09 — Event flood: no rate limiting on POST /events

**Risk**: There is no rate limiting on the ingestion endpoint.

**Impact**: A single client can submit millions of events per second, overwhelming the
database and storage.

**Verdict**: **EXPOSED** — apply `ThrottleMiddleware` or per-IP / per-API-key rate
limiting on the write endpoint.

---

### V-10 — Stats exposure: `COUNT(DISTINCT user_id)` leaks user count

**Risk**: `GET /stats/unique-users` returns the count of distinct user IDs per day.

**Impact**: Without authentication, this leaks daily active user counts — a sensitive
business metric.

**Verdict**: **EXPOSED** (same root as V-02). Restrict or authenticate stats endpoints.

---

## VULN summary

| # | Vulnerability | Verdict |
|---|---------------|---------|
| V-01 | No authentication on write endpoint | EXPOSED |
| V-02 | Stats endpoints world-readable | EXPOSED |
| V-03 | `user_id` not verified (identity spoofing) | EXPOSED |
| V-04 | `occurred_at` user-supplied (backdate/future-date) | EXPOSED |
| V-05 | `json_extract()` path concatenation | PARTIALLY BLOCKED |
| V-06 | `event_type` no allowlist / length limit | EXPOSED |
| V-07 | SQL injection via date range parameters | BLOCKED |
| V-08 | No size limit on `properties` JSON blob | EXPOSED |
| V-09 | No rate limiting on POST /events | EXPOSED |
| V-10 | Unique-user count leaks DAU metrics | EXPOSED |

**Critical fixes before production**:
1. **V-01 / V-02 / V-10** — Add authentication (API key or JWT) to write and stats endpoints
2. **V-03** — Derive `user_id` from verified identity, not request body
3. **V-04** — Validate `occurred_at` falls within an acceptable time window
4. **V-05** — Add `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)` validation
5. **V-06** — Add `event_type` max-length check (e.g., 100 chars)
6. **V-08** — Add `properties` size limit (e.g., 64 KB)
7. **V-09** — Apply rate limiting on POST /events

---

## Related howtos

- [`event-sourcing.md`](event-sourcing.md) — immutable event log pattern
- [`api-usage-metering.md`](api-usage-metering.md) — metered API with quota enforcement
- [`quota-management.md`](quota-management.md) — per-resource quota with QuotaWindow
- [`cursor-pagination.md`](cursor-pagination.md) — efficient pagination for high-volume event feeds
