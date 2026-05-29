---
title: "How-to: Fixed-window Rate Limiter"
category: security
tags: [rate-limiting, fixed-window, sqlite, throttle]
difficulty: intermediate
related: [sliding-window-rate-limiter, add-rate-limiting, rate-limiting]
ft: FT251
---

# How-to: Fixed-window Rate Limiter

> **FT reference**: FT251 (`NENE2-FT/ratelimitlog`) — Fixed-window rate limiting with SQLite upsert

Demonstrates a fixed-window rate limiter stored in SQLite. Each `(key, window_start)` pair
accumulates a request count. When the count exceeds the configured limit the request is
rejected with `429 Too Many Requests` and a `Retry-After` header.

---

## Routes

| Method | Path      | Description                                     |
|--------|-----------|-------------------------------------------------|
| `GET`  | `/ping`   | Rate-limited endpoint (reads `X-Client-Key`)    |
| `GET`  | `/status` | Read-only counter for a key (`?key=`)           |

---

## Schema: composite primary key as the rate limit counter store

```sql
CREATE TABLE IF NOT EXISTS rate_limit_windows (
    key          TEXT    NOT NULL,
    window_start TEXT    NOT NULL, -- ISO 8601 timestamp truncated to window boundary
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, window_start)
);

CREATE INDEX IF NOT EXISTS idx_rl_key_window ON rate_limit_windows(key, window_start);
```

`PRIMARY KEY(key, window_start)` uniquely identifies a counter for each `(client, window)` pair.
The index makes the upsert lookup fast. A separate `api_calls` log table records each
successful request for audit purposes.

---

## Upsert pattern: `INSERT … ON CONFLICT DO UPDATE`

```php
$this->executor->execute(
    'INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
     ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1',
    [$key, $windowStart],
);
```

The first request for a `(key, windowStart)` pair inserts `count = 1`. Subsequent requests
within the same window increment atomically via `DO UPDATE SET count = count + 1`.
No `SELECT` before the `INSERT` is needed — the upsert is atomic in SQLite.

After the upsert, the counter is read to detect if the limit was exceeded:

```php
$row   = $this->executor->fetchOne(
    'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
    [$key, $windowStart],
);
$count = (int) ($row['count'] ?? 0);

if ($count > $this->limit) {
    $retryAfter = (int) (strtotime($windowEnd) - strtotime($now));
    throw new RateLimitExceededException($key, $this->limit, $this->windowSeconds, max(0, $retryAfter));
}
```

The check fires **after** the increment. This means the (limit+1)th request is counted
before being rejected — the counter reaches `limit + 1` for over-limit requests.
This is intentional: the count accurately reflects total attempts, not just allowed ones.

---

## Window truncation: fixed boundary

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));

    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` is the offset within the current window. Subtracting it gives the
window start timestamp. For a 60-second window at `2026-01-01T00:00:45Z`:

```
ts     = 1751328045  (Unix timestamp)
ts % 60 = 45
bucket = 1751328045 - 45 = 1751328000  → 2026-01-01T00:00:00Z
```

All requests from `:00` to `:59` share the same `window_start = 2026-01-01T00:00:00Z`.
At `:60` a new window starts and the counter resets.

**Fixed vs sliding window trade-off**:

| Property | Fixed window | Sliding window |
|---|---|---|
| Implementation | Single upsert per request | Multiple reads/writes across buckets |
| Memory | 1 row per (key, window) | N rows per key (sub-buckets) |
| Burst at boundary | Yes — 2× limit possible at window edge | No — smoothly limits across time |
| Common use | Simple APIs, internal tools | Public-facing APIs, strict fairness |

---

## `429 Too Many Requests` with `Retry-After`

```php
final class RateLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $key,
        public readonly int    $limit,
        public readonly int    $windowSeconds,
        public readonly int    $retryAfter,
    ) {
        parent::__construct("Rate limit of {$limit} requests per {$windowSeconds}s exceeded for key '{$key}'.");
    }
}
```

The exception carries `retryAfter` (seconds until the current window expires). The handler
maps this to a `429` Problem Details response with a `Retry-After` header:

```php
public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
{
    assert($exception instanceof RateLimitExceededException);

    $response = $this->probs->create(
        request: $request,
        type: 'rate-limit-exceeded',
        title: 'Too Many Requests',
        status: 429,
        detail: $exception->getMessage(),
    );

    return $response->withHeader('Retry-After', (string) $exception->retryAfter);
}
```

`Retry-After` is the number of seconds the client should wait before retrying.
It is computed as `windowEnd - now`, clamped to `>= 0`.

---

## Per-client key via `X-Client-Key` header

```php
$key = $request->getHeaderLine('X-Client-Key') ?: '127.0.0.1';
```

Each client is identified by its `X-Client-Key` header. Missing header falls back to
`'127.0.0.1'` — all unauthenticated clients share one counter. In production:

- Use a verified user ID or API key extracted from an authenticated session — not a
  header the client can forge.
- Use `$_SERVER['REMOTE_ADDR']` (after proxy stripping) for IP-based limiting.
- Never use `X-Forwarded-For` directly — a client can spoof it to bypass limits.

---

## Read-only status endpoint

```php
public function currentCount(string $key, string $now): int
{
    $windowStart = $this->truncateToWindow($now);
    $row = $this->executor->fetchOne(
        'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
        [$key, $windowStart],
    );

    return (int) ($row['count'] ?? 0);
}
```

`GET /status?key=xxx` returns the current counter without incrementing it.
This is used for monitoring dashboards or client-side backoff logic.

---

## Window expiry pruning

```php
public function pruneExpired(string $now): int
{
    $cutoff = $this->subtractSeconds($now, $this->windowSeconds * 2);

    return $this->executor->execute(
        'DELETE FROM rate_limit_windows WHERE window_start < ?',
        [$cutoff],
    );
}
```

Old windows accumulate over time. `pruneExpired()` deletes rows older than two window
durations ago (current window + previous window are kept; older ones are removed).

Run `pruneExpired()` from a background task or after each request (with sampling —
e.g., `rand(0, 99) === 0` to run on ~1% of requests):

```php
if (random_int(0, 99) === 0) {
    $this->limiter->pruneExpired($now);
}
```

---

## Configuration injection

```php
$limiter = new SqliteRateLimiter($executor, limit: 3, windowSeconds: 60);
```

`limit` and `windowSeconds` are injected at construction. Different endpoints can use
different limiter instances with different configurations:

```php
$globalLimiter = new SqliteRateLimiter($executor, limit: 100, windowSeconds: 60);
$strictLimiter = new SqliteRateLimiter($executor, limit: 5,   windowSeconds: 60);
```

---

## Related howtos

- [`rate-limiting.md`](rate-limiting.md) — `ThrottleMiddleware` for per-route rate limiting
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — sliding window with sub-buckets (ratelog FT200)
- [`add-rate-limiting.md`](add-rate-limiting.md) — adding rate limiting to an existing route
- [`quota-management.md`](quota-management.md) — longer-horizon quotas (daily, monthly)
- [`api-usage-metering.md`](api-usage-metering.md) — per-user usage tracking with quota check
