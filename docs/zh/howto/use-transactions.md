# 使用数据库事务

本指南说明如何在 NENE2 中使用 `DatabaseTransactionManagerInterface` 执行原子性多步操作。

**前置条件**：你已有一个基于 `DatabaseQueryExecutorInterface` 的 repository。
如果没有，请先阅读[添加数据库端点](./add-database-endpoint.md)。

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

应使用**临时文件**代替：

```php
protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo); // 在工厂打开自己的连接之前关闭初始化连接

    $dbConfig = new DatabaseConfig(
        url:         null,
        environment: 'test',
        adapter:     'sqlite',
        host:        '',      // SQLite 不使用
        port:        1,       // SQLite 不使用
        name:        $this->dbFile,
        user:        '',      // SQLite 不使用
        password:    '',      // SQLite 不使用
        charset:     '',      // SQLite 不使用
    );

    $factory   = new PdoConnectionFactory($dbConfig);
    $executor  = new PdoDatabaseQueryExecutor($factory);
    $txManager = new PdoDatabaseTransactionManager($factory);
    // ... 连接 repository 和 use case
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

每个测试都获得一个全新的文件，`PdoDatabaseQueryExecutor` 和 `PdoDatabaseTransactionManager` 都连接到同一个文件，`tearDown` 删除该文件。

> **关于 SQLite `DatabaseConfig` 字段的说明**：对于 SQLite，只有 `adapter` 和 `name` 是必需的。`host`、`user`、`password`、`charset` 传空字符串——当 `adapter` 为 `'sqlite'` 时它们不会被验证。

> **说明**：`PdoDatabaseQueryExecutor` 不接受原始 `PDO` 作为构造参数——它需要 `DatabaseConnectionFactoryInterface`。使用 `PdoConnectionFactory`（如上所示）将原始 `PDO` 设置桥接到 executor。

---

## 验证回滚行为

测试事务中途失败会撤销所有之前的变更：

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
