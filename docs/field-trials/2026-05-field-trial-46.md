# Field Trial 46 — Rate Limiting with Fixed-Window Counter (ratelimitlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/ratelimitlog/`
**NENE2 version**: 1.5.17
**Theme**: SQLite-backed fixed-window rate limiter, 429 Too Many Requests, `Retry-After` header, per-key independent limits, `DomainExceptionHandlerInterface` for 429 + custom response header

## Overview

Built a rate-limited API with a fixed-window counter per client key (supplied via `X-Client-Key` header). The limiter uses SQLite's `ON CONFLICT DO UPDATE` for atomic upsert and throws a typed `RateLimitExceededException` when the limit is exceeded. The exception handler converts this to a 429 Problem Details response with a `Retry-After` header added via PSR-7's immutable `withHeader()`.

## Endpoints Implemented

- `GET /ping` — rate-limited endpoint; reads `X-Client-Key` header; returns `{pong, client_key}` or 429
- `GET /status` — check current request count for a key without incrementing; `?key=<key>`

## Test Results

11 tests, 23 assertions — pass with no fixes required.

---

## Frictions Found

None. All patterns worked as expected on first attempt.

---

## Patterns Validated

### SQLite upsert for atomic counter increment

```sql
INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1
```

This is a single statement: insert with count=1 on the first request, increment on subsequent ones. No separate SELECT + UPDATE needed.

### Fixed-window boundary truncation

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));
    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` gives the offset within the window; subtracting it gives the window start. For a 60-second window, all requests within the same minute share the same `window_start`.

### 429 with Retry-After header via PSR-7 immutability

```php
public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
{
    assert($exception instanceof RateLimitExceededException);
    $response = $this->probs->create(
        request: $request, type: 'rate-limit-exceeded',
        title: 'Too Many Requests', status: 429,
        detail: $exception->getMessage(),
    );
    return $response->withHeader('Retry-After', (string) $exception->retryAfter);
}
```

`ProblemDetailsResponseFactory::create()` returns a PSR-7 `ResponseInterface`. `withHeader()` returns a new immutable instance with the added header. No special NENE2 API needed — standard PSR-7 chaining works.

### Typed exception with extra fields for the handler

```php
final class RateLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $key,
        public readonly int    $limit,
        public readonly int    $windowSeconds,
        public readonly int    $retryAfter,  // seconds to wait
    ) { ... }
}
```

The exception carries the `retryAfter` value so the handler can set the header without recalculating it. Since the handler receives a `Throwable`, `assert($exception instanceof RateLimitExceededException)` is needed before accessing the typed properties.

### `assert()` in exception handlers

```php
assert($exception instanceof RateLimitExceededException);
```

PHPStan level 8 without assertions would flag accessing `$exception->retryAfter` as accessing an undefined method on `Throwable`. The `assert()` narrows the type for both PHPStan and runtime. The `DomainExceptionHandlerInterface::supports()` check guarantees this assertion is always true.

### Per-key independent limits

Each rate limit key (e.g. client IP or API key) has its own row in `rate_limit_windows`. Different keys never interfere — the `PRIMARY KEY(key, window_start)` uniqueness is per-key.

### Prune expired windows

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

Keeps the table from growing unbounded. Called explicitly (not automatically on every request — that would add latency). Returns the count of deleted rows.

---

## NENE2 Changes Required

None — zero frictions. `DomainExceptionHandlerInterface`, `ProblemDetailsResponseFactory`, `QueryStringParser`, and PSR-7 `withHeader()` composed cleanly.

No version bump needed.
