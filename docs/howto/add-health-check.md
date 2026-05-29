---
title: "Add a Health Check"
category: getting-started
tags: [health-check, monitoring, endpoint]
difficulty: beginner
related: [add-database-endpoint, quality-tools]
---

# Add a Health Check

This guide shows how to extend the `GET /health` endpoint with dependency health checks using
`HealthCheckInterface`.

**Prerequisite**: You have a working NENE2 application. If not, start with the [Tutorial](../tutorial/first-api.md).

---

## How health checks work

`GET /health` always returns a base payload:

```json
{ "service": "NENE2", "status": "ok", "timestamp": "2026-05-18T12:00:00+00:00" }
```

When you register `HealthCheckInterface` implementations, the endpoint adds a `checks` map:

- All checks pass → `200 OK`, `"status": "ok"`, each check shows `"ok"`
- Any check fails → `503 Service Unavailable`, `"status": "degraded"`, failing check shows `"error"`

---

## Quick start

Implement `HealthCheckInterface` and pass it to `RuntimeApplicationFactory`:

```php
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\HealthStatus;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CacheHealthCheck implements HealthCheckInterface
{
    // The return value of name() becomes the key in the "checks" map.
    public function name(): string { return 'cache'; }

    public function check(): HealthStatus
    {
        return $this->ping() ? HealthStatus::Ok : HealthStatus::Error;
    }

    private function ping(): bool { /* ... */ return true; }
}

$psr17 = new Psr17Factory();

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new CacheHealthCheck()],
))->create();
```

**Healthy response** (`200 OK`):

```json
{
  "service": "NENE2",
  "status": "ok",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "checks": { "cache": "ok" }
}
```

**Degraded response** (`503 Service Unavailable`):

```json
{
  "service": "NENE2",
  "status": "degraded",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "checks": { "cache": "error" }
}
```

---

## Use the DatabaseHealthCheck reference implementation

`src/Example/Health/DatabaseHealthCheck` is a ready-made check for PDO database connectivity.
It is part of `src/Example/` — copy and adapt it for your own project.

```php
use Nene2\Example\Health\DatabaseHealthCheck;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [new DatabaseHealthCheck($pdoConnection)],
))->create();
```

The check executes `SELECT 1` and returns `HealthStatus::Ok` on success, `HealthStatus::Error` on any exception.

> **Note**: `DatabaseHealthCheck` is in `src/Example/` — it is a reference implementation,
> not a stable API surface. Copy it into your application and adapt it to your needs.

---

## Multiple health checks

Pass as many checks as you need. Each runs independently; any failure degrades the overall status.

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    healthChecks: [
        new DatabaseHealthCheck($pdoConnection),
        new CacheHealthCheck($redis),
        new ExternalApiHealthCheck($httpClient),
    ],
))->create();
```

Response when database is healthy but cache is degraded:

```json
{
  "service": "NENE2",
  "status": "degraded",
  "timestamp": "2026-05-18T12:00:00+00:00",
  "checks": {
    "database": "ok",
    "cache": "error",
    "external-api": "ok"
  }
}
```

---

## Handle exceptions in checks

If your `check()` method throws an exception, `RuntimeApplicationFactory` treats it the same
as returning `false` — the status becomes `"degraded"` and the check shows `"error"`. You do
not need to catch exceptions inside `check()`.

---

## Next step

See [HTTP endpoints](../reference/http-endpoints.md) for the full `/health` response schema,
or [Add rate limiting](./add-rate-limiting.md) for request rate protection.
