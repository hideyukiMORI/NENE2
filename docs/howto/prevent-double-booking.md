---
title: "How to prevent double-booking (reservation and capacity enforcement)"
category: database
tags: [booking, double-booking, capacity, transactions, concurrency]
difficulty: advanced
related: [resource-reservation, event-ticket-booking, distributed-locking]
---

# How to prevent double-booking (reservation and capacity enforcement)

Reservation systems have two distinct failure modes that must be handled separately:

1. **Duplicate reservation** — the same user tries to book the same slot twice
2. **Over-capacity** — the number of reservations would exceed the slot's limit

Both result in a rejected INSERT, but they require different error responses.
This guide shows how to distinguish them and guard against concurrent conflicts.

---

## 1. Schema: UNIQUE constraint + capacity column

```sql
CREATE TABLE slots (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    date     TEXT    NOT NULL,
    time     TEXT    NOT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    UNIQUE(date, time)
);

CREATE TABLE reservations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slot_id    INTEGER NOT NULL REFERENCES slots(id),
    user_id    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE(slot_id, user_id)  -- last-resort guard against duplicate reservations
);
CREATE INDEX idx_reservations_slot ON reservations (slot_id);
```

The `UNIQUE(slot_id, user_id)` constraint is the safety net — it prevents duplicate reservations
even if application logic has a bug. But it cannot tell you *why* the INSERT failed.

---

## 2. Distinguish duplicate from over-capacity with an explicit check

`DatabaseConstraintException` does not carry column-level information about which constraint
fired. To return different 409 responses, check for each condition before the INSERT:

```php
public function reserve(int $slotId, string $userId): ?Reservation
{
    // 1. Check for duplicate reservation first
    $existing = $this->db->fetchOne(
        'SELECT id FROM reservations WHERE slot_id = ? AND user_id = ?',
        [$slotId, $userId],
    );
    if ($existing !== null) {
        throw new AlreadyReservedException('User already has a reservation.');
    }

    // 2. Check remaining capacity
    $slot = $this->findSlot($slotId);
    if ($slot === null || $slot->available() === 0) {
        return null; // caller maps null → 409 slot-full
    }

    // 3. Insert — UNIQUE constraint is the final guard
    $id = $this->db->insert(
        'INSERT INTO reservations (slot_id, user_id, created_at) VALUES (?, ?, ?)',
        [$slotId, $userId, $now],
    );

    return new Reservation((int) $id, $slotId, $userId, $now);
}
```

Use a **domain exception** (`AlreadyReservedException`) for the user-facing business rule,
not `DatabaseConstraintException` — which signals a database-layer event, not a business condition.

---

## 3. Handler: map to distinct 409 responses

```php
try {
    $reservation = $this->repo->reserve($slotId, $userId);
} catch (AlreadyReservedException) {
    return $this->problems->create(
        $request, 'already-reserved', 'Already Reserved', 409,
        'You already have a reservation for this slot.',
    );
}

if ($reservation === null) {
    return $this->problems->create(
        $request, 'slot-full', 'Slot Full', 409,
        'No capacity remaining for this slot.',
    );
}
```

---

## 4. Compute availability in SQL (avoid N+1)

Count reservations in the same query as the slot fetch:

```sql
SELECT s.*, COUNT(r.id) AS reserved
FROM slots s
LEFT JOIN reservations r ON r.slot_id = s.id
WHERE s.id = ?
GROUP BY s.id
```

Then `available = capacity - reserved`. Never fetch all reservations to count them in PHP.

---

## 5. Concurrency: TOCTOU and when it matters

The explicit check-then-insert pattern has a **TOCTOU (Time-of-Check / Time-of-Use) window**:
two concurrent requests can both pass the capacity check and then both try to insert.

| Database | Behaviour |
|---|---|
| **SQLite** | Per-database write serialization: only one write runs at a time. The second INSERT hits the UNIQUE constraint and throws `DatabaseConstraintException`. Safe. |
| **PostgreSQL** under high concurrency | Two requests with different `user_id` can both pass the `available > 0` check and both INSERT, briefly exceeding capacity by 1. The UNIQUE constraint does not fire (different users). |

**Fix for PostgreSQL**: wrap the check and INSERT in a `SERIALIZABLE` transaction, or use
`SELECT ... FOR UPDATE` on the slot row to lock it before reading:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($slotId, $userId): ?Reservation {
    $db = new SqliteBookingRepository($tx);
    // All queries in this closure share the same serializable snapshot
    return $db->reserveWithinTransaction($slotId, $userId);
});
```

For SQLite, the UNIQUE constraint alone is sufficient protection.

---

## 6. Test concurrent-style scenarios

Sequential tests cannot reproduce true concurrency, but they can verify the intent:

```php
public function testLostUpdateSimulation(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];

    $alice = $this->reserve($slotId, 'alice');
    $bob   = $this->reserve($slotId, 'bob');  // arrives "simultaneously"

    self::assertSame(201, $alice->getStatusCode());
    self::assertSame(409, $bob->getStatusCode());  // slot full

    $slot = $this->decode($this->req('GET', '/slots/' . $slotId));
    self::assertSame(0, $slot['available']);
}

public function testCancelFreesCapacity(): void
{
    $slotId = $this->decode($this->createSlot(capacity: 1))['id'];
    $this->reserve($slotId, 'alice');

    $this->req('DELETE', '/slots/' . $slotId . '/reservations/alice');

    // After cancel, bob can book
    self::assertSame(201, $this->reserve($slotId, 'bob')->getStatusCode());
}
```

---

## Notes

- The UNIQUE constraint is a **last-resort guard** — it catches bugs in application logic.
  Never rely on it as the primary capacity enforcer, because it cannot distinguish duplicate-user
  from over-capacity.
- **Cancel and re-reserve**: when a user cancels, delete from `reservations`. The capacity count
  decrements automatically via the `COUNT(r.id)` query. No explicit "free slot" update is needed.
- **Idempotent cancellation**: `DELETE WHERE slot_id = ? AND user_id = ?` returns 0 rows if
  the reservation does not exist — map this to 404, not 500.
