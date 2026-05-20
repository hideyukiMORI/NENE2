# Field Trial 60 — Event Sourcing: Balance Derived from Event Replay (eventsourcelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/eventsourcelog/`
**NENE2 version**: 1.5.20
**Theme**: Event sourcing — account balance is never stored, always derived by replaying an append-only `account_events` log

## Overview

Built a bank account API to validate the event sourcing pattern:
- **Accounts**: created via `POST /accounts` with a user-supplied string ID and owner.
- **Events**: append-only `account_events` table; event types are `account_opened`, `money_deposited`, `money_withdrawn`.
- **Balance**: computed at read time by replaying all events for an account. No `balance` column exists in the schema.
- **Insufficient funds**: withdrawal checks current balance (replayed) and returns 409 Conflict if the account cannot cover the amount.

## Endpoints Implemented

- `POST /accounts` — open an account (records `account_opened` event)
- `GET /accounts/{id}` — current state with replayed balance
- `GET /accounts/{id}/events` — full event stream in insertion order
- `POST /accounts/{id}/deposits` — append `money_deposited` event
- `POST /accounts/{id}/withdrawals` — append `money_withdrawn` event (409 if insufficient funds)

## Test Results

18 tests, 33 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

None. The event sourcing pattern composed cleanly with NENE2's public API. The append-only event store maps naturally to `DatabaseQueryExecutorInterface::execute()` + `lastInsertId()`. Business rules (insufficient funds check) slot naturally into the repository before appending the event.

---

## Patterns Validated

### Append-only event store schema

```sql
CREATE TABLE IF NOT EXISTS account_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  TEXT NOT NULL,
    type        TEXT NOT NULL,
    amount      INTEGER NOT NULL DEFAULT 0,
    recorded_at TEXT NOT NULL
);
```

No `balance` column — the account state is always reconstructed from events.

### Balance replayed from event stream

```php
private function replay(array $events): int
{
    $balance = 0;
    foreach ($events as $event) {
        $balance += match ($event->type) {
            AccountEvent::TYPE_DEPOSITED => $event->amount,
            AccountEvent::TYPE_WITHDRAWN => -$event->amount,
            default                      => 0,
        };
    }
    return $balance;
}
```

`account_opened` contributes 0 — it acts as the stream anchor. `match` with a `default` arm makes it forward-compatible with new event types.

### Insufficient funds check before append

```php
public function withdraw(string $accountId, int $amountCents, string $now): AccountEvent
{
    $events  = $this->events($accountId);
    $balance = $this->replay($events);

    if ($balance < $amountCents) {
        throw new InsufficientFundsException($balance, $amountCents);
    }
    // ... INSERT INTO account_events ...
}
```

The check reads the current event stream, replays balance, and only appends if the invariant holds. For SQLite (synchronous), this is race-condition-free within a single request.

### Typed event constants

```php
final readonly class AccountEvent
{
    public const string TYPE_OPENED    = 'account_opened';
    public const string TYPE_DEPOSITED = 'money_deposited';
    public const string TYPE_WITHDRAWN = 'money_withdrawn';
}
```

PHP 8.3+ typed class constants (`string`) give PHPStan level 8 full type coverage without extra annotation.

---

## NENE2 Changes Required

None. Event sourcing is a pure application-layer pattern. All required primitives available in v1.5.20.
