# How-to: Event Sourcing Ledger

> **FT reference**: FT310 (`NENE2-FT/eventsourcelog`) — Event sourcing account ledger: immutable event log (append-only), `replayBalance()` replays all events to compute current balance, deposit/withdraw events never deleted, `is_int()` strict amount validation, max amount 1,000,000,000, separate accounts don't share balance, 17 tests / 24 assertions PASS.

This guide shows how to implement an account ledger using event sourcing: the current balance is not stored directly — it's derived by replaying all past events.

## Schema

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
    payload      TEXT    NOT NULL,  -- JSON: { "amount": 100 }
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`events` is append-only. There is no `UPDATE` or `DELETE` on events. Each deposit or withdrawal appends a new row.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/accounts` | Create account |
| `GET` | `/accounts/{id}/balance` | Get current balance (replayed) |
| `POST` | `/accounts/{id}/deposit` | Append deposit event |
| `POST` | `/accounts/{id}/withdraw` | Append withdraw event |
| `GET` | `/accounts/{id}/events` | List all events |

## Balance — Replay from Events

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

Balance is not stored anywhere — it is computed fresh by replaying all events. New accounts start at 0 (no events). The event log is the source of truth.

## Deposit Validation

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;
if ($amount <= 0 || $amount > 1_000_000_000) {
    return $this->problems->create($request, 'validation-failed',
        'amount must be a positive integer not exceeding 1000000000.', 422, '');
}
// Append event
$this->repo->appendEvent($id, 'AccountDeposited', ['amount' => $amount], date('c'));
```

- `is_int()` rejects floats, strings, null
- `> 0` rejects zero and negative
- `<= 1_000_000_000` caps single-transaction amount

## Withdraw — Check Replay Balance First

```php
$balance = $this->repo->replayBalance($id);
if ($amount > $balance) {
    return $this->problems->create($request, 'validation-failed',
        'insufficient funds.', 422, '');
}
$this->repo->appendEvent($id, 'AccountWithdrawn', ['amount' => $amount], date('c'));
```

Replay balance before accepting a withdrawal. The overdraft check happens at the application layer — not DB constraints (the event row has no notion of "balance after").

## Account Isolation

Each account has its own `aggregate_id`. `replayBalance()` filters by `aggregate_id`, so:
- Account 1's deposits don't affect Account 2's balance
- Event lists are per-account (no cross-contamination)

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store `balance` column instead of replaying | Mutable state can get out of sync; events are the truth |
| Allow event deletion | Deleting events makes balance computation wrong retroactively |
| Accept float `amount` | Fractional amounts in event payload corrupt integer replay |
| No overdraft check at application level | Negative balance possible since events have no balance constraint |
| Shared event table without `aggregate_id` filter | All accounts share the same event stream |
| Replay all accounts' events for one balance | Full table scan instead of filtered by account |
