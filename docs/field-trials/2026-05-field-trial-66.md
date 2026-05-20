# Field Trial 66 — Subscription Lifecycle with Billing Periods

**Date**: 2026-05-20
**Theme**: Recurring subscription management with billing period tracking, lifecycle transitions (active/paused/cancelled), and expiry queries
**Project**: `/home/xi/docker/NENE2-FT/subscriptionlog/`
**NENE2 version**: 1.5.21

---

## Summary

Implemented a subscription management API covering the full lifecycle: create, pause, resume, cancel, and renew. Added a range-based query endpoint for finding subscriptions expiring before a given date. Tests date arithmetic with `DateTimeImmutable`, status state machine (without a formal enum — simple guard conditions), and static-vs-dynamic route ordering.

**Result**: 19 tests, 35 assertions — all pass. PHPStan level 8 clean. CS-Fixer clean.

---

## What Was Built

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/subscriptions` | Create a subscription (user_id, plan) |
| `GET` | `/subscriptions/expiring?before=YYYY-MM-DD` | List active subs expiring before date |
| `GET` | `/subscriptions/{id}` | Get a subscription |
| `GET` | `/users/{userId}/subscriptions` | List all subscriptions for a user |
| `POST` | `/subscriptions/{id}/cancel` | Cancel (idempotent if already cancelled → 409) |
| `POST` | `/subscriptions/{id}/pause` | Pause (only from active) |
| `POST` | `/subscriptions/{id}/resume` | Resume (only from paused) |
| `POST` | `/subscriptions/{id}/renew` | Advance billing period by one month |

### Domain Model

```
subscriptions(id, user_id, plan, status, current_period_start, current_period_end, cancelled_at, created_at, updated_at)
```

Status values: `active`, `paused`, `cancelled`, `expired` (string-backed enum).

### Billing Period Renewal

```php
$periodEnd = new \DateTimeImmutable($sub->currentPeriodEnd);
$newStart  = $periodEnd->format('Y-m-d');
$newEnd    = $periodEnd->modify('+1 month')->format('Y-m-d');
```

`DateTimeImmutable::modify('+1 month')` handles month-end edge cases (e.g., Jan 31 → Mar 3 in PHP, not Feb 28). This is a known PHP date gotcha worth documenting in production use.

### Expiry Query

```sql
SELECT * FROM subscriptions
WHERE status = 'active' AND current_period_end <= ?
ORDER BY current_period_end ASC
```

The `before` query param uses `YYYY-MM-DD` format validated by regex. SQLite date string comparison works correctly for ISO 8601 strings.

---

## Frictions Encountered

### F-1 (Medium): Static route must be registered before parameterized sibling

**Symptom**: Both `GET /subscriptions/expiring` and `GET /subscriptions/{id}` exist. NENE2's router matches in registration order. Registering `GET /subscriptions/{id}` first causes `/subscriptions/expiring` to be matched with id="expiring" → cast to `(int)` → 0 → 404.

**Workaround**: Register static routes before parameterized siblings on the same HTTP method.

```php
// Correct order:
$router->get('/subscriptions/expiring', $this->expiring(...));  // static first
$router->get('/subscriptions/{id}', $this->get(...));
```

**Impact**: Surprised developers often encounter this when adding "sub-resource action" routes alongside GET by ID. The router's PHPDoc notes "Routes are matched in registration order," but this consequence for static vs. dynamic path segments on the same method isn't surfaced as a warning.

**Suggested fix**: NENE2 could warn in debug mode when a parameterized route is registered before a static route that would shadow it, or auto-sort static routes to take precedence.

---

## Patterns Validated

### Pattern: Null-Return Status Guard (No Formal State Machine)

For simple linear lifecycles, guard conditions in repository methods are cleaner than a full state machine enum:

```php
public function pause(int $id, string $now): ?Subscription
{
    $sub = $this->findById($id);
    if ($sub === null || $sub->status !== SubscriptionStatus::Active) {
        return null;  // controller returns 409
    }
    // ...
}
```

The state machine enum pattern (FT61) is better when there are many valid transitions; for simple "must be in X to do Y," a null guard suffices.

### Pattern: Date Range Query with Query Param Validation

```php
if ($before === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
    return $this->problems->create($request, 'validation-failed', ..., 422, ...);
}
```

Regex validates format; SQLite `<=` comparison handles semantics. The `before` param is inclusive (subscriptions ending on exactly that date are included).

### Pattern: Sub-Resource Actions via POST

Lifecycle transitions (`/cancel`, `/pause`, `/resume`, `/renew`) use `POST` rather than `PATCH status=...`. This is more expressive and avoids "what do I send to the body?" confusion for side-effect-heavy operations.

---

## No NENE2 Changes Required

All patterns implementable with NENE2 1.5.21. The routing registration-order friction (F-1) warrants a GitHub issue.
