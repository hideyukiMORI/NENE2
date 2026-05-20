# Field Trial 73 — Quota / Rate Limiting with Time Windows (quotalog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/quotalog/`
**Theme**: Per-user per-resource quota tracking with hourly/daily time-window awareness, atomic consume, 429 on exhaustion, and manual reset.

---

## What was built

A quota management API where administrators define per-user per-resource limits with time windows. Clients call a "consume" endpoint to atomically check and decrement quota; exhausted quotas return HTTP 429.

### Domain

- `QuotaWindow` — string-backed enum: `hourly`, `daily`. Each variant implements `windowStart(string $now)` to compute the floor timestamp for a given moment (e.g. 14:37 → 14:00 for hourly).
- `QuotaPolicy` — the configured limit: `user_id`, `resource`, `window`, `limit_count`
- `QuotaStatus` — the runtime snapshot: `usage`, `remaining`, `allowed`, `window_start`

### Schema

```sql
CREATE TABLE IF NOT EXISTS quota_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    resource TEXT NOT NULL,
    window TEXT NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(user_id, resource)
);

CREATE TABLE IF NOT EXISTS quota_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    resource TEXT NOT NULL,
    window_start TEXT NOT NULL,
    usage INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| PUT | `/quotas/{userId}/{resource}` | Create or update quota policy |
| GET | `/quotas/{userId}` | List all policies for a user |
| GET | `/quotas/{userId}/{resource}` | Check current quota status |
| POST | `/quotas/{userId}/{resource}/consume` | Consume one unit (429 if exhausted) |
| POST | `/quotas/{userId}/{resource}/reset` | Reset usage to 0 for current window |

### Key design decisions

**Window flooring in the enum**: `QuotaWindow::windowStart(now)` computes the window floor purely in PHP — no SQL date functions needed. Hourly: `setTime(H, 0, 0)`. Daily: `setTime(0, 0, 0)`. The result is stored as a TEXT key in `quota_usage`.

**Natural window rollover**: When a new window begins, `getUsage()` finds no row for the new `window_start`, returns 0, and `incrementUsage()` INSERTs a fresh row. Old rows are retained for historical audit — no cleanup needed for correctness.

**Atomic consume without transactions**: The check-then-increment is two SQL statements. Since SQLite tests run single-threaded, this is safe in this FT. In a concurrent setting, a proper transaction or `INSERT OR REPLACE` with a check constraint would be needed.

**HTTP 429 for exhausted quota**: `consume()` returns HTTP 429 with the same `QuotaStatus` body as a 200, so clients can inspect `remaining` and `window_start` to know when to retry.

**User isolation via path prefix**: `/quotas/{userId}/...` scopes all operations to one user. Two users with the same resource name have independent quota records.

**`QuotaStatus` as a value-object response**: Both `check()` and `consume()` return a `QuotaStatus` value object that serializes to the same JSON shape. This avoids duplicating the response assembly logic.

### Test results

```
OK (14 tests, 33 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.22 handled all quota patterns cleanly:

- Two path parameters (`{userId}/{resource}`): ✅
- Action sub-paths (`/consume`, `/reset`): ✅
- HTTP 429 status code via `$this->json->create($body, 429)`: ✅
- Enum with domain method (`QuotaWindow::windowStart()`): ✅ (pure PHP, no framework involvement)

---

## Summary

Quota tracking with time-window awareness works cleanly with NENE2 v1.5.22. The window flooring logic lives in a PHP enum method, keeping the repository stateless. No framework changes needed. HTTP 429 is returned correctly by `JsonResponseFactory::create()` with a custom status code.
