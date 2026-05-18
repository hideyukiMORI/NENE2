# ADR 0010: Rate Limiting Design

## Status

accepted

## Context

NENE2 v1.0 ships a `BearerTokenMiddleware` and an `ApiKeyAuthenticationMiddleware`, but has no
mechanism to cap request volume. Without rate limiting, a single client can saturate the server,
block legitimate users, and drive up infrastructure cost in production environments.

Three design choices were evaluated:

### 1. Algorithm

| Algorithm | Pros | Cons |
|-----------|------|------|
| **Fixed window** | Simple implementation; easy to reason about; storage needs one counter + TTL | Allows burst at window boundary |
| Sliding window log | More accurate | Stores a timestamp per request; memory grows with traffic |
| Token bucket | Smooth bursting | More complex state (tokens + last-refill-time) |

**Decision: fixed window.** The burst-at-boundary problem is acceptable for a framework default
and easy to explain to adopters. Adopters who need smoother limiting can implement a
`RateLimitStorageInterface` backed by Redis with their preferred algorithm.

### 2. Rate limit key

Common options: client IP (`REMOTE_ADDR`), authenticated user id, or a composite.

**Decision: default to `REMOTE_ADDR`.** The `ThrottleMiddleware` accepts a callable key extractor
so adopters can override it with `$request->getAttribute('nene2.auth.claims')['sub']` or any
other value.

### 3. Storage backend

Framework ships an in-memory storage for local development and testing. Production adopters need
a shared store (Redis, Memcached, database) that survives process restarts and scales horizontally.

**Decision: `RateLimitStorageInterface` as the stable abstraction.** `InMemoryRateLimitStorage`
is `@internal` — for tests only; it shares no state between PHP-FPM processes.

### 4. HTTP response conventions

RFC 6585 defines 429 Too Many Requests. Common headers:

- `Retry-After` — seconds until the window resets (RFC 7231 §7.1.3)
- `X-RateLimit-Limit` — configured limit
- `X-RateLimit-Remaining` — remaining requests in this window
- `X-RateLimit-Reset` — Unix timestamp when the window resets

**Decision: emit all four headers** on both allowed and rate-limited responses so clients can
implement polite back-off.

### 5. Middleware pipeline position

Rate limiting must run after authentication so that per-user limits are possible. It must run
before routing and handler dispatch to protect all routes uniformly.

**Decision: position 8, after Auth, before routing.**

```
1. Request id
2. Request logging
3. Security headers
4. CORS
5. Error handling
6. Request size limit
7. Authentication / authorization
8. Rate limiting          ← ThrottleMiddleware
9. Routing / handler dispatch
```

## Decision

- Add `RateLimitStorageInterface` to `Nene2\Middleware` (stable public surface, see ADR 0009).
- Add `InMemoryRateLimitStorage` as an `@internal` implementation.
- Add `ThrottleMiddleware` (fixed window, IP-keyed by default, configurable) to `Nene2\Middleware`.
- Wire via `RuntimeApplicationFactory` optional parameter (not enabled by default).

## Consequences

**Benefits**

- Adopters get a tested, drop-in rate limiter for local validation scenarios.
- `RateLimitStorageInterface` lets teams swap Redis or Memcached without touching framework code.
- Consistent `X-RateLimit-*` headers help clients implement polite retry logic.

**Costs / follow-up work**

- `InMemoryRateLimitStorage` is **not safe for multi-process or multi-server deployments**. This
  must be documented prominently.
- Adopters building per-route or per-user limits will need to compose multiple `ThrottleMiddleware`
  instances or implement a custom middleware wrapping the interface.

## Related

- Issue: `#348`
- See also: ADR 0008 (JWT auth), ADR 0009 (stable surface), `docs/review/middleware-security.md`
