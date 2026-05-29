---
title: "How-to: Event Sourcing & CQRS API"
category: infrastructure
tags: [event-sourcing, cqrs, append-only, read-model, projection]
difficulty: advanced
related: [event-sourcing, cqrs-pattern, event-sourcing-ledger]
---

# How-to: Event Sourcing & CQRS API

> **FT reference**: `NENE2-FT/eventstore` — Append-only event log with per-aggregate sequence numbers, allowlisted event types, read-model projection rebuilt from events, balance tracking, 17 tests PASS.

This guide shows how to implement event sourcing: store every state change as an immutable event, compute the current state from the event log, and expose a read-model projection.

## Schema

```sql
-- Append-only event log — never UPDATE or DELETE rows
CREATE TABLE domain_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id   TEXT    NOT NULL,  -- e.g. "acc-001"
    aggregate_type TEXT    NOT NULL,  -- e.g. "account"
    event_type     TEXT    NOT NULL,  -- e.g. "MoneyDeposited"
    payload        TEXT    NOT NULL DEFAULT '{}',  -- JSON
    sequence       INTEGER NOT NULL,  -- per-aggregate counter, starts at 1
    occurred_at    TEXT    NOT NULL,
    UNIQUE(aggregate_id, sequence)
);

-- Read-model: current account state rebuilt from events
CREATE TABLE account_projections (
    account_id    TEXT    PRIMARY KEY,
    owner         TEXT    NOT NULL,
    balance_cents INTEGER NOT NULL DEFAULT 0,
    is_open       INTEGER NOT NULL DEFAULT 1,
    last_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT    NOT NULL
);
```

`UNIQUE(aggregate_id, sequence)` prevents duplicate event insertion. The projection is always derived from the event log — it can be rebuilt at any time by replaying.

## Event Types (Allowlist)

```php
const ALLOWED_EVENTS = [
    'AccountOpened',
    'MoneyDeposited',
    'MoneyWithdrawn',
    'AccountClosed',
];
```

Unknown event types return 422. Only append events that have explicit handlers in the projection logic.

## Endpoints

| Method | Path                          | Description                     |
|--------|-------------------------------|---------------------------------|
| `POST` | `/accounts`                   | Open account (emits AccountOpened) |
| `GET`  | `/accounts`                   | List all account projections    |
| `GET`  | `/accounts/{id}`              | Get account projection (404 if not found) |
| `POST` | `/accounts/{id}/events`       | Append event to account         |
| `GET`  | `/accounts/{id}/events`       | List event log for account      |

## Open Account

```php
POST /accounts
{"account_id": "acc-001", "owner": "Alice"}

→ 201
{
  "event_type": "AccountOpened",
  "aggregate_id": "acc-001",
  "sequence": 1,
  "payload": {"owner": "Alice"},
  "occurred_at": "..."
}
```

Opening an account creates an `AccountOpened` event (sequence=1) and initialises the projection.

## Append Events

```php
POST /accounts/acc-001/events
{"event_type": "MoneyDeposited", "payload": {"amount_cents": 50000}}

→ 201
{
  "event_type": "MoneyDeposited",
  "aggregate_id": "acc-001",
  "sequence": 2,         // ← increments per aggregate
  "payload": {"amount_cents": 50000},
  "occurred_at": "..."
}
```

Each account has an **independent sequence counter**. `acc-001` and `acc-002` both start at 1.

```php
// Invalid event type → 422
POST /accounts/acc-001/events  {"event_type": "UnknownEvent"}
→ 422

// Non-existent account → 404
POST /accounts/nonexistent/events  {"event_type": "MoneyDeposited", "payload": {"amount_cents": 1000}}
→ 404
```

## Read-Model Projection

```php
GET /accounts/acc-001

→ 200
{
  "account_id": "acc-001",
  "owner": "Alice",
  "balance_cents": 60000,   // 50000 deposited + 10000 deposited
  "is_open": true,
  "last_sequence": 3
}

// AccountClosed event applied
GET /accounts/acc-001  // after AccountClosed appended
→ 200  {"is_open": false, "last_sequence": 4}
```

```php
GET /accounts/nonexistent
→ 404
```

## Event Log

```php
GET /accounts/acc-001/events

→ 200
{
  "total": 3,
  "items": [
    {"event_type": "AccountOpened",  "sequence": 1, ...},
    {"event_type": "MoneyDeposited", "sequence": 2, "payload": {"amount_cents": 50000}, ...},
    {"event_type": "MoneyWithdrawn", "sequence": 3, "payload": {"amount_cents": 30000}, ...}
  ]
}
```

Ordered by `sequence ASC` — chronological order.

```php
// Unknown account → empty list (not 404)
GET /accounts/nonexistent/events
→ 200  {"total": 0, "items": []}
```

## Implementation

### Sequence Generation

```php
public function nextSequence(string $aggregateId): int
{
    $row = $this->db->fetchOne(
        'SELECT MAX(sequence) AS seq FROM domain_events WHERE aggregate_id = ?',
        [$aggregateId],
    );
    return (int) ($row['seq'] ?? 0) + 1;
}
```

### Append Event + Update Projection (Transaction)

```php
public function appendEvent(string $aggregateId, string $eventType, array $payload): array
{
    $sequence = $this->nextSequence($aggregateId);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $this->tx->begin();
    try {
        // Append to immutable log
        $id = $this->db->insert(
            'INSERT INTO domain_events (aggregate_id, aggregate_type, event_type, payload, sequence, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$aggregateId, 'account', $eventType, json_encode($payload), $sequence, $now->format('Y-m-d H:i:s')],
        );

        // Update projection
        $this->applyProjection($aggregateId, $eventType, $payload, $sequence, $now);

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }

    return $this->db->fetchOne('SELECT * FROM domain_events WHERE id = ?', [$id]);
}
```

### Projection Logic

```php
private function applyProjection(
    string $aggregateId,
    string $eventType,
    array $payload,
    int $sequence,
    \DateTimeImmutable $now,
): void {
    $ts = $now->format('Y-m-d H:i:s');
    match ($eventType) {
        'AccountOpened' => $this->db->execute(
            'INSERT INTO account_projections (account_id, owner, balance_cents, is_open, last_sequence, updated_at)
             VALUES (?, ?, 0, 1, ?, ?)',
            [$aggregateId, $payload['owner'] ?? '', $sequence, $ts],
        ),
        'MoneyDeposited' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents + ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'MoneyWithdrawn' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents - ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'AccountClosed' => $this->db->execute(
            'UPDATE account_projections SET is_open = 0, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [$sequence, $ts, $aggregateId],
        ),
        default => null,
    };
}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| UPDATE or DELETE rows in `domain_events` | Destroys the audit trail; projections become inconsistent with history |
| No `UNIQUE(aggregate_id, sequence)` | Duplicate events corrupt the projection on replay |
| Store computed balance in `domain_events` | Derived state belongs in the projection, not the event log |
| Allow arbitrary event types | Projection logic has no handler → silent no-op or crash |
| Append event without updating projection in same transaction | Inconsistency window between event log and read model |
| No per-aggregate sequence counter | Cannot detect replay gaps or concurrent write conflicts |
