# Use Database Transactions

This guide explains how to perform atomic multi-step operations using
`DatabaseTransactionManagerInterface` in NENE2.

**Prerequisite**: You have a repository backed by `DatabaseQueryExecutorInterface`.
If not, start with [Add a database-backed endpoint](./add-database-endpoint.md).

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

Because the callback provides its own executor, you cannot safely reuse repositories that were
injected at construction time — they would execute queries on a separate connection that sits
outside the transaction.

The solution: **instantiate repository classes inside the callback** using the executor the
callback provides.

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

Use a **temp file** instead:

```php
protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}

private function dbConfig(): DatabaseConfig
{
    return new DatabaseConfig(
        url: null, environment: 'test', adapter: 'sqlite',
        host: 'localhost', port: 1, name: $this->dbFile,
        user: 'myapp', password: '', charset: 'utf8',
    );
}
```

Each test gets a fresh file, both `PdoDatabaseQueryExecutor` and
`PdoDatabaseTransactionManager` connect to the same file, and `tearDown` deletes it.

---

## Verifying rollback behaviour

Test that a failure mid-transaction undoes all preceding changes:

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
