# Rate Limiting

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
