# 使用数据库事务

本指南说明如何在 NENE2 中使用 `DatabaseTransactionManagerInterface` 执行原子性多步操作。

**前置条件**：你已有一个基于 `DatabaseQueryExecutorInterface` 的 repository。
如果没有，请先阅读[添加数据库端点](./add-database-endpoint.md)。

> **🚫 SQLite 的 `:memory:` 与 `transactional()` 不兼容。**
>
> `PdoDatabaseTransactionManager` 每次调用都会打开一个*新*连接。每个 `:memory:` 连接指向的是
> *不同的*空内存数据库，因此 executor 与事务看到的是不同的数据，回滚对 executor 的视图没有任何影响。
> 症状是静默的：回调执行到一半时查询返回 `null`，或者回滚无法撤销测试通过另一个 executor 写入的数据。
>
> 测试中请使用**基于文件的 SQLite** 数据库（参见下文
> “[使用基于文件的 SQLite 数据库进行测试](#使用基于文件的-sqlite-数据库进行测试)”）。
> `Nene2\Testing\DatabaseTestKit::sqlite(':memory:')` 会以 `InvalidArgumentException` 拒绝
> `:memory:`，使该问题快速失败。

---

## 为什么在 NENE2 中使用事务

`DatabaseTransactionManagerInterface` 将多条 SQL 语句包装在单个事务中：要么全部成功（提交），要么在任何 `Throwable` 时全部回滚。

该接口有一个方法：

```php
public function transactional(callable $callback): mixed;
```

回调接收一个绑定到当前打开事务的**全新** `DatabaseQueryExecutorInterface`。**此 executor 与构造时注入的 executor 不同。**

---

## 事务性 repository 模式

> **警告——不要在回调内部复用已注入的 repository。**
>
> 在构造时注入的 repository 持有与事务运行所在连接**不同的连接**。在回调内使用它们意味着其查询在事务之外执行：回滚不会撤销这些变更，而通过回调内部写入的未提交行对它们也可能不可见。
>
> 这个错误能通过编译，测试也可能通过——该 bug 只在并发写入或依赖回滚行为时才会暴露。

因为回调提供了自己的 executor，所以必须在回调内部**使用回调提供的 executor 来实例化 repository 类**。

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
                // 必须在这里实例化具体类——$tx executor 绑定到
                // 此事务的连接。已注入的实例使用不同的 executor。
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

### 为什么不复用已注入的 repository？

`PdoDatabaseTransactionManager::transactional()` 通过 `DatabaseConnectionFactoryInterface::create()` 打开一个**新连接**，并在其上开启事务。回调的 executor 绑定到该特定连接。

已注入的 `SqliteProductRepository` 持有一个独立的 `PdoDatabaseQueryExecutor`，它会在第一次使用时懒加载打开自己的连接。通过已注入 repository 的查询运行在那个其他连接上——在事务之外——因此回滚不会撤销它们，而通过已注入 repository 的插入可能也看不到回调内的未提交行。

---

## 在前端控制器中进行连接

需要两个独立的对象：

| 对象 | 用途 |
|---|---|
| `PdoDatabaseQueryExecutor` | 非事务性读取（如 `GET /products`） |
| `PdoDatabaseTransactionManager` | 将多步写入包装在事务中 |

两者共享同一个 `PdoConnectionFactory`：

```php
$connectionFactory = new PdoConnectionFactory($dbConfig);

$executor  = new PdoDatabaseQueryExecutor($connectionFactory);  // 用于读取 repository
$txManager = new PdoDatabaseTransactionManager($connectionFactory); // 用于 use case

$products = new SqliteProductRepository($executor);  // 供 GET /products 使用
$createOrder = new CreateOrderUseCase($txManager);   // 内部使用 $tx
```

---

## 使用基于文件的 SQLite 数据库进行测试

内存 SQLite（`sqlite::memory:`）每个连接创建一个**独立的数据库**，因此 `PdoDatabaseTransactionManager`（每次 `transactional()` 调用都打开新连接）看不到 `PdoDatabaseQueryExecutor` 写入的行，反之亦然。

应使用**临时文件**代替。`Nene2\Testing\DatabaseTestKit` 只需一行即可将 executor 与
事务管理器接到同一个文件上：

```php
use Nene2\Testing\DatabaseTestKit;

protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/' . uniqid('test-', true) . '.sqlite';

    // 用一次性连接写入 schema，并在 kit 打开自己的连接之前关闭它。
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo);

    $this->kit = DatabaseTestKit::sqlite($this->dbFile);
    // $this->kit->queryExecutor       — 用于读取 repository
    // $this->kit->transactionManager  — 用于 use case
    // $this->kit->connectionFactory   — 需要构建额外 executor 时使用
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

该 kit 位于 `Nene2\Testing\DatabaseTestKit`（ADR 0012，公开 API）。它在内部把
`PdoConnectionFactory` + `PdoDatabaseQueryExecutor` + `PdoDatabaseTransactionManager` 接在
同一个文件上，因此测试无需按名称引用任何 `@internal` 类。
`DatabaseTestKit::sqlite(':memory:')` 及其底层的配置组合都会在工厂层被拦截。

> **`DatabaseConfig::sqlite(string $path)`** 是希望保持显式接线时（例如注入自定义的
> `PdoConnectionFactory` 子类）的等价快捷方式。它取代了旧版指南中 9 个参数的
> `new DatabaseConfig(...)` 写法。

---

## 验证回滚行为

一个包裹多次写入的 use case，只有当回滚路径**确实撤销**了之前的写入时才是正确的。
只覆盖成功路径的测试，即使 use case 忘记使用 `$tx` 也会通过——此时 `$this->products`
会在事务之外执行并静默提交。真正能抓到这个 bug 的是回滚测试。

### 单元级回滚

用 `DatabaseTestKit` 直接驱动 use case，并在异常之后断言数据库状态：

```php
public function testRollbackUndoesStockDecrementWhenOrderInsertFails(): void
{
    $kit = DatabaseTestKit::sqlite($this->dbFile);
    $kit->queryExecutor->execute(/* 种子数据：stock=10 的产品 1 */);

    $useCase = new CreateOrderUseCase($kit->transactionManager);

    try {
        // 传入一个能通过 decrementStock、但会在 orders.save 触发唯一约束冲突的数量
        //（例如重复的幂等键）。
        $useCase->execute(productId: 1, qty: 3, idempotencyKey: $existingKey);
        self::fail('Expected order creation to fail.');
    } catch (DatabaseConstraintException) {
        // 符合预期
    }

    // 第 1 步的 decrementStock 必须已被回滚。
    $row = $kit->queryExecutor->fetchOne('SELECT stock FROM products WHERE id = ?', [1]);
    self::assertSame(10, $row['stock']);
}
```

如果 use case 调用的是 `$this->products->decrementStock()` 而不是从 `$tx` 构建 repository，
这个测试会立刻失败——库存扣减会越过回滚，被断言抓住。

### HTTP 级回滚

在集成层面验证同一性质：

```php
public function testTransactionRollsBackOnDomainException(): void
{
    // 填充两个商品
    // ...

    // 第二个商品会失败的订单（库存不足）
    $response = $this->request('POST', '/orders', [
        'items' => [
            ['product_id' => $p1Id, 'qty' => 3],   // 会成功
            ['product_id' => $p2Id, 'qty' => 99],  // 会失败
        ],
    ]);

    self::assertSame(409, $response->getStatusCode());

    // 商品 1 的库存必须保持不变——事务已回滚
    $products = $this->json($this->request('GET', '/products'))['items'];
    self::assertSame($originalStock1, $products[0]['stock']);
}
```

---

## 未来方向

当前模式要求在回调内部实例化具体的 repository 类，这意味着 use case 需要了解 repository 的实现（`SqliteProductRepository`）而非其接口。这是一个已知的局限。

`RepositoryFactory` 抽象——use case 接受的接口，能够为给定的 executor 生成 repository——将恢复完全的接口级依赖。这将在未来的 NENE2 版本中考虑实现。
