# How-to: API Usage Metering

**FT175 — meterlog**

This guide explains how to implement per-user API usage metering with daily quotas,
event recording, quota checks, and per-endpoint breakdowns using NENE2.

---

## Overview

API usage metering lets you:

- **Set per-user daily quotas** — configurable limits enforced per calendar day.
- **Record usage events** — append-only log of every API call with endpoint labels.
- **Gate requests** — lightweight check endpoint before proxying to upstream services.
- **Analyse usage** — per-endpoint breakdown for dashboards and billing.

The pattern uses two tables:

- `quotas` — one row per user, stores the user's daily call limit.
- `usage_events` — append-only; one row per API call; indexed by `(user_id, day_key)`.

---

## Schema

```sql
-- Per-user quota configuration
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL DEFAULT 1000,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    CHECK (daily_limit > 0)
);

-- Append-only usage events
CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,   -- e.g. "GET /articles"
    day_key     TEXT    NOT NULL,   -- YYYY-MM-DD for efficient date grouping
    recorded_at TEXT    NOT NULL    -- ISO 8601
);

CREATE INDEX idx_usage_user_day ON usage_events (user_id, day_key);
```

Key design decisions:

- **`day_key TEXT`** — storing the pre-computed `YYYY-MM-DD` string avoids `strftime()` on every COUNT query; the composite index `(user_id, day_key)` gives O(1) daily aggregation.
- **Append-only events** — never update or delete; makes auditing and rollback straightforward.
- **Default quota** — if no `quotas` row exists for a user the system falls back to `DEFAULT_DAILY_LIMIT` (1000) so new users aren't blocked before admin configuration.

---

## Domain layer

### Quota entity

```php
final readonly class Quota
{
    public function __construct(
        public int    $id,
        public int    $userId,
        public int    $dailyLimit,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
```

### MeterRepository — core operations

```php
final readonly class MeterRepository
{
    public const int DEFAULT_DAILY_LIMIT = 1000;

    // Upsert quota (INSERT or UPDATE)
    public function upsertQuota(int $userId, int $dailyLimit, string $now): Quota;

    // Find existing quota (null → use DEFAULT_DAILY_LIMIT)
    public function findQuota(int $userId): ?Quota;

    // Record one API call
    public function record(int $userId, string $endpoint, string $now): void;

    // Count today's calls
    public function countForDay(int $userId, string $dayKey): int;

    // Per-endpoint breakdown for a day
    // @return list<array{endpoint: string, count: int}>
    public function breakdownForDay(int $userId, string $dayKey): array;

    // Full status summary (quota + usage)
    // @return array{user_id, day, daily_limit, used, remaining, allowed}
    public function statusForToday(int $userId, string $today): array;
}
```

#### Deriving `day_key` from an ISO 8601 timestamp

```php
public function record(int $userId, string $endpoint, string $now): void
{
    $dayKey = substr($now, 0, 10); // "2024-06-15T14:30:00+09:00" → "2024-06-15"
    $this->db->insert(
        'INSERT INTO usage_events (user_id, endpoint, day_key, recorded_at) VALUES (?, ?, ?, ?)',
        [$userId, $endpoint, $dayKey, $now],
    );
}
```

#### Keeping `remaining` non-negative

```php
'remaining' => max(0, $limit - $used),
```

`max(0, …)` prevents the API from returning a negative remainder if a caller manages to
exceed the quota before the gate kicks in (e.g. concurrent requests past the limit).

---

## HTTP endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/quotas` | `X-Admin-Key` | Upsert per-user quota |
| `GET` | `/quotas/{userId}` | — | Today's quota status |
| `POST` | `/usage` | `X-Machine-Key` | Record one usage event |
| `GET` | `/usage/{userId}/breakdown?date=YYYY-MM-DD` | own `X-User-Id` or admin | Per-endpoint breakdown |
| `POST` | `/usage/check` | `X-Machine-Key` | Gate check before upstream call |

### Auth pattern

Use **separate keys** for admin operations and machine-client operations to
apply the principle of least privilege:

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    $key = $request->getHeaderLine('X-Admin-Key');
    return $key !== '' && hash_equals(self::ADMIN_KEY, $key);
}

private function isMachineClient(ServerRequestInterface $request): bool
{
    $key = $request->getHeaderLine('X-Machine-Key');
    return $key !== '' && hash_equals(self::MACHINE_KEY, $key);
}
```

Always use `hash_equals()` — never `===` — for secret comparison to prevent timing attacks.

### Quota gate flow (typical call path)

```
machine client → POST /usage/check  → allowed: true  → proceed with API call
                                    → allowed: false → return 429 to end-user
machine client → POST /usage        → record the event
```

The gate check and the record step are separate so the machine client controls
which events are counted (e.g. you may not want to count failed auth attempts).

---

## Validation rules

| Field | Rule |
|-------|------|
| `user_id` | Must be a positive integer (`> 0`) |
| `daily_limit` | Must be a positive integer (`> 0`) — enforced by `CHECK` constraint and application layer |
| `endpoint` | Must be a non-empty string after trim |
| `date` | Must match `YYYY-MM-DD`; must be a valid calendar date (e.g. `2024-02-30` rejected) |

### Date validation

```php
private function validateDate(string $raw): ?string
{
    if ($raw === '') {
        return date('Y-m-d'); // default: today
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    // Re-format to catch calendar overflows (e.g. 2024-02-30 → 2024-03-01)
    if (date('Y-m-d', $ts) !== $raw) {
        return null;
    }

    return $raw;
}
```

---

## Security checklist

| Risk | Mitigation |
|------|-----------|
| Admin endpoints accessible without key | `hash_equals()` check on every admin/machine request |
| Timing attack on key comparison | `hash_equals()` — constant-time; never `===` |
| SQL injection via `endpoint` field | Parameterised queries throughout |
| Negative `remaining` in response | `max(0, $limit - $used)` |
| Invalid / calendar-overflow dates | Pattern + `strtotime()` + re-format check |
| IDOR on breakdown | Verify caller owns the `userId` or is admin; return 403 otherwise |
| `user_id` <= 0 | Reject at controller layer before hitting DB |

---

## Testing approach

1. **Happy-path functional tests** — quota create/update, usage record, check allowed/denied, breakdown.
2. **Vulnerability assessment (VULN-A through VULN-L)** — every FT that exercises auth or data boundaries includes a structured vulnerability sweep covering:
   - Missing/wrong keys → 401
   - Non-positive integers → 422
   - SQL injection in text fields → 201 (stored safely, no crash)
   - IDOR on per-user data → 403
   - Over-quota `remaining` always ≥ 0

See `tests/Meter/MeterTest.php` for the full suite (24 tests, 92 assertions).
