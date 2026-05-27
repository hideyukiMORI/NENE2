# 数据库事务

使用 `DatabaseTransactionManagerInterface::transactional()` 执行原子性多步骤操作。
如果任何步骤抛出异常，回调中的所有更改都会自动回滚。

## 关键规则：在回调内部实例化 Repository

> **警告：** 在构造时注入的 Repository 使用*不同的* PDO 连接，在**事务外部**执行。回滚不会撤销它们的更改。

`PdoConnectionFactory` 每次调用时都会创建一个新连接。`transactional()` 开启事务时，在*新连接*上开启。任何在此调用之前创建的 Repository（例如通过构造函数 DI 注入的）持有不同的连接——该连接不属于事务。

### 正确模式

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // ✅ 在回调内部使用事务范围的 executor 实例化 Repository
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // 抛出 InsufficientStockException → 自动触发回滚
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

### 错误模式（更改**不会**被回滚）

```php
// ❌ 这些 Repository 持有不同的连接——不属于事务
public function __construct(
    private readonly InventoryRepository $inventory,
    private readonly OrderRepository $orders,
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← 不同连接，在事务外部！
        }
        return $this->orders->create($items); // ← 同样的问题
        // 如果抛出异常，$this->inventory 的更改不会被撤销
    });
}
```

这是一个静默失败：代码可以编译，PHPStan 无法检测到它，除非测试专门验证回滚行为，否则测试可能会通过。

---

## 回滚行为

`transactional()` 捕获 `Throwable`，回滚，然后重新抛出。调用者接收原始异常。

```php
try {
    $this->orderService->placeOrder($items);
} catch (InsufficientStockException $e) {
    // 此调用中的所有库存扣减都已撤销
    // 未创建订单
}
```

---

## 预验证 + 原子操作模式

上述失败快速模式在第一个缺货商品处停止，客户端不知道其他失败。
当用户体验很重要时，先收集所有错误，然后原子性地执行：

```php
public function placeOrder(array $items): int
{
    // 第一阶段：在事务外验证所有商品（只读）
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

    // 第二阶段：原子扣减——内部仍使用事务范围的 executor
    return $this->tx->transactional(function ($executor) use ($items): int {
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            // DB CHECK 约束是应对竞争的最终安全网
            $inventory->decrement($item['product_id'], $item['quantity']);
        }

        return $orders->create($items);
    });
}
```

**权衡：**
- 第一阶段的读取不属于事务，因此并发请求可能在第一阶段和第二阶段之间耗尽库存。数据库 `CHECK (stock >= 0)` 约束（或 `decrement()` 在负库存时抛出）会捕获这个竞争。
- 对大多数应用这是可以接受的。若需要严格正确性，使用 `SELECT ... FOR UPDATE` 或可序列化隔离级别（SQLite 不支持）。

---

## 测试回滚正确性

始终验证失败订单后库存保持不变：

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// Widget 扣减本会成功，但 Gadget 失败 → 两者都必须回滚
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],
    ['product_id' => 2, 'quantity' => 5], // 仅有 1 件库存
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1), 'Widget 必须回滚到 10');
assertSame(0, $this->orders->count(), '未创建订单');
```

模拟 Repository 的单元测试无法捕获这类 bug——只有共享同一 SQLite/MySQL 连接的集成测试才能验证回滚正确性。

---

## Laravel vs NENE2

Laravel 的 `DB::transaction()` 可以与注入的 Model 一起使用，因为它透明地在整个请求中共享一个连接。NENE2 的 `PdoConnectionFactory` 每次调用都返回一个新连接——这是为了可测试性和显式连接控制而做出的刻意设计选择。其结果是，在 NENE2 中**必须**使用回调范围的 executor 模式。
