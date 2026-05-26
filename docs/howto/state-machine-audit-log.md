# How-to: State Machine with Audit Log

> **FT reference**: FT237 (`NENE2-FT/statemachinelog`) — State Machine with Audit Log
> **VULN**: FT237 — security / vulnerability assessment (V-01 through V-10)

Demonstrates a state machine API where every transition is recorded in an immutable
audit log table. The current status lives on the order; the full history lives in a
separate `order_transitions` table. `InvalidTransitionException` provides structured
409 responses with `from` and `to` context.

---

## Routes

| Method | Path                            | Description                                   |
|--------|---------------------------------|-----------------------------------------------|
| `POST` | `/orders`                       | Create an order (starts as `draft`)           |
| `GET`  | `/orders/{id}`                  | Get current order state                       |
| `POST` | `/orders/{id}/transitions`      | Apply a state transition                      |
| `GET`  | `/orders/{id}/transitions`      | List full transition history                  |

---

## State machine: allowed transitions

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

Terminal states (`approved`, `rejected`, `cancelled`) return an empty list — they
cannot transition further.

---

## InvalidTransitionException → 409 with context

When a caller requests an illegal transition, the exception carries the from and to
states as structured data for the error response:

```php
final class InvalidTransitionException extends \RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            sprintf('Transition from "%s" to "%s" is not allowed.', $from->value, $to->value)
        );
    }
}
```

The controller includes `from` and `to` in the Problem Details extension:

```php
try {
    $updated = $this->repo->transition($id, $targetEnum, $now);
} catch (InvalidTransitionException $e) {
    return $this->problems->create(
        $request,
        'invalid-transition',
        'Invalid State Transition',
        409,
        $e->getMessage(),
        ['from' => $order->status->value, 'to' => $targetEnum->value],
    );
}
```

Response:
```json
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "title": "Invalid State Transition",
  "status": 409,
  "detail": "Transition from \"approved\" to \"submitted\" is not allowed.",
  "from": "approved",
  "to": "submitted"
}
```

`from` and `to` let the caller understand exactly which transition was rejected without
parsing the `detail` string.

---

## Transition audit log: two-write pattern

Every successful transition updates the order status AND inserts a log record atomically:

```php
public function transition(int $orderId, OrderStatus $targetStatus, string $now): Order
{
    $order = $this->findById($orderId);

    if (!$order->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($order->status, $targetStatus);
    }

    // Update current status
    $this->executor->execute(
        'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $now, $orderId],
    );

    // Append to audit log
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **Atomicity note**: Without a wrapping transaction, a failure between the UPDATE and
> INSERT leaves the order in the new state with no log record. Wrap both statements in a
> transaction for true atomicity. SQLite's WAL mode makes this safe under concurrent access.

---

## Schema: order state + transition history

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_transitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL,
    from_status     TEXT    NOT NULL,
    to_status       TEXT    NOT NULL,
    transitioned_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);
```

`order_transitions` is append-only by design — no UPDATE or DELETE endpoint exists for
it. The full transition history is preserved for auditing.

---

## Transition history response

```json
{
  "order_id": 1,
  "transitions": [
    {"id": 1, "order_id": 1, "from_status": "draft", "to_status": "submitted", "transitioned_at": "2026-05-27 10:00:00"},
    {"id": 2, "order_id": 1, "from_status": "submitted", "to_status": "approved", "transitioned_at": "2026-05-27 11:00:00"}
  ]
}
```

The list is ordered by `id ASC` so history is chronological.

---

## VULN — Security assessment (FT237)

### V-01 — No authentication on any endpoint

**Attack**: Create orders and apply transitions without credentials.

```bash
curl -s -X POST http://localhost:8080/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**Observed**: `200 OK` — no token required. Anyone can approve or cancel any order.

**Verdict**: **EXPOSED** (by design for FT237 demo). Add authentication and
authorisation: gate transitions behind a role (submitter vs reviewer), and restrict
each order to its owner.

---

### V-02 — Invalid status value

**Attack**: Send an unknown status string.

```json
{"status": "hacked"}
{"status": ""}
```

**Observed**: `OrderStatus::tryFrom('hacked')` = `null` → `422` with an error listing
all valid statuses.

**Verdict**: **BLOCKED** — backed enum `tryFrom()` rejects unknown values.

---

### V-03 — Illegal transition (terminal state → active)

**Attack**: Try to transition from `approved` or `cancelled` to another status.

```json
{"status": "submitted"}   // from approved
{"status": "draft"}       // from cancelled
```

**Observed**: `canTransitionTo()` returns `false` → `InvalidTransitionException` →
`409 Conflict` with `from`/`to` context in the response body.

**Verdict**: **BLOCKED** — state machine enforces all transition rules at the domain level.

---

### V-04 — Non-numeric order ID

**Attack**: Pass a string or float as `{id}`.

```
GET /orders/abc
GET /orders/1.5
```

**Observed**: `(int) 'abc'` = 0, `(int) '1.5'` = 1. For `abc`, `findById(0)` returns
`null` → `404 Not Found`. For `1.5`, if order 1 exists it is returned — silent truncation.

**Verdict**: **PARTIALLY BLOCKED** — non-numeric strings resolve to 404. Floats are
silently truncated. Add `ctype_digit()` guard for strict validation.

---

### V-05 — Transition history is not scoped to the caller

**Attack**: Read another user's transition history.

```
GET /orders/1/transitions
```

**Observed**: `200 OK` — full history returned without any ownership or authentication
check. The history reveals who submitted, approved, or cancelled the order (via
timestamps, though no actor is recorded).

**Verdict**: **EXPOSED** — no ownership model. Add a `created_by` field to orders and
restrict history reads to the owner or authorised reviewers.

---

### V-06 — SQL injection via `status` body field

**Attack**: Embed SQL metacharacters in the `status` value.

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**Observed**:
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → `422` before any SQL.
2. Even if the check were bypassed, the status is passed as a parameterised `?` value.

**Verdict**: **BLOCKED** — dual layer: enum allowlist + parameterised queries.

---

### V-07 — Transition to the same status (idempotency)

**Attack**: Send a transition to the current status.

```json
// Order is already 'submitted'
{"status": "submitted"}
```

**Observed**: `allowedTransitions()` for `submitted` is `[approved, rejected, cancelled]`
— `submitted` is not in the list. `canTransitionTo(submitted)` returns `false` →
`409 Conflict`.

**Verdict**: **BLOCKED** — self-transitions are implicitly rejected by the state machine.

---

### V-08 — Concurrent transitions on the same order

**Attack**: Send two simultaneous transition requests for the same order.

```
POST /orders/1/transitions {"status":"approved"}  // concurrent request A
POST /orders/1/transitions {"status":"rejected"}  // concurrent request B
```

**Observed**: Both requests fetch the order (status = `submitted`) before either
UPDATE runs. Both see `canTransitionTo()` = true. Both UPDATE — the second UPDATE
overwrites the first. One transition log record per request is inserted, but the order
ends in whichever status ran last. The history shows both transitions, which is
inconsistent (e.g., `submitted → approved`, then `submitted → rejected`).

**Verdict**: **EXPOSED** — wrap the `findById` + `canTransitionTo` + `UPDATE` +
`INSERT` sequence in a single transaction to prevent race conditions.

---

### V-09 — Whitespace-only title

**Attack**: Create an order with a blank title.

```json
{"title": "   "}
```

**Observed**: `trim($body['title'])` reduces to `""` → `title === ''` check fires →
`422 Unprocessable Entity`.

**Verdict**: **BLOCKED** — `trim()` before empty-string check handles whitespace-only input.

---

### V-10 — Unbounded title length

**Attack**: Create an order with a very long title.

```json
{"title": "A".repeat(100_000)}
```

**Observed**: No length limit is enforced — very long titles are stored in the `TEXT`
column without restriction.

**Verdict**: **EXPOSED** — add a length guard:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
```

---

## VULN summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| V-01 | No authentication | EXPOSED |
| V-02 | Invalid status value | BLOCKED |
| V-03 | Illegal transition from terminal state | BLOCKED |
| V-04 | Non-numeric order ID | PARTIALLY BLOCKED |
| V-05 | Transition history not scoped to caller | EXPOSED |
| V-06 | SQL injection via status body | BLOCKED |
| V-07 | Self-transition (same status) | BLOCKED |
| V-08 | Concurrent transitions race condition | EXPOSED |
| V-09 | Whitespace-only title | BLOCKED |
| V-10 | Unbounded title length | EXPOSED |

**Real vulnerabilities to fix before production**:
1. **V-01 / V-05** — Add authentication and authorisation (ownership scoping)
2. **V-08** — Wrap transition in a transaction
3. **V-10** — Add title length limit
4. **V-04** — Add `ctype_digit()` guard for ID parameters

---

## Related howtos

- [`approval-workflow.md`](approval-workflow.md) — enum-based state machine with separate action endpoints
- [`audit-trail.md`](audit-trail.md) — append-only audit log patterns
- [`transactions.md`](transactions.md) — wrapping multi-write sequences in a transaction
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR prevention
