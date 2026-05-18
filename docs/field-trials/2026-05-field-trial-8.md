# Field Trial 8 — v1.1.0 Production Features Validation

## Date

2026-05-18

## Baseline

- NENE2 v1.1.0 (via `composer require hideyukimori/nene2:dev-main`)
- New in v1.1.0:
  - `HealthCheckInterface` / `DatabaseHealthCheck` — DB connectivity check on `GET /health`
  - `ThrottleMiddleware` / `InMemoryRateLimitStorage` / `RateLimitStorageInterface` — rate limiting
- Environment: Ubuntu 24.04 + PHP 8.4 (Packagist install path, no git-clone)

## Goal

Validate v1.1.0 production features from a clean `composer require` starting point, verifying:

1. `HealthCheckInterface` — healthy (200) and degraded (503) paths
2. `ThrottleMiddleware` — 429 + `Retry-After` + `X-RateLimit-*` headers
3. `InMemoryRateLimitStorage` — hit counter increments and resets correctly

---

## Note

A detailed step-by-step trial log was not captured for this field trial.
The completion status is recorded in `docs/milestones/2026-05-v1.1.md` (Phase 46).

Verified outcomes per the milestone record:

| Item | Result |
|---|---|
| New project directory (`~/ft8`), no git-clone dependency | ✓ |
| `HealthCheckInterface` healthy → 200 / degraded → 503 | ✓ |
| `ThrottleMiddleware` 429 + `Retry-After` + `X-RateLimit-*` headers | ✓ |
| `InMemoryRateLimitStorage` hit counter | ✓ |

---

## Follow-up Issues

None opened from this trial. Friction identified in subsequent code review (#371, #372) was
addressed before v1.3.0.

## Conclusion

v1.1.0 production features (`HealthCheckInterface`, `ThrottleMiddleware`) validated on a clean
`composer require` install path. All acceptance criteria in the milestone passed.
