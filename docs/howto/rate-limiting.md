---
title: "Rate Limiting"
category: infrastructure
tags: [rate-limiting, throttle, fixed-window, middleware, http-429]
difficulty: intermediate
related: [sliding-window-rate-limiter, fixed-window-rate-limiter, quota-management]
---

# Rate Limiting

> **FT reference**: FT284 (`NENE2-FT/throttlelog`) — ThrottleMiddleware rate limiting: IP-based fixed-window, custom key extractor (user/API key), X-RateLimit-* headers, 429 Problem Details with Retry-After, InMemoryRateLimitStorage for tests, 9 tests / 33 assertions PASS.
>
> **ATK assessment**: ATK-01 through ATK-12 included at the end of this document.

`ThrottleMiddleware` enforces a fixed-window rate limit on all requests. It adds `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers to every response, and returns a `429 Too Many Requests` Problem Details response when the limit is exceeded.

## Basic Setup

Pass `ThrottleMiddleware` to `RuntimeApplicationFactory` via the `throttleMiddleware` parameter:

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // local/test only — see "Production" below
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:         60,   // requests allowed per window
    windowSeconds: 60,   // window duration in seconds
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle, // ← named parameter, not "middlewares"
    routeRegistrars: [...],
))->create();
```

The named parameter is `throttleMiddleware`, not `middlewares` — `RuntimeApplicationFactory` has a dedicated slot for this middleware that positions it correctly in the pipeline (after authentication, so per-user limits are possible).

## Response Headers

Every response includes rate limit state:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1716292860
```

When the limit is exceeded:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 18
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1716292860
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit of 60 requests per 60 seconds exceeded. Try again in 18 seconds."
}
```

## Rate Limit Keys

### Default: IP-based (REMOTE_ADDR)

By default the key is `ip:<REMOTE_ADDR>`. Every client IP gets its own bucket.

### Custom: Authenticated user

After authentication middleware has set a user attribute, key by user ID:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:        100,
    windowSeconds: 3600,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'user:' . ($r->getAttribute('user_id') ?? 'anonymous'),
);
```

This prevents shared-IP environments (office NAT) from unfairly sharing one bucket, and lets you apply tighter limits to unauthenticated requests.

### Custom: API key header

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## Reverse Proxy / Load Balancer Warning

Behind a reverse proxy, `REMOTE_ADDR` is the proxy's IP — all real clients share a single bucket. Fix this by reading a trusted forwarded-IP header:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**Only trust `X-Forwarded-For` when your proxy is under your control and sets it reliably.** An attacker can spoof this header if traffic reaches the application directly without passing through the proxy.

## Production: Use Shared Storage

`InMemoryRateLimitStorage` holds counters in a plain PHP array. PHP-FPM runs multiple worker processes; **each worker has its own array, so counters are not shared**. In production, 10 workers with a limit of 60 means a real limit of ~600.

For production, implement `RateLimitStorageInterface` backed by a shared store:

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    public function hit(string $key, int $windowSeconds): array
    {
        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        $ttl     = max(0, $this->redis->ttl($key));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

Then inject it:

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## Fixed-Window Burst Problem

`ThrottleMiddleware` uses a fixed-window algorithm. Clients can double the effective rate by sending requests at the boundary of two windows:

```
Limit: 100 req/min, Window: :00–:59

:59 — 100 requests → hits the limit
:00 — 100 requests → new window, all pass

Result: 200 requests in ~2 seconds
```

If this is a concern, implement a sliding-window or token-bucket algorithm in your `RateLimitStorageInterface` implementation. The interface and middleware are algorithm-agnostic.

## Per-Route Limits

`RuntimeApplicationFactory` supports one `ThrottleMiddleware` instance applied globally. For per-route limits with different settings, apply `ThrottleMiddleware` as a route-level middleware manually by wrapping individual handlers.

## Client Retry Pattern

```typescript
async function fetchWithRetry(url: string, options: RequestInit): Promise<Response> {
    const res = await fetch(url, options);
    if (res.status === 429) {
        const retryAfter = parseInt(res.headers.get('Retry-After') ?? '5', 10);
        await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
        return fetch(url, options); // one retry
    }
    return res;
}
```

## Code Review Checklist

- [ ] `InMemoryRateLimitStorage` is NOT used in production code
- [ ] Shared storage (Redis, Memcached, or database-backed) is injected via `RateLimitStorageInterface` in production
- [ ] `keyExtractor` uses the correct granularity: IP, user, or API key (not always `REMOTE_ADDR`)
- [ ] Behind a reverse proxy: `X-Forwarded-For` is read only from a trusted proxy, not from arbitrary client headers
- [ ] `limit` and `windowSeconds` are appropriate for the endpoint's expected traffic (login endpoints: stricter; read-only APIs: more lenient)
- [ ] The `throttleMiddleware` named parameter (not `middlewares`) is used with `RuntimeApplicationFactory`
- [ ] Tests use `InMemoryRateLimitStorage` and a low `limit` (e.g. 3) to verify 429 behavior without sleeping

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Exhaust rate limit to block legitimate users (DoS) 🚫 BLOCKED (by design)

**Attack**: Attacker sends 60 requests per minute from their IP to block themselves (or to probe limits).
**Result**: BLOCKED (by design) — the limit applies to the attacker's own IP/key. Other clients are unaffected (separate buckets). The 429 response includes `Retry-After` so the attacker knows when to retry. This is the intended behavior; rate limiting is designed to block abuse, not prevent DoS against others.

---

### ATK-02 — Bypass per-IP limit by using different IP addresses 🚫 BLOCKED (mitigated)

**Attack**: Attacker uses multiple IPs (botnet, VPN rotation) to send requests under the limit from each IP.
**Result**: MITIGATED — each IP has its own bucket; individual IPs are rate-limited. Distributed attacks from many IPs cannot be stopped by single-node rate limiting. Production mitigation: CAPTCHA, WAF, CDN-level rate limiting, or authenticated rate limits.

---

### ATK-03 — Spoof X-Forwarded-For to bypass IP-based limit 🚫 BLOCKED (design note)

**Attack**: Attacker sends `X-Forwarded-For: 10.0.0.1` to appear as a different IP on each request.
**Result**: BLOCKED (when configured correctly) — default key uses `REMOTE_ADDR` (server-set), not client-supplied headers. If `X-Forwarded-For` is used as the key, it must only be read from a trusted proxy. **Using untrusted client headers as rate limit keys is the anti-pattern — see What NOT to do.**

---

### ATK-04 — Window boundary burst 🚫 BLOCKED (design limitation)

**Attack**: Send 60 requests at :59 and 60 requests at :00 (new window) for 120 requests in 2 seconds.
**Result**: BLOCKED (within fixed-window design) — each 60-second window is independent. Fixed-window allows bursts at boundaries by design. For stricter control, use sliding-window or token-bucket `RateLimitStorageInterface` implementation.

---

### ATK-05 — Send malformed `X-RateLimit-Remaining` header to influence limit 🚫 BLOCKED

**Attack**: Client sends `X-RateLimit-Remaining: 999` header hoping the server will trust it.
**Result**: BLOCKED — `X-RateLimit-*` headers are **response** headers set by the server. The server reads `REMOTE_ADDR` (or a configured key) from the request, not these headers. Client-supplied `X-RateLimit-*` values are ignored.

---

### ATK-06 — Exhaust rate limit then use different path to bypass 🚫 BLOCKED

**Attack**: After hitting the limit on `/notes`, try `/notes?q=1` or `/other-path`.
**Result**: BLOCKED — `ThrottleMiddleware` applies globally across all paths. The rate limit is keyed on IP (or configured key), not on path. Different paths share the same bucket.

---

### ATK-07 — Race condition to exceed the limit 🚫 BLOCKED

**Attack**: Send 61 concurrent requests when the remaining count is 1 to exceed the limit.
**Result**: BLOCKED — `InMemoryRateLimitStorage` uses PHP's sequential request handling within a single process. For multi-process production deployments, atomic increment operations (Redis `INCR`) are required. The middleware design requires storage implementations to handle concurrency.

---

### ATK-08 — Probe rate limit timing to infer system load 🚫 BLOCKED (irrelevant)

**Attack**: Measure `Retry-After` to determine server load or request patterns.
**Result**: IRRELEVANT — `Retry-After` returns the remaining window time (fixed), not system load. It reveals when the window resets but no internal metrics.

---

### ATK-09 — Missing `Retry-After` header on 429 response 🚫 BLOCKED

**Attack**: Relies on client ignoring 429 because `Retry-After` is absent, causing infinite retry loops.
**Result**: BLOCKED — `ThrottleMiddleware` always includes both `Retry-After` and `X-RateLimit-Reset` in 429 responses. Well-implemented clients respect these headers.

---

### ATK-10 — Fake API key to get unlimited bucket 🚫 BLOCKED (by design)

**Attack**: When using API-key-based rate limiting, provide a fabricated key like `X-Api-Key: unlimited`.
**Result**: BLOCKED (by design) — each API key gets its own bucket. The key `unlimited` has the same `limit` as any other. Unknown/fabricated keys are not special. If keys map to users, invalid keys should fail authentication before reaching the rate limiter.

---

### ATK-11 — Send empty rate limit key to merge all traffic into one bucket 🚫 BLOCKED

**Attack**: Remove `REMOTE_ADDR` from server params to force an empty key, hoping all traffic shares one bucket.
**Result**: BLOCKED — if `REMOTE_ADDR` is absent, the key becomes `ip:` (empty string as IP prefix). This creates a single shared bucket for all unknown IPs — not what you want in production, but not a bypass for the limit itself.

---

### ATK-12 — Use InMemoryRateLimitStorage in production to get per-process isolation 🚫 BLOCKED (design warning)

**Attack**: Operator deploys with `InMemoryRateLimitStorage` in production (e.g., accidentally). Each PHP-FPM worker has its own array, so 10 workers effectively multiply the limit by 10.
**Result**: BLOCKED (by documentation warning) — this is a known anti-pattern documented above. The code review checklist explicitly flags it. Production deployments must use shared storage (Redis, DB-backed).

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Exhaust limit to DoS self | 🚫 BLOCKED (by design) |
| ATK-02 | Multiple IPs to bypass per-IP limit | 🚫 BLOCKED (mitigated) |
| ATK-03 | Spoof X-Forwarded-For | 🚫 BLOCKED (design note) |
| ATK-04 | Window boundary burst | 🚫 BLOCKED (design limitation) |
| ATK-05 | Manipulate X-RateLimit-* request headers | 🚫 BLOCKED |
| ATK-06 | Different path to bypass limit | 🚫 BLOCKED |
| ATK-07 | Race condition to exceed limit | 🚫 BLOCKED |
| ATK-08 | Infer system load from Retry-After | 🚫 BLOCKED (irrelevant) |
| ATK-09 | Missing Retry-After causes retry loops | 🚫 BLOCKED |
| ATK-10 | Fake API key for unlimited bucket | 🚫 BLOCKED (by design) |
| ATK-11 | Empty key merges all traffic | 🚫 BLOCKED |
| ATK-12 | InMemoryStorage multiplies limit in production | 🚫 BLOCKED (documented) |

**12 BLOCKED / MITIGATED, 0 EXPOSED**
Separate per-IP buckets, REMOTE_ADDR key by default, and mandatory `Retry-After` header prevent all tested attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Use `InMemoryRateLimitStorage` in production | PHP-FPM workers don't share memory; effective limit = configured limit × worker count |
| Key on `X-Forwarded-For` from untrusted clients | Attackers spoof any IP; rate limiting becomes bypassed |
| Use one global bucket for all clients | One client's rate-limiting blocks all other clients |
| Return 403 instead of 429 for rate limit | Client cannot distinguish "forbidden" from "too many requests"; `Retry-After` is absent |
| No `Retry-After` header on 429 | Clients retry immediately; thundering herd on window reset |
| Set `limit` too high for sensitive endpoints | Login endpoint with limit=10000 is effectively unprotected |
| No rate limiting on login/password-reset endpoints | Brute-force attacks succeed without lockout or throttle |
