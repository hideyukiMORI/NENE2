---
title: "How-to: Circuit Breaker"
category: infrastructure
tags: [circuit-breaker, resilience, state-machine, fault-tolerance]
difficulty: advanced
related: [job-queue, dead-letter-queue]
---

# How-to: Circuit Breaker

> **FT reference**: FT298 (`NENE2-FT/circuitlog`) — Circuit breaker pattern: closed/open/half_open three-state machine, configurable failure threshold, timeout-based automatic half_open transition, 503 Service Unavailable on open circuit, `isCallAllowed()` readonly check, 15 tests / 28 assertions PASS.

The circuit breaker pattern prevents cascading failures when calling external services. Instead of letting slow or failed calls pile up, the circuit trips open and immediately rejects calls until the dependency recovers.

## Three states

```
Closed ──(N consecutive failures)──▶ Open ──(timeout elapsed)──▶ Half-Open
  ▲                                                                    │
  └──────────────────(success)────────────────────────────────────────┘
  Half-Open ──(failure)──▶ Open
```

| State | Behavior |
|---|---|
| **Closed** | Normal — calls pass through. Failure count increments on each error. |
| **Open** | Calls immediately rejected with 503. Opens for `timeout_seconds` after `failure_threshold` consecutive failures. |
| **Half-Open** | Single probe call allowed. Success → Closed (reset). Failure → Open again. |

## Schema

```sql
CREATE TABLE IF NOT EXISTS circuits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    state             TEXT    NOT NULL DEFAULT 'closed',
    failure_count     INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    open_until        TEXT,
    half_open_at      TEXT,
    last_failure_at   TEXT,
    updated_at        TEXT    NOT NULL
);
```

The circuit name is typically the external service identifier (e.g., `payment-gateway`, `email-svc`). Multiple independent circuits can coexist.

## Recording outcomes

```php
// After a successful call to the external service:
$this->repo->recordSuccess($circuitName, $now);

// After a failed call:
$this->repo->recordFailure($circuitName, $now, timeoutSeconds: 30);
```

`recordFailure()` decides the transition:
- If `failure_count + 1 >= failure_threshold` → set state to `open`, compute `open_until = now + timeout`.
- If still below threshold → increment `failure_count`, stay `closed`.
- If in `half_open` state → any failure immediately reopens.

## Checking if a call is allowed

```php
$circuit = $this->repo->maybeTransitionToHalfOpen($name, $now);

if (!$circuit->isCallAllowed($now)) {
    // Return 503 immediately — don't call the external service
    return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503);
}

// Attempt the call...
```

Call `maybeTransitionToHalfOpen()` before the `isCallAllowed()` check on every request. This transitions `Open → Half-Open` once `open_until` has passed, allowing the probe call through.

```php
public function isCallAllowed(string $now): bool
{
    return match ($this->state) {
        CircuitState::Closed   => true,
        CircuitState::Open     => $now >= ($this->openUntil ?? ''),
        CircuitState::HalfOpen => true,
    };
}
```

## Half-Open timing

The `Open → Half-Open` transition is lazy: it happens the next time `maybeTransitionToHalfOpen()` is called after `open_until` has elapsed. This is intentional — it avoids background timers and keeps state changes tied to incoming requests.

## Failure threshold and timeout tuning

| Dependency type | Recommended threshold | Recommended timeout |
|---|---|---|
| Database (critical) | 3–5 | 10–30s |
| External API | 5–10 | 30–60s |
| Non-critical service | 10–20 | 60–120s |

Higher thresholds reduce false positives (temporary blips). Longer timeouts give dependencies more recovery time but extend customer-visible degradation.

## Multiple circuits per service

Use distinct circuit names for distinct failure domains:

```
payment-gateway/charge
payment-gateway/refund
email-svc/transactional
email-svc/marketing
```

This prevents a failure in the refund endpoint from blocking charge attempts.

## Response when circuit is Open

Return `503 Service Unavailable` with a `Retry-After` header pointing to `open_until`:

```php
return $problems->create($request, 'service-unavailable', 'Circuit is open.', 503, null, [
    'open_until' => $circuit->openUntil,
]);
```

Clients and load balancers that respect `503` can stop routing to this instance while the circuit is open.

## Design decisions

**Why DB-backed state instead of in-memory?** In-memory state is lost on restart and doesn't share across PHP-FPM workers. DB state is consistent across all workers and survives restarts, at the cost of one extra DB query per protected call. For high-throughput paths, consider Redis with atomic increment operations.

**Why lazy Half-Open transition?** Proactive background transitions require a scheduler or daemon. Lazy transitions are simpler, stateless from the scheduler's perspective, and sufficient for most web APIs where request volume ensures the check runs promptly.

**Why `failure_count` resets on any success?** This is "consecutive failures" semantics. An alternative is "failure rate over a sliding window" (e.g., >50% failures in the last 60 seconds). The sliding window is more accurate for services with low but steady traffic; consecutive failures is simpler and sufficient for services that are either up or down.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE(name)` constraint | Concurrent creates produce multiple rows for the same circuit |
| No timeout on open circuit | Circuit stays open forever after threshold breach |
| No half_open state | Circuit goes directly open → closed; no probe-then-verify |
| Return 200 when circuit is open | Callers think call succeeded; downstream errors hidden |
| No `open_until` in 503 response | Callers retry immediately (thundering herd); include retry timing |
| Accept string `"true"` as success | JSON type confusion; use `is_bool()` strictly |
| Check `isCallAllowed()` without `maybeTransitionToHalfOpen()` first | Open circuit never becomes half_open; stuck permanently |
| In-memory state only | State lost on worker restart; no sharing across PHP-FPM workers |
