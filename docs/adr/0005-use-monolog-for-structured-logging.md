# ADR 0005 — Use Monolog for Structured Logging

**Status:** Accepted  
**Date:** 2026-05-17  
**Issue:** [#206](https://github.com/hideyukiMORI/NENE2/issues/206)

---

## Context

NENE2 has a PSR-3 `LoggerInterface` binding in the DI container, but it was wired to `Psr\Log\NullLogger` — discarding all log output.
Structured, machine-readable logs are needed before the second field trial to diagnose request failures and observe behaviour in production-like environments.

Requirements:
- PSR-3 compatible (no leakage of `monolog/monolog` into domain or use-case code)
- JSON output to stderr (compatible with Docker / container log aggregators)
- Log level configurable via `APP_DEBUG`
- Minimal dependencies; no config files

## Decision

Use **Monolog 3.x** as the concrete PSR-3 implementation.

- `MonologLoggerFactory` (in `src/Log/`) creates a `Logger` with a single `StreamHandler` writing JSON to `php://stderr`.
- Channel name: `nene2`.
- Log level: `debug` when `APP_DEBUG=true`, `warning` otherwise.
- `RuntimeServiceProvider` replaces the `NullLogger` binding with a `MonologLoggerFactory` call.

## Alternatives Considered

| Option | Reason not chosen |
|---|---|
| Keep `NullLogger` | No observability; can't diagnose field trial issues |
| Custom PSR-3 JSON logger | Unnecessary maintenance burden; Monolog is the PHP standard |
| File handler | Requires volume management; stderr is the 12-factor standard |
| Log level via env variable | Extra env var complexity; `APP_DEBUG` is already available |

## Consequences

- Adds `monolog/monolog ^3.0` as a runtime dependency.
- Errors and warnings are visible in `docker compose logs app` with structured JSON fields.
- Domain, use-case, and middleware code stays decoupled — they only see `LoggerInterface`.
- Future: add `RequestIdProcessor` to attach `request_id` to every log record (out of scope here).
