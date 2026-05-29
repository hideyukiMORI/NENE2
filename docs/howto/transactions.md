---
title: "Database Transactions"
category: database
tags: [transactions, atomic, rollback, database]
difficulty: beginner
related: [transaction-scope-pattern, use-transactions]
---

# Database Transactions

Use `DatabaseTransactionManagerInterface::transactional()` for atomic multi-step operations.
If any step throws, all changes in the callback are rolled back automatically.

## The Critical Rule: Instantiate Repositories Inside the Callback

> **Warning:** Repositories injected at construction time use a *different* PDO connection and execute **outside the transaction**. Rollbacks will not undo their changes.

`PdoConnectionFactory` creates a new connection each time it is called. When `transactional()` opens a transaction, it opens it on a *new* connection. Any repository that was created before this call (e.g., injected via constructor DI) holds a different connection — one that is not part of the transaction.

### Correct pattern

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // ✅ Instantiate repositories INSIDE the callback with the tx-scoped executor
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // throws InsufficientStockException → rollback triggered automatically
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

### Wrong pattern (changes NOT rolled back)

```php
// ❌ These repos hold a different connection — not part of the transaction
public function __construct(
    private readonly InventoryRepository $inventory,
    private readonly OrderRepository $orders,
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← different connection, outside transaction!
        }
        return $this->orders->create($items); // ← same problem
        // If an exception is thrown, $this->inventory changes are NOT undone
    });
}
```

This is a silent failure: the code compiles, PHPStan cannot detect it, and tests may pass unless they specifically verify rollback behavior.

---

## Rollback Behavior

`transactional()` catches `Throwable`, rolls back, then re-throws. The caller receives the original exception.

```php
try {
    $this->orderService->placeOrder($items);
} catch (InsufficientStockException $e) {
    // All inventory decrements from this call are undone
    // No order was created
}
```

---

## Pre-Validation + Atomic Operation Pattern

The fail-fast pattern above stops at the first out-of-stock item, leaving the client unaware of other failures.
When UX matters, collect all errors first, then execute atomically:

```php
public function placeOrder(array $items): int
{
    // Phase 1: validate all items outside the transaction (read-only)
    $errors = [];
    foreach ($items as $item) {
        $stock = $this->getStockSnapshot($item['product_id']);
        if ($stock < $item['quantity']) {
            $errors[] = [
                'product_id' => $item['product_id'],
                'requested'  => $item['quantity'],
                'available'  => $stock,
            ];
        }
    }

    if ($errors !== []) {
        throw new InsufficientStockException($errors);
    }

    // Phase 2: atomic decrement — still use the tx-scoped executor inside
    return $this->tx->transactional(function ($executor) use ($items): int {
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            // DB CHECK constraint is the final safety net for races
            $inventory->decrement($item['product_id'], $item['quantity']);
        }

        return $orders->create($items);
    });
}
```

**Trade-offs:**
- The read in Phase 1 is not part of the transaction, so a concurrent request may deplete stock between Phase 1 and Phase 2. The database `CHECK (stock >= 0)` constraint (or `decrement()` throwing on negative stock) catches this race.
- For most applications this is acceptable. For strict correctness, use `SELECT ... FOR UPDATE` or a serializable isolation level (not available in SQLite).

---

## Testing Rollback Correctness

Always verify that inventory is unchanged after a failed order:

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// Widget decrement would succeed, but Gadget fails → both must roll back
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],
    ['product_id' => 2, 'quantity' => 5], // only 1 in stock
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1), 'Widget must be rolled back to 10');
assertSame(0, $this->orders->count(), 'No order created');
```

Unit tests that mock repositories cannot catch this class of bug — only integration tests that share the same SQLite/MySQL connection can verify rollback correctness.

---

## Laravel vs NENE2

Laravel's `DB::transaction()` works with injected models because it transparently shares one connection across the request. NENE2's `PdoConnectionFactory` returns a new connection on each call — a deliberate design choice for testability and explicit connection control. The consequence is that the callback-scoped executor pattern is **required** in NENE2.
