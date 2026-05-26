# How-to: Transaction Scope Pattern

> **FT reference**: FT253 (`NENE2-FT/txlog`) — Database transaction boundaries: atomic order-with-inventory

Demonstrates the correct pattern for `DatabaseTransactionManagerInterface::transactional()`
in NENE2. Repositories must be instantiated **inside** the callback with the
transaction-scoped executor — pre-injected repositories operate on a different connection
and their writes are **not** rolled back if the transaction fails.

---

## Routes

| Method | Path      | Description                        |
|--------|-----------|------------------------------------|
| `POST` | `/orders` | Place an order (atomic inventory deduction + order create) |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS inventory (
    product_id   INTEGER PRIMARY KEY,
    product_name TEXT    NOT NULL,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    status     TEXT    NOT NULL DEFAULT 'placed',
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL
);
```

`CHECK(stock >= 0)` is a DB-level safety net that prevents stock from going negative
even if application logic has a bug. The application also validates stock before
decrementing, so in normal operation the constraint is never triggered.

---

## The executor scope trap

`DatabaseTransactionManagerInterface::transactional()` opens a transaction and passes a
**transaction-scoped executor** to the callback. Writes made through this executor are
part of the transaction and are rolled back if an exception is thrown.

**❌ Wrong: repository injected before the transaction**

```php
final class OrderService
{
    public function __construct(
        private readonly InventoryRepository $inventory, // uses outer executor
        private readonly OrderRepository     $orders,    // uses outer executor
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($txExecutor) use ($items): int {
            // BUG: $this->inventory and $this->orders use the outer executor,
            // not $txExecutor. Their writes are on a different connection.
            // If the transaction rolls back, their changes are NOT undone.
            foreach ($items as $item) {
                $this->inventory->decrement($item['product_id'], $item['quantity']);
            }
            return $this->orders->create($items);
        });
    }
}
```

**✅ Correct: repositories created inside the callback**

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
        // Only inject the transaction manager, not the repositories.
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Repositories are instantiated with the transaction-scoped executor.
            // All reads and writes go through the same connection, in the same transaction.
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // Throws InsufficientStockException → triggers rollback
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

The transaction-scoped `$executor` passed by `transactional()` shares the same PDO
connection and transaction context. Creating repositories inside the callback with
this executor ensures all their writes participate in the same atomic transaction.

---

## Inventory decrement with application-level check

```php
public function decrement(int $productId, int $qty): void
{
    $current = $this->getStock($productId);
    if ($current < $qty) {
        throw new InsufficientStockException($productId, $qty, $current);
    }
    $this->executor->execute(
        'UPDATE inventory SET stock = stock - ? WHERE product_id = ?',
        [$qty, $productId],
    );
}
```

The read-then-write pattern (`getStock()` + `UPDATE`) is not atomic on its own —
a concurrent request could read the same stock and both succeed. For production use,
wrap this in a `SELECT ... FOR UPDATE` (MySQL) or use `UPDATE ... WHERE stock >= ?`
as an optimistic check:

```sql
UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?
-- then check affected rows == 1; if 0, throw InsufficientStockException
```

SQLite does not support `SELECT FOR UPDATE`, so the `CHECK(stock >= 0)` constraint
catches concurrent races at the DB level — the second update would throw a constraint
violation, which propagates as an exception and triggers rollback.

---

## Multi-item atomicity: partial failure triggers full rollback

```php
foreach ($items as $item) {
    $inventory->decrement($item['product_id'], $item['quantity']); // may throw
}
return $orders->create($items); // only reached if all decrements succeed
```

If the **first** item fails, no inventory changes are made. If the **last** item fails,
all prior decrements are rolled back. The test suite verifies both cases:

```php
// Widget: 10 stock; Gadget: 1 stock. Order [Widget×3, Gadget×5] fails on Gadget.
$this->assertSame(10, $inventory->getStock(1)); // Widget restored to 10
$this->assertSame(0, $orders->count());          // No order created
```

---

## Exception → rollback → 422 mapping

The exception thrown inside `transactional()` propagates to the caller:

```php
try {
    $orderId = $this->service->placeOrder($items);
    return $this->json->create(['order_id' => $orderId, 'status' => 'placed'], 201);
} catch (InsufficientStockException $e) {
    return $this->problems->create(
        $request,
        'insufficient-stock',
        'Insufficient stock.',
        422,
        $e->getMessage(),
    );
}
```

`transactional()` catches the exception, calls `ROLLBACK`, then re-throws. The caller
catches the re-thrown exception and maps it to a Problem Details response.

---

## Input validation: strict `is_int` for quantities

```php
foreach ($body['items'] as $i => $item) {
    if (
        !is_array($item) ||
        !isset($item['product_id'], $item['quantity']) ||
        !is_int($item['product_id']) ||
        !is_int($item['quantity']) ||
        $item['quantity'] < 1
    ) {
        return $this->problems->create(
            $request,
            'validation-failed',
            'Validation failed.',
            422,
            null,
            ['errors' => [['field' => "items.{$i}", 'code' => 'invalid', ...]]],
        );
    }
}
```

`is_int()` rejects JSON floats (`1.0`) and strings (`"3"`). PHP's JSON decoder converts
JSON integer values to PHP `int`, so `is_int()` works correctly for JSON input.
Use `is_numeric()` only for query string parameters (which are always strings).

---

## `transactional()` usage summary

| Do | Don't |
|---|---|
| Create repositories inside the callback | Inject repositories at class construction |
| Pass `$executor` from the callback to new repositories | Use `$this->executor` injected in the constructor |
| Let exceptions propagate to trigger rollback | Catch exceptions silently inside the callback |
| Return a value from the callback | Return `void` when you need the inserted ID |

---

## Related howtos

- [`transactions.md`](transactions.md) — `DatabaseTransactionManagerInterface` API reference
- [`use-transactions.md`](use-transactions.md) — adding transactions to an existing endpoint
- [`budget-tracking.md`](budget-tracking.md) — transfer funds with transaction (two-account balance update)
- [`order-management.md`](order-management.md) — full order lifecycle (create, status, cancel)
- [`optimistic-locking.md`](optimistic-locking.md) — race condition prevention without transactions
