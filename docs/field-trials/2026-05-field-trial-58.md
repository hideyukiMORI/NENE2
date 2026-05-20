# Field Trial 58 — CQRS Pattern with Separate Command and Query Handlers (cqrslog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/cqrslog/`
**NENE2 version**: 1.5.20
**Theme**: CQRS — separate `OrderCommandHandler` (write model) and `OrderQueryHandler` (read model), read-only SQL VIEW for query side, command/query objects as typed DTOs

## Overview

Built an order management API to validate the CQRS architectural pattern:
- **Write model**: `OrderCommandHandler` handles `PlaceOrderCommand` (creates order + items) and `UpdateOrderStatusCommand` (changes status). Uses normalized `orders` + `order_items` tables.
- **Read model**: `OrderQueryHandler` handles `GetOrderSummaryQuery` and `ListOrderSummariesQuery`. Reads from a denormalized `order_summary` SQL VIEW that aggregates item count and total.
- Commands and queries are typed readonly DTOs — no raw array or mixed types crossing the boundary.
- After a command, the route handler queries the read model to build the response (cross-side read acceptable in the HTTP layer).

## Endpoints Implemented

- `POST /orders` — place order (command) → returns read model summary
- `PATCH /orders/{id}/status` — update status (command) → returns read model summary
- `GET /orders` — list all orders (query, optional `?status=` filter)
- `GET /orders/{id}` — get single order summary (query)

## Test Results

15 tests, 27 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

None. The CQRS pattern composed cleanly with NENE2's public API. Typed command/query DTOs + separate handlers + SQL VIEW for the read projection worked without friction.

---

## Patterns Validated

### Command DTO (readonly, typed)

```php
final readonly class PlaceOrderCommand
{
    /** @param list<array{product: string, quantity: int, unit_price: int}> $items */
    public function __construct(
        public string $customer,
        public array  $items,
    ) {}
}
```

PHPStan level 8 enforces the `$items` shape annotation.

### Separate write handler (command side)

```php
final readonly class OrderCommandHandler
{
    public function place(PlaceOrderCommand $command, string $now): int { ... }
    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool { ... }
}
```

No SELECT queries in the command handler. Returns the new ID or a success bool — not a view model.

### SQL VIEW for the read projection

```sql
CREATE VIEW IF NOT EXISTS order_summary AS
SELECT
    o.id, o.customer, o.status, o.created_at,
    COUNT(oi.id) AS item_count,
    SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

The read handler queries `order_summary` instead of raw tables. This gives a pre-aggregated denormalized view without maintaining a separate projection table. For SQLite, views are synchronous and always consistent with writes.

### Cross-side read after command (HTTP layer pattern)

```php
$orderId = $this->commands->place($command, $now);
$summary = $this->queries->get(new GetOrderSummaryQuery($orderId));
return $this->json->create($summary->toArray(), 201);
```

The HTTP handler crosses from the write to the read side to build the 201 response. This is acceptable at the HTTP layer — CQRS doesn't prohibit reading from the read model in a response to a command, it prohibits mixing read and write logic *within* the same handler class.

### Status filter on the query side

```php
if ($query->status !== null) {
    $rows = $this->executor->fetchAll(
        'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC',
        [$query->status],
    );
}
```

Optional filter passed cleanly through `ListOrderSummariesQuery::$status = null` typed nullable property.

---

## NENE2 Changes Required

None. The CQRS pattern is a pure application architecture concern composable from existing NENE2 public API.
