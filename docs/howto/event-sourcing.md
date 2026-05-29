---
title: "Event Sourcing (Basic)"
category: infrastructure
tags: [event-sourcing, append-only, immutable, replay, domain-events]
difficulty: intermediate
related: [event-sourcing-ledger, event-sourcing-cqrs-api, cqrs-pattern]
---

# Event Sourcing (Basic)

Persist state as an immutable sequence of domain events. Derive current state by replaying the event stream.

## Overview

Event sourcing stores **what happened** (events) rather than **what is** (current state). The balance of an account is not stored; it is computed by replaying all deposit and withdrawal events. Events are immutable — they are never updated or deleted.

## Database Schema

```sql
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,  -- JSON
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`accounts` is the aggregate root. `events` is the append-only event log. There is no `balance` column — it is always computed from events.

## Event Types

Define event types as constants to prevent typos and enable static analysis:

```php
public const string TYPE_ACCOUNT_CREATED = 'account_created';
public const string TYPE_DEPOSITED       = 'deposited';
public const string TYPE_WITHDRAWN       = 'withdrawn';
```

## Appending Events

Events are always inserted, never updated. The API has no endpoint that modifies or deletes events:

```php
public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->executor->execute(
        'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
        [$aggregateId, $eventType, $payloadJson, $now],
    );
    ...
}
```

## Replaying State

Load events in insertion order and fold them into current state:

```php
public function replayBalance(int $aggregateId): int
{
    $events  = $this->findEventsByAggregateId($aggregateId);
    $balance = 0;

    foreach ($events as $event) {
        $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

        if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
            $balance += $amount;
        } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
            $balance -= $amount;
        }
    }

    return $balance;
}
```

`ORDER BY id ASC` guarantees replay order. `ORDER BY occurred_at ASC` is fragile — two events with the same timestamp would have undefined order.

## Amount Validation

Validate amounts strictly before appending events:

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

if ($amount <= 0 || $amount > 1_000_000_000) {
    return 422;
}
```

- `is_int()` rejects float values (e.g. `1.9`) that PHP would otherwise silently truncate to `1`
- The upper bound prevents integer overflow when summing multiple large deposits
- Reject at the API layer — do not let invalid amounts reach the event log

## Insufficient Funds

Check balance before appending a withdrawal event:

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
}

$event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);
```

The balance check happens in the handler (not in the repository) because it is a business rule, not a data integrity constraint.

## Event Isolation

Events are scoped to their aggregate by `aggregate_id`. Replaying account A's events never touches account B:

```sql
SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC
```

## Security Properties

| Property | Implementation |
|---|---|
| Event immutability | No DELETE/UPDATE endpoint on events |
| Amount range | 1–1,000,000,000 (int) — rejects floats and overflow values |
| Insufficient funds | Balance replayed before withdrawal; 422 if insufficient |
| Cross-account isolation | All queries filter by aggregate_id |
| Payload injection | Payload always `['amount' => int]`; no user-controlled keys |
| Event type injection | Event type always from constants; no user-controlled event_type |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/accounts` | Create account |
| `POST` | `/accounts/{id}/deposit` | Append deposit event |
| `POST` | `/accounts/{id}/withdraw` | Append withdrawal event (balance check) |
| `GET` | `/accounts/{id}/balance` | Replay balance from events |
| `GET` | `/accounts/{id}/events` | List all events for account |
