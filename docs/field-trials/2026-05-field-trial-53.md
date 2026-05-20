# Field Trial 53 — Rate Limiting with ThrottleMiddleware (ratelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/ratelog/`
**NENE2 version**: 1.5.19
**Theme**: `ThrottleMiddleware` + `InMemoryRateLimitStorage` + custom key extractor, 429 Problem Details, rate limit headers, isolated per-key buckets

## Overview

Built a message-sending API to validate the full rate limiting stack:
- `ThrottleMiddleware` injected via `RuntimeApplicationFactory` `$throttleMiddleware` parameter
- Custom key extractor keying by `X-Sender-Id` header instead of IP
- `InMemoryRateLimitStorage` for in-process state during tests
- Limit: 3 requests per 60-second window per sender key
- Verified `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers on all responses
- Verified `Retry-After` header on 429 responses
- Verified isolated buckets per sender ID (alice's limit does not affect bob's)

## Endpoints Implemented

- `POST /messages` — send a message (rate-limited)
- `GET /messages` — list all messages (rate-limited)
- `GET /messages/by-sender?sender=<name>` — filter by sender (rate-limited)

## Test Results

16 tests, 36 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — `memory_limit` in phpstan.neon `parameters` block causes config error [LOW]

**Symptom**:
```
Invalid configuration:
Unexpected item 'parameters › memory_limit'.
```

**Root cause**: PHPStan 2.x removed `memory_limit` as a valid `parameters` key in `phpstan.neon`. It was valid in PHPStan 1.x.

**Fix**: Pass `--memory-limit=512M` as a CLI flag instead:
```bash
vendor/bin/phpstan analyse --level=8 --memory-limit=512M src tests
```

**NENE2 impact**: `docs/howto/quality-tools.md` already documents this at lines 26 and 45-46 with a warning note. No NENE2 changes needed — this is a developer education issue.

---

## Patterns Validated

### ThrottleMiddleware injection via RuntimeApplicationFactory

```php
$throttle = new ThrottleMiddleware(
    $problems,
    $storage,
    limit: 3,
    windowSeconds: 60,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'sender:' . ($r->getHeaderLine('X-Sender-Id') ?: 'anonymous'),
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
    routeRegistrars: [...],
))->create();
```

Clean named-argument injection. `ThrottleMiddleware` is positioned after auth in the middleware stack so it can key by authenticated identity.

### Rate limit headers on all responses

Every response (200, 201, 404, 422) carries:
- `X-RateLimit-Limit`: configured maximum
- `X-RateLimit-Remaining`: requests left in current window
- `X-RateLimit-Reset`: Unix timestamp when the window resets

Only 429 responses additionally carry `Retry-After`.

### 429 response is Problem Details

```json
{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit of 3 requests per 60 seconds exceeded. Try again in 58 seconds."
}
```

### InMemoryRateLimitStorage for test isolation

Each `setUp()` creates a fresh `InMemoryRateLimitStorage`, guaranteeing no state leaks between tests. The bucket is keyed per sender, so different `X-Sender-Id` values have independent windows.

### Custom key extractor for per-user rate limiting

The default key extractor uses `REMOTE_ADDR`, which is unsuitable behind a reverse proxy and won't key by authenticated user. The custom lambda pattern works cleanly:

```php
keyExtractor: static fn (ServerRequestInterface $r): string
    => 'sender:' . ($r->getHeaderLine('X-Sender-Id') ?: 'anonymous'),
```

In production, use an authenticated user ID attribute (populated by auth middleware) as the key.

---

## NENE2 Changes Required

None. The PHPStan `memory_limit` friction is already documented in `docs/howto/quality-tools.md`. All patterns validated are existing public API — no new features or fixes are needed.
