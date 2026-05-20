# Field Trial 45 — Event Sourcing with Append-Only Log and Projections (eventstore)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/eventstore/`
**NENE2 version**: 1.5.17
**Theme**: Immutable event log, per-aggregate sequence numbers, write-side (event append) + read-side (projection) separation, `ON CONFLICT DO UPDATE` for projection upserts

## Overview

Built a bank account event-sourcing API. Domain events (`AccountOpened`, `MoneyDeposited`, `MoneyWithdrawn`, `AccountClosed`) are stored immutably in an append-only `domain_events` table. A parallel `account_projections` read model is updated atomically with each append via `transactional()`. The projection provides current account state without replaying events on every read.

## Endpoints Implemented

- `POST /accounts` — open an account (appends `AccountOpened` event, creates projection)
- `POST /accounts/{id}/events` — append any valid event type with an arbitrary JSON payload
- `GET /accounts/{id}/events` — full event log for an account (chronological order)
- `GET /accounts/{id}` — current projection (balance, open/closed state)
- `GET /accounts` — list all account projections

## Test Results

16 tests, 26 assertions — pass after 1 PHPStan fix.

---

## Frictions Found

### Friction 1 — `public array $payload` needs explicit PHPDoc for PHPStan level 8 [LOW]

**Symptom**: PHPStan error "has parameter $payload with no value type specified in iterable type array".

**Root cause**: In a `readonly` class constructor, bare `array` without a type annotation triggers PHPStan's `missingType.iterableValue` rule at level 8.

**Fix**: Add `@param array<string, mixed> $payload` PHPDoc comment above the constructor.

```php
/** @param array<string, mixed> $payload */
public function __construct(
    ...
    public array  $payload,
    ...
```

**NENE2 impact**: None — this is a PHPStan level 8 PHP typing requirement, not a framework issue. Noted as a pattern to follow.

---

## Patterns Validated

### Append-only event store schema

```sql
CREATE TABLE IF NOT EXISTS domain_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id TEXT NOT NULL,
    aggregate_type TEXT NOT NULL,
    event_type TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    sequence INTEGER NOT NULL,
    occurred_at TEXT NOT NULL,
    UNIQUE(aggregate_id, sequence)
);
```

The `UNIQUE(aggregate_id, sequence)` constraint prevents duplicate sequence numbers from concurrent appends (would cause a 500 on conflict, acceptable for this FT).

### Per-aggregate sequence with MAX+1

```php
$seqRow   = $tx->fetchOne(
    'SELECT MAX(sequence) AS max_seq FROM domain_events WHERE aggregate_id = ?',
    [$aggregateId],
);
$sequence = isset($seqRow['max_seq']) ? ((int) $seqRow['max_seq']) + 1 : 1;
```

Inside the transaction, `MAX(sequence)` gives the current highest sequence. New aggregates return `null` (converted to 0+1=1).

### Projection upsert with ON CONFLICT DO UPDATE

```sql
INSERT INTO account_projections (account_id, owner, balance_cents, is_open, last_sequence, updated_at)
VALUES (?, ?, 0, 1, ?, ?)
ON CONFLICT(account_id) DO UPDATE
    SET owner = excluded.owner, last_sequence = excluded.last_sequence, updated_at = excluded.updated_at
```

SQLite's `ON CONFLICT … DO UPDATE` (upsert) allows idempotent projection writes. If `AccountOpened` is replayed, it updates rather than inserting a duplicate.

### Atomic append + projection update

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use (...): DomainEvent {
    // 1. Get next sequence
    // 2. Insert event
    // 3. Update projection
    return $event;
});
```

Both the event insert and the projection update happen in one transaction. If either fails, both are rolled back — the log and projection stay consistent.

### Pattern-matched projection application

```php
match ($eventType) {
    'AccountOpened'  => $this->projectAccountOpened(...),
    'MoneyDeposited' => $this->projectMoneyDeposited(...),
    'MoneyWithdrawn' => $this->projectMoneyWithdrawn(...),
    'AccountClosed'  => $this->projectAccountClosed(...),
    default          => null, // unknown events stored but not projected
};
```

Unknown event types are stored in the log but ignored by the projector. This allows forward compatibility (new event types added later won't break the existing projector).

---

## NENE2 Changes Required

None — the `DomainEvent` PHPDoc fix is a PHP typing issue, not a framework issue.

No version bump needed.
