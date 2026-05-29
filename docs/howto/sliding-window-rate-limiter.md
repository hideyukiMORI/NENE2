---
title: "How-to: Sliding-Window Rate Limiter"
category: infrastructure
tags: [rate-limiting, sliding-window, http-429, per-user]
difficulty: intermediate
related: [rate-limiting, fixed-window-rate-limiter, quota-management]
---

# How-to: Sliding-Window Rate Limiter

## Overview

This guide covers building a per-user, per-endpoint sliding-window rate limiter with NENE2. Requests are counted within a rolling time window; once the limit is reached, further requests are rejected with `429 Too Many Requests`.

**Reference implementation**: `../NENE2-FT/ratelog/`

---

## Schema Design

```sql
CREATE TABLE IF NOT EXISTS rate_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    endpoint   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_rate_events_user_endpoint
    ON rate_events (user_id, endpoint, created_at);
```

The index on `(user_id, endpoint, created_at)` makes the COUNT query fast at scale.

---

## Route Table

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/rate/check` | User | Record a request; returns 429 if over limit |
| `GET` | `/rate/status` | User | Current usage for a user/endpoint |
| `DELETE` | `/rate/reset/{userId}` | Admin | Reset counters for a user |

---

## Core Algorithm

```php
private const int LIMIT = 10;
private const int WINDOW_SECONDS = 60;

public function check(int $userId, string $endpoint): string
{
    $since = $this->windowStart();   // now() - 60s
    $count = $this->countInWindow($userId, $endpoint, $since);

    if ($count >= self::LIMIT) {
        return 'rate_limited';
    }

    $this->recordEvent($userId, $endpoint);
    return 'ok';
}
```

**Sliding window**: every `check()` looks back exactly `WINDOW_SECONDS` from the current moment, so old events naturally fall out of scope.

---

## Admin Reset with Fail-Closed Pattern

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;     // fail-closed: unconfigured key blocks all admin access
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Reset all counters for a user (all endpoints):
```sql
DELETE FROM rate_events WHERE user_id = :uid
```

Reset for a specific endpoint:
```sql
DELETE FROM rate_events WHERE user_id = :uid AND endpoint = :ep
```

---

## Path Parameter Extraction (no Router::param())

When `Router::param()` is not available in the installed version, use the attribute directly:

```php
/** @var array<string, string> $params */
$params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$raw    = $params['userId'] ?? '';
```

---

## Validation

- `endpoint`: non-empty string, max 128 chars
- `X-User-Id`: `ctype_digit()` + positive integer
- Path `userId`: `ctype_digit()` + positive integer (fail → 404)
- Admin key: `hash_equals()` comparison (fail → 403)

---

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Request allowed | 200 |
| Status retrieved | 200 |
| Counter reset | 200 |
| No X-User-Id | 400 |
| No body | 400 |
| Empty / missing endpoint | 422 |
| Endpoint too long | 422 |
| No admin key | 403 |
| Wrong admin key | 403 |
| Invalid userId in path | 404 |
| Rate limit exceeded | 429 |

---

## ATK Attack Patterns Covered

| ATK | Pattern | Defence |
|-----|---------|---------|
| ATK-01 | Missing X-User-Id | 400 with message |
| ATK-02 | Empty endpoint string | 422 validation |
| ATK-03 | 129-char endpoint (DoS) | Length limit 422 |
| ATK-04 | SQL injection in endpoint | Parameterized queries |
| ATK-05 | Non-admin reset attempt | 403 fail-closed |
| ATK-06 | Wrong admin key | 403 hash_equals() |
| ATK-07 | Negative userId in path | 404 |
| ATK-08 | Zero userId | 404 |
| ATK-09 | Non-digit userId (`abc`) | 404 ctype_digit |
| ATK-10 | Status without endpoint param | 422 |
| ATK-11 | Check without body | 400 |
| ATK-12 | Body missing endpoint key | 422 |

---

## Notes

- **Concurrency**: The sliding window has a small TOCTOU window. For high-concurrency production use, consider atomic counters (Redis INCR + EXPIRE) or database-level locking.
- **Clock skew**: All timestamps should use UTC to avoid DST or timezone surprises.
- **Storage growth**: Old events accumulate. Add a periodic cleanup job: `DELETE FROM rate_events WHERE created_at < :cutoff`.
