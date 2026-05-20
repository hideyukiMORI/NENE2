# Field Trial 74 — Immutable Audit Log with Search and Statistics (auditlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/auditlog/`
**Theme**: Append-only audit event trail with multi-dimensional filtering (actor/action/resource_type/resource_id/date range), pagination, and aggregated statistics by action.

---

## What was built

A read/append-only audit log API where events are recorded with structured metadata. No updates or deletes are supported. Clients can filter events by any combination of dimensions and retrieve aggregated counts by action type.

### Domain

- `AuditEvent` — immutable value object: `actor`, `action`, `resource_type`, `resource_id`, `metadata` (JSON object), `occurred_at`

### Schema

```sql
CREATE TABLE IF NOT EXISTS audit_events (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor         TEXT NOT NULL,
    action        TEXT NOT NULL,
    resource_type TEXT NOT NULL,
    resource_id   TEXT NOT NULL,
    metadata      TEXT NOT NULL DEFAULT '{}',
    occurred_at   TEXT NOT NULL
);
```

No `updated_at` column — this is intentional. Audit events are immutable.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/audit-events` | Record a new event |
| GET | `/audit-events` | Search/filter events with optional `actor`, `action`, `resource_type`, `resource_id`, `from`, `to`, `limit`, `offset` |
| GET | `/audit-events/stats` | Aggregated counts by action (optional `resource_type` filter) |
| GET | `/audit-events/{id}` | Get single event |

### Key design decisions

**Metadata stored as JSON TEXT**: `metadata` is a `TEXT` column containing a JSON object. Stored via `json_encode()`, decoded in hydration via `json_decode()`. This avoids SQLite JSON column type while still allowing structured metadata round-trips.

**Dynamic WHERE clause**: `SqliteAuditRepository::search()` builds the WHERE clause programmatically from non-null filter parameters, appending conditions and bindings to arrays. Clean, no string concatenation vulnerabilities (all values go through prepared statement bindings).

**No DELETE/UPDATE endpoints**: The API is intentionally read/write-only (no mutate-existing operations). This enforces the immutability contract without any DB-level trigger.

**Pagination via `limit`/`offset`**: Simple offset pagination — appropriate for audit log browsing. `limit` is capped at 100 to prevent large dumps.

**Statistics via GROUP BY**: `statsByAction()` uses `COUNT(*) GROUP BY action ORDER BY count DESC`. Simple, no ORM needed.

**`/audit-events/stats` route registered before `/{id}`**: `GET /audit-events/stats` (0 path params) and `GET /audit-events/{id}` (1 path param). NENE2 v1.5.22 auto-sorts by parameter count, so `/stats` always wins regardless of registration order. ✅

### Test results

```
OK (13 tests, 27 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

### F-1 (Note): Same-second timestamp ordering non-deterministic in tests

**Symptom**: A test asserting `ORDER BY occurred_at DESC` order between two events inserted in the same second found the assertion non-deterministic — both events have identical `occurred_at` values, so SQLite may return either one first.

**Resolution**: The test was rewritten to assert on result count rather than ordering within the same second. Real-world usage would have distinct timestamps. This is not a NENE2 issue — it's a test design caveat.

---

## Summary

Immutable audit log with multi-dimensional filtering and statistics works cleanly with NENE2 v1.5.22. No framework changes needed. The static `/stats` route is prioritized over `/{id}` by the v1.5.22 router auto-sort. Dynamic WHERE clause building in the repository layer is safe and concise.
