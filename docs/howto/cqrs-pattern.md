# How-to: CQRS Pattern

> **FT reference**: FT233 (`NENE2-FT/cqrslog`) — CQRS Pattern API

Demonstrates Command Query Responsibility Segregation (CQRS): the write side accepts
Commands and mutates the write model; the read side accepts Queries and reads from a
denormalised read model (SQL VIEW). The two sides share the same SQLite database but
have separate handler classes, separate model objects, and no shared state.

---

## Core CQRS concepts

| Concept | Description |
|---------|-------------|
| **Command** | An intent to change state — `PlaceOrderCommand`, `UpdateOrderStatusCommand` |
| **Query** | A request for data — `GetOrderSummaryQuery`, `ListOrderSummariesQuery` |
| **CommandHandler** | Executes a command against the write model (normalised tables) |
| **QueryHandler** | Executes a query against the read model (denormalised view) |
| **Write model** | Normalised tables optimised for transactional writes |
| **Read model** | Denormalised view optimised for query output shape |

---

## Routes

| Method  | Path                  | Side  | Description                    |
|---------|-----------------------|-------|--------------------------------|
| `POST`  | `/orders`             | Write | Place a new order (command)    |
| `PATCH` | `/orders/{id}/status` | Write | Update order status (command)  |
| `GET`   | `/orders`             | Read  | List order summaries (query)   |
| `GET`   | `/orders/{id}`        | Read  | Get one order summary (query)  |

---

## Command objects (write side)

Commands are immutable value objects that carry validated data into the handler:

```php
final readonly class PlaceOrderCommand
{
    /**
     * @param list<array{product: string, quantity: int, unit_price: int}> $items
     */
    public function __construct(
        public string $customer,
        public array  $items,
    ) {
    }
}

final readonly class UpdateOrderStatusCommand
{
    public function __construct(
        public int    $orderId,
        public string $newStatus,
    ) {
    }
}
```

Commands hold no business logic — they are typed containers for the controller's
validated input. Using `readonly` prevents mutation after construction.

---

## Command handler (write model)

`OrderCommandHandler` owns all mutations. It writes to normalised tables:

```php
final readonly class OrderCommandHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function place(PlaceOrderCommand $command, string $now): int
    {
        $orderId = $this->executor->insert(
            'INSERT INTO orders (customer, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$command->customer, 'pending', $now, $now],
        );

        foreach ($command->items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product'], $item['quantity'], $item['unit_price']],
            );
        }

        return $orderId;
    }

    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool
    {
        $affected = $this->executor->execute(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$command->newStatus, $now, $command->orderId],
        );

        return $affected > 0;
    }
}
```

The handler returns primitive values (`int` orderId, `bool` success) — not read model
objects. After a command succeeds, the controller re-queries the read side to get the
response shape.

---

## Query objects (read side)

Queries are typed wrappers for query parameters:

```php
final readonly class GetOrderSummaryQuery
{
    public function __construct(public int $orderId) {}
}

final readonly class ListOrderSummariesQuery
{
    public function __construct(public ?string $status) {}
}
```

Wrapping query parameters in objects makes the query handler's contract explicit and
avoids primitive-obsession across handler signatures.

---

## Query handler (read model)

`OrderQueryHandler` reads from `order_summary`, a SQL VIEW that denormalises the
join at the DB layer:

```php
final readonly class OrderQueryHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function get(GetOrderSummaryQuery $query): ?OrderSummary
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM order_summary WHERE id = ?',
            [$query->orderId],
        );

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @return list<OrderSummary> */
    public function list(ListOrderSummariesQuery $query): array
    {
        if ($query->status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC',
                [$query->status],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary ORDER BY created_at DESC',
                [],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OrderSummary
    {
        return new OrderSummary(
            id:         (int) $row['id'],
            customer:   (string) $row['customer'],
            status:     (string) $row['status'],
            createdAt:  (string) $row['created_at'],
            itemCount:  (int) $row['item_count'],
            totalCents: (int) ($row['total_cents'] ?? 0),
        );
    }
}
```

`OrderSummary` is a read model DTO — it is never written to; it only represents a
query result. Keeping it separate from any write-side `Order` entity prevents
read-side concerns leaking into the write model.

---

## Read model: SQL VIEW as denormalised projection

The read model is a SQLite `VIEW` that pre-computes the join and aggregation:

```sql
CREATE VIEW IF NOT EXISTS order_summary AS
SELECT
    o.id,
    o.customer,
    o.status,
    o.created_at,
    COUNT(oi.id)                     AS item_count,
    SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

The view provides a stable query surface — the query handler doesn't need to know
about the normalised `orders`/`order_items` join. If the write model changes its
table structure, only the view definition needs updating, not the query handler.

`total_cents` stores monetary amounts as integer cents (no floating-point rounding
errors). `?? 0` guards against `NULL` when no items exist.

---

## Write model schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product    TEXT    NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price INTEGER NOT NULL
);
```

Write model is normalised: `orders` + `order_items` in a 1:N relationship. No
computed columns — the read projection is in the view.

---

## Controller: wiring commands and queries

After a write command succeeds, the controller uses the read side to build the response:

```php
private function placeOrder(ServerRequestInterface $request): ResponseInterface
{
    // ... validate input ...
    $orderId = $this->commands->place(new PlaceOrderCommand($customer, $items), $now);

    // Re-query via read model to get the response shape
    $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));

    return $this->json->create($summary->toArray(), 201);
}
```

This "command then query" pattern keeps the write side ignorant of response shape and
ensures the response always reflects the view's projection (including computed fields
like `total_cents`).

Item validation before the command:

```php
foreach ($rawItems as $item) {
    if (!is_array($item)) continue;

    $product   = isset($item['product']) && is_string($item['product']) ? trim($item['product']) : '';
    $quantity  = isset($item['quantity']) && is_int($item['quantity']) && $item['quantity'] > 0 ? $item['quantity'] : 0;
    $unitPrice = isset($item['unit_price']) && is_int($item['unit_price']) && $item['unit_price'] >= 0 ? $item['unit_price'] : -1;

    if ($product === '' || $quantity === 0 || $unitPrice < 0) {
        return $this->json->create(['error' => 'each item needs product, quantity>0, unit_price>=0'], 422);
    }
    $items[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}
```

`is_int()` strict check on `quantity` and `unit_price` rejects floats and strings from
JSON. `unit_price >= 0` allows zero (free items); `quantity > 0` requires at least one.

---

## When to use CQRS

CQRS adds structural overhead. Use it when:

- The read and write data shapes diverge significantly (e.g. list needs aggregates the
  write model doesn't store)
- Read load far exceeds write load and you want to scale them independently
- The domain has complex write invariants (transactions, validation, domain events) that
  should be isolated from read optimisations
- You are building toward event sourcing (CQRS pairs naturally with event-sourced write
  models)

Avoid CQRS when:
- The read and write shapes are identical (a simple CRUD endpoint)
- The codebase is small and the indirection outweighs the clarity benefit
- The team is unfamiliar with the pattern (introduces cognitive overhead)

---

## Related howtos

- [`event-sourcing.md`](event-sourcing.md) — CQRS write side backed by an event store
- [`approval-workflow.md`](approval-workflow.md) — state machine for order status transitions
- [`transactions.md`](transactions.md) — wrapping command writes in a transaction
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — bulk commands with per-item results
