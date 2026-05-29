---
title: Use Database Transactions
category: database
tags: [transactions, atomicity, rollback]
difficulty: intermediate
related: [transactions, optimistic-locking]
---

# Use Database Transactions

This guide explains how to perform atomic multi-step operations using
`DatabaseTransactionManagerInterface` in NENE2.

**Prerequisite**: You have a repository backed by `DatabaseQueryExecutorInterface`.
If not, start with [Add a database-backed endpoint](./add-database-endpoint.md).

> **🚫 SQLite `:memory:` is incompatible with `transactional()`.**
>
> `PdoDatabaseTransactionManager` opens a *new* connection per call. Each
> `:memory:` connection points at a *different* empty in-memory database, so
> the executor and the transaction see different data and rollbacks have no
> effect on the executor's view. The symptom is silent: queries return `null`
> mid-callback, or rollbacks fail to undo writes the test made through a
> separate executor.
>
> Use a **file-based SQLite** database in tests (see "[Test with a file-based
> SQLite database](#test-with-a-file-based-sqlite-database)" below).
> `Nene2\Testing\DatabaseTestKit::sqlite(':memory:')` rejects `:memory:` with
> `InvalidArgumentException` to make this fail-fast.

---

## Why transactions in NENE2

`DatabaseTransactionManagerInterface` wraps multiple SQL statements in a single transaction:
either all succeed (commit) or all are rolled back on any `Throwable`.

The interface has one method:

```php
public function transactional(callable $callback): mixed;
```

The callback receives a **fresh** `DatabaseQueryExecutorInterface` bound to the open
transaction. **This executor is different from the one you inject at construction time.**

---

## The transactional repository pattern

> **Warning — do not reuse injected repositories inside the callback.**
>
> Repositories injected at construction time hold a **different connection** than the one
> the transaction runs on. Using them inside the callback means their queries execute
> outside the transaction: rollbacks will not undo those changes, and uncommitted
> rows written inside the callback may not be visible to them.
>
> This mistake compiles and tests may pass — the bug only surfaces under concurrent
> writes or when you rely on rollback behaviour.

Because the callback provides its own executor, you must **instantiate repository classes
inside the callback** using the executor the callback provides.

```php
<?php

declare(strict_types=1);

namespace MyApp\Order;

use MyApp\Product\ProductNotFoundException;
use MyApp\Product\SqliteProductRepository;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;

final class CreateOrderUseCase
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $transactionManager,
    ) {}

    public function execute(int $productId, int $qty): Order
    {
        return $this->transactionManager->transactional(
            function (DatabaseQueryExecutorInterface $tx) use ($productId, $qty): Order {
                // Must instantiate concrete classes here — the $tx executor is bound to
                // this transaction's connection. Injected instances use a different executor.
                $products = new SqliteProductRepository($tx);
                $orders   = new SqliteOrderRepository($tx);

                $product = $products->findById($productId)
                    ?? throw new ProductNotFoundException($productId);

                $products->decrementStock($productId, $qty);

                return $orders->save($product->price * $qty, [
                    new OrderItem($productId, $qty, $product->price),
                ]);
            },
        );
    }
}
```

### Why not reuse injected repositories?

`PdoDatabaseTransactionManager::transactional()` opens a **new connection** via
`DatabaseConnectionFactoryInterface::create()` and begins a transaction on it.
The callback's executor is bound to that specific connection.

An injected `SqliteProductRepository` holds a separate `PdoDatabaseQueryExecutor` that
lazily opens its own connection on first use. Queries through the injected repository
run on that other connection — outside the transaction — so a rollback will not undo
them, and an insert through the injected repository may not see uncommitted rows from
the callback.

---

## Wire it up in your front controller

You need two separate objects:

| Object | Purpose |
|---|---|
| `PdoDatabaseQueryExecutor` | Non-transactional reads (e.g. `GET /products`) |
| `PdoDatabaseTransactionManager` | Wraps multi-step writes in a transaction |

Both share the same `PdoConnectionFactory`:

```php
$connectionFactory = new PdoConnectionFactory($dbConfig);

$executor  = new PdoDatabaseQueryExecutor($connectionFactory);  // for read repositories
$txManager = new PdoDatabaseTransactionManager($connectionFactory); // for use cases

$products = new SqliteProductRepository($executor);  // used by GET /products
$createOrder = new CreateOrderUseCase($txManager);   // uses $tx internally
```

---

## Test with a file-based SQLite database

In-memory SQLite (`sqlite::memory:`) creates a **separate database per connection**, so
`PdoDatabaseTransactionManager` (which opens a new connection per `transactional()` call)
would not see rows written by the `PdoDatabaseQueryExecutor` and vice versa.

Use a **temp file** instead. `Nene2\Testing\DatabaseTestKit` wires the executor and
the transaction manager onto the same file in one line:

```php
use Nene2\Testing\DatabaseTestKit;

protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/' . uniqid('test-', true) . '.sqlite';

    // Seed schema on a throwaway connection, then close it before the kit opens its own.
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo);

    $this->kit = DatabaseTestKit::sqlite($this->dbFile);
    // $this->kit->queryExecutor       — for read repositories
    // $this->kit->transactionManager  — for use cases
    // $this->kit->connectionFactory   — if you need to build extra executors
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

The kit lives at `Nene2\Testing\DatabaseTestKit` (ADR 0012, public API). It
wires `PdoConnectionFactory` + `PdoDatabaseQueryExecutor` +
`PdoDatabaseTransactionManager` internally, all sharing the same file, so
tests do not need to reference any `@internal` class by name. Both
`DatabaseTestKit::sqlite(':memory:')` and the underlying configuration
combinations are blocked at the factory.

> **`DatabaseConfig::sqlite(string $path)`** is the equivalent shortcut when
> you want to keep wiring explicit (e.g. to inject your own subclass of
> `PdoConnectionFactory`). It replaces the 9-argument `new DatabaseConfig(...)`
> form shown in older guides.

---

## Verifying rollback behaviour

A use case that wraps multiple writes is only correct if the rollback path
**actually undoes** preceding writes. A test that only happy-paths the success
case will pass even when the use case forgets to use `$tx` — `$this->products`
will execute outside the transaction and silently commit. The rollback test is
the one that catches the bug.

### Unit-level rollback

Drive the use case directly with `DatabaseTestKit` and assert on the database
state after the exception:

```php
public function testRollbackUndoesStockDecrementWhenOrderInsertFails(): void
{
    $kit = DatabaseTestKit::sqlite($this->dbFile);
    $kit->queryExecutor->execute(/* seed: product 1 with stock=10 */);

    $useCase = new CreateOrderUseCase($kit->transactionManager);

    try {
        // Pass a quantity that succeeds at decrementStock but triggers a
        // unique-constraint violation in orders.save (e.g. duplicate idempotency key).
        $useCase->execute(productId: 1, qty: 3, idempotencyKey: $existingKey);
        self::fail('Expected order creation to fail.');
    } catch (DatabaseConstraintException) {
        // expected
    }

    // The decrementStock from step 1 must have been rolled back.
    $row = $kit->queryExecutor->fetchOne('SELECT stock FROM products WHERE id = ?', [1]);
    self::assertSame(10, $row['stock']);
}
```

This test fails fast if the use case calls `$this->products->decrementStock()`
instead of building a repository from `$tx` — the stock decrement leaks past
the rollback and the assertion catches it.

### HTTP-level rollback

The same property at the integration level:

```php
public function testTransactionRollsBackOnDomainException(): void
{
    // seed two products
    // ...

    // Order that will fail on the second product (insufficient stock)
    $response = $this->request('POST', '/orders', [
        'items' => [
            ['product_id' => $p1Id, 'qty' => 3],   // would succeed
            ['product_id' => $p2Id, 'qty' => 99],  // will fail
        ],
    ]);

    self::assertSame(409, $response->getStatusCode());

    // Stock of product 1 must be unchanged — transaction was rolled back
    $products = $this->json($this->request('GET', '/products'))['items'];
    self::assertSame($originalStock1, $products[0]['stock']);
}
```

---

## Future direction

The current pattern requires instantiating concrete repository classes inside the
callback, which means the use case knows about the repository implementation
(`SqliteProductRepository`) rather than its interface. This is a known limitation.

A `RepositoryFactory` abstraction — an interface accepted by use cases that can produce
a repository for a given executor — would restore full interface-only dependency.
This is tracked for consideration in a future NENE2 version.
