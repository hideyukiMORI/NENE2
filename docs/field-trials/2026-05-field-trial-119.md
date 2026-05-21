# Field Trial Report — FT119: Circuit Breaker Pattern

**Date**: 2026-05-21
**Release**: v1.5.53
**App**: `circuitlog` (`/home/xi/docker/NENE2-FT/circuitlog/`)
**Tests**: 15/15 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement the circuit breaker pattern with three-state transitions (Closed → Open → Half-Open → Closed), configurable failure threshold, and timeout-based recovery. State is persisted in SQLite to survive restarts and share across workers.

## State machine

```
Closed ──(failures >= threshold)──▶ Open ──(open_until elapsed)──▶ Half-Open
  ▲                                                                      │
  └───────────────────(success)────────────────────────────────────────┘
Half-Open ──(any failure)──▶ Open
```

## Key decisions

### Lazy Half-Open transition

`maybeTransitionToHalfOpen()` is called on every request before the `isCallAllowed()` check. This avoids background timers or scheduled jobs — the transition happens naturally when traffic arrives after the timeout. For low-traffic services, this means the recovery probe is slightly delayed until the next request arrives, which is acceptable in practice.

### DB-backed state for consistency

In-memory state would be reset on PHP-FPM worker restart and wouldn't be shared between workers. DB state is consistent at the cost of one additional query per protected call. For very high throughput, Redis with atomic commands (INCR, SET NX) would be preferable.

### "Consecutive failures" not "failure rate"

`failure_count` resets to 0 on any success. This is simpler than a sliding window rate calculation and works well for services that are either working or not. A sliding window (e.g., >50% failures in 60 seconds) would be more appropriate for services with intermittent partial degradation.

### 503 on Open circuit

The call endpoint returns `503 Service Unavailable` with the circuit state and `open_until` when the circuit is open. This gives clients and upstream load balancers enough context to implement backoff.

## Test coverage (15 tests)

| Category | Tests |
|---|---|
| Initial state (new circuit starts Closed) | 1 |
| Closed → Open after threshold failures | 2 |
| Failures below threshold stay Closed | 1 |
| Success resets failure count | 1 |
| Open → Half-Open after timeout | 2 |
| Half-Open success → Closed | 1 |
| Half-Open failure → Open | 1 |
| GET /circuits/{name} (404, state) | 2 |
| Reset | 1 |
| Circuits isolated by name | 1 |
| open_until in response, last_failure_at | 2 |

## DX observations

- The three-state enum makes transitions self-documenting; adding a fourth state (e.g., `forced_open`) would be a clean extension.
- Passing `$now` as a string parameter to all repository methods makes temporal transitions fully testable without `sleep()`.
- The `isCallAllowed()` method on the record object keeps the Open check logic co-located with the state definition.
