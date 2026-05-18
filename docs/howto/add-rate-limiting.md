# Add Rate Limiting

This guide shows how to protect your NENE2 application with request rate limiting using
`ThrottleMiddleware` and `RateLimitStorageInterface`.

**Prerequisite**: You have a working NENE2 application. If not, start with the [Tutorial](../tutorial/first-api.md).

---

## Quick start

Add `ThrottleMiddleware` to `RuntimeApplicationFactory`. The built-in `InMemoryRateLimitStorage`
is suitable for local development and single-process deployments.

```php
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17    = new Psr17Factory();
$problems = new ProblemDetailsResponseFactory($psr17, $psr17);
$storage  = new InMemoryRateLimitStorage();

$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          60,   // requests allowed per window
    windowSeconds:  60,   // window length in seconds
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
))->create();
```

`ThrottleMiddleware` sits at position 8 in the middleware stack — after authentication,
so you can key limits per authenticated user if you choose (see [Custom key extractor](#custom-key-extractor) below).

---

## How it works

For every request, the middleware:

1. Computes a key for the client (default: `REMOTE_ADDR`).
2. Increments the counter in the storage backend.
3. If the counter is **at or below** the limit — passes the request through and adds rate limit headers.
4. If the counter **exceeds** the limit — returns `429 Too Many Requests` with Problem Details.

### Response headers

Every response (including 429) carries these headers:

| Header | Value |
|---|---|
| `X-RateLimit-Limit` | Configured limit per window |
| `X-RateLimit-Remaining` | Requests remaining in the current window |
| `X-RateLimit-Reset` | Unix timestamp when the window resets |
| `Retry-After` | Seconds until the window resets (429 only) |

### 429 response body

```json
{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit exceeded. Try again in 42 seconds.",
  "instance": "/examples/notes"
}
```

---

## Custom key extractor

By default the key is the client IP address (`REMOTE_ADDR`). Pass a `Closure` to key limits
per authenticated user, API key, or any other dimension.

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          1000,
    windowSeconds:  3600,
    keyExtractor:   static function (ServerRequestInterface $request): string {
        // Use a header set by BearerTokenMiddleware after token verification.
        return $request->getAttribute('user_id', 'anonymous');
    },
);
```

---

## Swap the storage backend

`InMemoryRateLimitStorage` stores counters in the PHP process memory. This is reset on every
request in FPM deployments and is **not shared between processes**. For production you need a
shared store such as Redis.

Implement `RateLimitStorageInterface`:

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    /** @return array{count: int, reset_at: int} */
    public function hit(string $key, int $windowSeconds): array
    {
        $redisKey = "rate:{$key}";
        $count    = (int) $this->redis->incr($redisKey);

        if ($count === 1) {
            $this->redis->expire($redisKey, $windowSeconds);
        }

        $ttl     = max(0, (int) $this->redis->ttl($redisKey));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

Then inject it instead of `InMemoryRateLimitStorage`:

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        new RedisRateLimitStorage($redis),
    limit:          200,
    windowSeconds:  60,
);
```

---

## Design decisions

See [ADR 0010](../adr/0010-rate-limiting.md) for the rationale behind:

- Fixed-window algorithm selection
- IP-keyed default
- Header conventions (`X-RateLimit-*`, `Retry-After`)
- `RateLimitStorageInterface` abstraction boundary

---

## Next step

See [Problem Details types](../reference/problem-details-types.md) for the full `429` error shape,
or [Add a health check](./add-health-check.md) for the complementary observability feature.
