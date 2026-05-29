---
title: "How-to: Quota Management API"
category: infrastructure
tags: [quota, rate-limiting, windowed-counter, http-429]
difficulty: intermediate
related: [rate-limiting, sliding-window-rate-limiter, fixed-window-rate-limiter]
---

# How-to: Quota Management API

> **FT reference**: FT236 (`NENE2-FT/quotalog`) — Quota Management API
> **ATK**: FT236 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a quota management API where each user/resource pair has a configurable
rate policy (hourly or daily), usage is tracked in a separate table keyed by window
start, and a `consume` endpoint enforces the limit with `429 Too Many Requests` when
exceeded. `check` (read-only) and `consume` (mutating) are separate operations.

---

## Routes

| Method | Path                                     | Description                                        |
|--------|------------------------------------------|----------------------------------------------------|
| `PUT`  | `/quotas/{userId}/{resource}`            | Create or update a quota policy                    |
| `GET`  | `/quotas/{userId}`                       | List all quota policies for a user                 |
| `GET`  | `/quotas/{userId}/{resource}`            | Check current quota status (read-only)             |
| `POST` | `/quotas/{userId}/{resource}/consume`    | Consume one unit (returns 429 if exceeded)         |
| `POST` | `/quotas/{userId}/{resource}/reset`      | Reset usage to zero for the current window         |

---

## QuotaWindow: computing the window start

`QuotaWindow` is a backed enum with a `windowStart()` method that floors the current
timestamp to the window boundary:

```php
enum QuotaWindow: string
{
    case Hourly = 'hourly';
    case Daily  = 'daily';

    public function windowStart(string $now): string
    {
        $dt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s'),
            self::Daily  => $dt->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        };
    }
}
```

`setTime(H, 0, 0)` floors to the current hour; `setTime(0, 0, 0)` floors to midnight
UTC. The result is stored as the `window_start` key in the usage table — all requests
within the same window share the same `window_start` value.

---

## Two-table design: policies and usage

```sql
-- Quota policy: max allowed per window
CREATE TABLE IF NOT EXISTS quota_policies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     TEXT    NOT NULL,
    resource    TEXT    NOT NULL,
    window      TEXT    NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(user_id, resource)
);

-- Usage tracking: actual count per window
CREATE TABLE IF NOT EXISTS quota_usage (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      TEXT    NOT NULL,
    resource     TEXT    NOT NULL,
    window_start TEXT    NOT NULL,
    usage        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

Separating policies from usage means:
- Policies persist across windows — no need to recreate them each period.
- Usage rows are automatically partitioned by `window_start`. Old windows accumulate
  in the table; a background job can prune them.
- `UNIQUE(user_id, resource)` on policies prevents duplicate configurations.
- `UNIQUE(user_id, resource, window_start)` on usage ensures one counter per window.

---

## check vs consume

`check` is read-only — it computes remaining without any mutation:

```php
public function check(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);
    $remaining   = max(0, $policy->limitCount - $usage);

    return new QuotaStatus(..., remaining: $remaining, allowed: $remaining > 0);
}
```

`consume` checks the limit first, and only increments if allowed:

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // Quota exceeded — return status with allowed=false, do NOT increment
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

The controller maps `allowed=false` to `429 Too Many Requests`:

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` is semantically correct for quota exhaustion. Include a `Retry-After` header in
production pointing to the window reset time.

---

## Usage increment: SELECT-then-INSERT/UPDATE

Usage increment is an application-level upsert:

```php
private function incrementUsage(string $userId, string $resource, string $windowStart, string $now): void
{
    $existing = $this->executor->fetchAll(
        'SELECT id FROM quota_usage WHERE user_id = ? AND resource = ? AND window_start = ?',
        [$userId, $resource, $windowStart],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE quota_usage SET usage = usage + 1, updated_at = ? WHERE user_id = ? AND resource = ? AND window_start = ?',
            [$now, $userId, $resource, $windowStart],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO quota_usage (user_id, resource, window_start, usage, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $resource, $windowStart, $now, $now],
        );
    }
}
```

`usage = usage + 1` is an atomic DB-level increment — no read-modify-write in
application code. The `UNIQUE` constraint on `(user_id, resource, window_start)`
prevents a race condition between two concurrent first-use inserts.

---

## Policy upsert via `PUT`

`PUT /quotas/{userId}/{resource}` is idempotent — it creates or updates:

```php
$window     = QuotaWindow::tryFrom($windowRaw);
$limitCount = isset($body['limit_count']) && is_int($body['limit_count']) ? $body['limit_count'] : -1;

$errors = [];
if ($window === null) {
    $errors[] = ['field' => 'window', 'code' => 'invalid', 'message' => 'window must be one of: hourly, daily.'];
}
if ($limitCount < 1) {
    $errors[] = ['field' => 'limit_count', 'code' => 'invalid', 'message' => 'limit_count must be a positive integer.'];
}
```

`is_int()` strict check rejects JSON floats and strings. `limitCount < 1` requires at
least 1 — zero and negative values are rejected.

---

## ATK — Cracker-mindset attack test (FT236)

### ATK-01 — No authentication

**Attack**: Create a quota policy or consume on behalf of any user without credentials.

```bash
curl -s -X PUT http://localhost:8080/quotas/user-123/api-calls \
  -H 'Content-Type: application/json' \
  -d '{"window":"daily","limit_count":10}'
```

**Observed**: `200 OK` — no token required. Anyone can set or exhaust any user's quota.

**Verdict**: **EXPOSED** (by design for FT236 demo). Add authentication; gate policy
management behind an admin role, and consume behind the owning user's token.

---

### ATK-02 — SQL injection via `{resource}` path parameter

**Attack**: Embed SQL metacharacters in the resource name.

```
PUT /quotas/user-1/api'; DROP TABLE quota_policies; --
POST /quotas/user-1/" OR "1"="1/consume
```

**Observed**: The resource string is passed directly as a parameterised `?` value in
all queries — no string interpolation. The injected SQL is stored/compared as a literal
string, not executed.

**Verdict**: **BLOCKED** — parameterised queries prevent injection via path parameters.

---

### ATK-03 — Negative or zero `limit_count`

**Attack**: Set a limit of 0 or -1 to disable another user's access.

```json
{"window": "daily", "limit_count": 0}
{"window": "daily", "limit_count": -999}
```

**Observed**: `$limitCount < 1` check fires → `422 Unprocessable Entity` with a
structured error for `limit_count`.

**Verdict**: **BLOCKED** — minimum `limit_count` of 1 enforced at the application layer.

---

### ATK-04 — Invalid `window` value

**Attack**: Send an unsupported window string.

```json
{"window": "weekly", "limit_count": 100}
{"window": "minutely", "limit_count": 100}
```

**Observed**: `QuotaWindow::tryFrom('weekly')` returns `null` → `422` with structured
error for `window`.

**Verdict**: **BLOCKED** — backed enum `tryFrom()` rejects unknown window values.

---

### ATK-05 — Consume without a policy

**Attack**: Call `POST .../consume` for a user/resource with no policy configured.

```bash
curl -s -X POST http://localhost:8080/quotas/user-ghost/api-calls/consume
```

**Observed**: `findPolicy()` returns `null` → `404 Not Found` with a Problem Details
response.

**Verdict**: **BLOCKED** — no policy → no consume. The caller must configure a policy
before consuming.

---

### ATK-06 — Float `limit_count`

**Attack**: Send a float instead of an integer.

```json
{"window": "daily", "limit_count": 9.9}
```

**Observed**: `is_int(9.9)` = `false` in PHP — the float-decoded-from-JSON value
(`float` type) fails the check. `$limitCount` defaults to `-1` → the `< 1` guard
fires → `422`.

**Verdict**: **BLOCKED** — `is_int()` strict type check rejects JSON floats.

---

### ATK-07 — Extremely large `limit_count`

**Attack**: Set a limit_count of `PHP_INT_MAX` or `9999999999`.

```json
{"window": "daily", "limit_count": 9223372036854775807}
```

**Observed**: `is_int()` passes (PHP represents this as an `int`); `< 1` check passes.
The value is stored and used in comparisons without issue. No upper bound exists.

**Verdict**: **EXPOSED** — no maximum `limit_count` enforced. A very large limit is
effectively the same as "no limit". Add:
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — Race condition on concurrent consume at limit

**Attack**: Send two simultaneous `POST .../consume` requests when `usage == limit - 1`.

**Observed**: Both requests read `usage = limit - 1` before either increment runs. Both
see `usage < limitCount` → both call `incrementUsage()`. Both succeed — usage ends at
`limit + 1`, both responses return `allowed: true`.

**Verdict**: **EXPOSED** — the check-then-increment pattern is not atomic. Fix with a
transaction:
```sql
BEGIN;
SELECT usage FROM quota_usage WHERE ... FOR UPDATE;
-- check < limit
UPDATE quota_usage SET usage = usage + 1 WHERE ...;
COMMIT;
```
Or use `UPDATE ... SET usage = CASE WHEN usage < ? THEN usage + 1 ELSE usage END RETURNING usage` on PostgreSQL.

---

### ATK-09 — Unknown or arbitrary `{resource}` name

**Attack**: Use a resource name that was never intended.

```
PUT /quotas/user-1/../../../../etc/passwd
PUT /quotas/user-1/system::admin
POST /quotas/user-1/; DROP TABLE quota_usage;--/consume
```

**Observed**: Path traversal (`../`) is URL-decoded before routing; the router sees
them as multi-segment paths and does not match the `{resource}` route. Special
characters stored as literal strings via parameterised queries (see ATK-02).

**Verdict**: **BLOCKED** in effect — router rejects path traversal, SQL is safe.
Consider adding a resource name allowlist or format check if resource names should
be restricted to known values.

---

### ATK-10 — Reset another user's quota

**Attack**: Reset a different user's quota counter to bypass their throttling.

```bash
curl -s -X POST http://localhost:8080/quotas/target-user/api-calls/reset
```

**Observed**: `200 OK` — no ownership check. Any caller can reset any user's quota
usage, immediately re-enabling their access.

**Verdict**: **EXPOSED** — same root as ATK-01. Gate `reset` behind an admin role.

---

### ATK-11 — Unbounded `{userId}` and `{resource}` length

**Attack**: Send extremely long path segment values.

```
PUT /quotas/<10000 chars>/<5000 chars>
```

**Observed**: Long strings are accepted and stored in `TEXT` columns without limit.
Index performance on very long keys degrades.

**Verdict**: **EXPOSED** — add a length guard:
```php
if (strlen($userId) > 255 || strlen($resource) > 255) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

---

### ATK-12 — `window_start` manipulation via clock drift

**Attack**: If the caller can influence `$now`, they can shift the window start to
artificially extend or restart a window.

**Observed**: `$now` is computed inside the controller via `new \DateTimeImmutable()` —
it is not user-supplied. The caller cannot influence the window calculation.

**Verdict**: **BLOCKED** — server clock is the only time source. For distributed
systems with multiple nodes, ensure all nodes use UTC and are NTP-synchronized.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | No authentication | EXPOSED |
| ATK-02 | SQL injection via resource path param | BLOCKED |
| ATK-03 | Negative/zero limit_count | BLOCKED |
| ATK-04 | Invalid window value | BLOCKED |
| ATK-05 | Consume without policy | BLOCKED |
| ATK-06 | Float limit_count | BLOCKED |
| ATK-07 | Extremely large limit_count | EXPOSED |
| ATK-08 | Concurrent consume race condition | EXPOSED |
| ATK-09 | Arbitrary resource name | BLOCKED |
| ATK-10 | Reset another user's quota | EXPOSED |
| ATK-11 | Unbounded userId/resource length | EXPOSED |
| ATK-12 | Window start manipulation | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01 / ATK-10** — Add authentication and authorisation
2. **ATK-08** — Wrap consume in a transaction (atomic check-then-increment)
3. **ATK-07** — Add an upper bound on `limit_count`
4. **ATK-11** — Add length limits on path parameter values

---

## Related howtos

- [`rate-limiting.md`](rate-limiting.md) — middleware-level rate limiting
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — sliding window counter
- [`api-usage-metering.md`](api-usage-metering.md) — per-API-key usage tracking
- [`credit-ledger.md`](credit-ledger.md) — credit/debit model for quota-like systems
