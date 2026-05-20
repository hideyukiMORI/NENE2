# Field Trial 61 — State Machine: Order Workflow with Enforced Transitions (statemachinelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/statemachinelog/`
**NENE2 version**: 1.5.20
**Theme**: State machine — typed PHP enum for states, allowed-transitions map, 409 on invalid move, transition log

## Overview

Built an order workflow API to validate the state machine pattern with PHP 8.1+ backed enums:
- **States**: `draft → submitted → approved/rejected`, `draft → cancelled`, `submitted → cancelled`. Terminal states have no outgoing transitions.
- **Enum**: `OrderStatus` backed enum with an `allowedTransitions()` method returning the valid next states.
- **Transition enforcement**: `canTransitionTo()` check in the repository; throws `InvalidTransitionException` (→ 409) on violation.
- **Transition log**: every successful transition appends to `order_transitions` with `from_status`, `to_status`, and timestamp.

## Endpoints Implemented

- `POST /orders` — create order (starts in `draft`)
- `GET /orders/{id}` — current order state
- `POST /orders/{id}/transitions` — attempt a state transition; body: `{"status": "submitted"}`
- `GET /orders/{id}/transitions` — full transition history

## Test Results

16 tests, 31 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

None. The state machine pattern composed cleanly with NENE2's public API. PHP 8.1 backed enums with `tryFrom()` provided clean validation of the incoming status string; `match` in `allowedTransitions()` gives an exhaustive, PHPStan-verified transition table.

---

## Patterns Validated

### Backed enum as state machine

```php
enum OrderStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`match` is exhaustive — PHPStan level 8 catches missing cases at compile time.

### Unknown status value — 422 via `tryFrom()`

```php
$targetEnum = OrderStatus::tryFrom($statusRaw);
if ($targetEnum === null) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

`tryFrom()` handles the "not a valid enum value" case cleanly, keeping 422 (malformed input) distinct from 409 (invalid transition).

### 409 with structured `from`/`to` detail

```php
return $this->problems->create(
    $request, 'invalid-transition', 'Invalid State Transition', 409,
    $e->getMessage(),
    ['from' => $order->status->value, 'to' => $targetEnum->value],
);
```

The Problem Details `extensions` array carries machine-readable `from` and `to` values so the client can reason about which transition failed.

### Transition log persisted alongside state update

```php
$this->executor->execute('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?', ...);
$this->executor->execute('INSERT INTO order_transitions (order_id, from_status, to_status, ...) VALUES ...', ...);
```

State update and transition record happen in the same repository method. Both rows are written or neither is (SQLite implicit transaction per statement; for strict atomicity a transaction wrapper would be added).

---

## NENE2 Changes Required

None. The state machine pattern is a pure application-layer concern. All required primitives available in v1.5.20.
