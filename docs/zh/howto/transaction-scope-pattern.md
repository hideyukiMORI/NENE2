# 操作指南：事务作用域模式

> **FT 参考**：FT253（`NENE2-FT/txlog`）— 数据库事务边界：原子性订单创建与库存扣减

演示 NENE2 中 `DatabaseTransactionManagerInterface::transactional()` 的正确使用模式。
Repository 必须在回调内部使用事务范围的 executor 进行实例化——预先注入的 Repository 使用不同的连接，如果事务失败，其写入操作**不会**被回滚。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/orders` | 下单（原子性库存扣减 + 订单创建） |

---

## 数据库结构

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

`CHECK(stock >= 0)` 是 DB 层的安全网，即使应用逻辑存在 bug 也能防止库存变为负数。应用层也会在扣减前验证库存，因此正常操作中该约束不会被触发。

---

## Executor 作用域陷阱

`DatabaseTransactionManagerInterface::transactional()` 开启一个事务，并向回调传递一个**事务范围的 executor**。通过这个 executor 进行的写入属于事务的一部分，如果抛出异常则会被回滚。

**❌ 错误：事务前注入的 Repository**

```php
final class OrderService
{
    public function __construct(
        private readonly InventoryRepository $inventory, // 使用外部 executor
        private readonly OrderRepository     $orders,    // 使用外部 executor
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($txExecutor) use ($items): int {
            // BUG：$this->inventory 和 $this->orders 使用外部 executor，
            // 而非 $txExecutor。它们的写入在不同的连接上。
            // 事务回滚时，它们的更改不会被撤销。
            foreach ($items as $item) {
                $this->inventory->decrement($item['product_id'], $item['quantity']);
            }
            return $this->orders->create($items);
        });
    }
}
```

**✅ 正确：在回调内部创建 Repository**

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
        // 只注入事务管理器，不注入 Repository。
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Repository 使用事务范围的 executor 实例化。
            // 所有读写通过同一个连接，在同一个事务中进行。
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // 抛出 InsufficientStockException → 触发回滚
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

`transactional()` 传递的事务范围 `$executor` 共享同一个 PDO 连接和事务上下文。在回调内部使用此 executor 创建 Repository，确保所有写入参与同一个原子事务。

---

## 应用层检查的库存扣减

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

先读后写模式（`getStock()` + `UPDATE`）本身不是原子的——并发请求可能读取相同的库存并都成功。在生产环境中，使用 `SELECT ... FOR UPDATE`（MySQL）或 `UPDATE ... WHERE stock >= ?` 作为乐观检查：

```sql
UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?
-- 然后检查受影响行数 == 1；如果为 0，抛出 InsufficientStockException
```

SQLite 不支持 `SELECT FOR UPDATE`，因此 `CHECK(stock >= 0)` 约束在 DB 层捕获并发竞争——第二次更新会抛出约束违反，作为异常传播并触发回滚。

---

## 多商品原子性：部分失败触发完整回滚

```php
foreach ($items as $item) {
    $inventory->decrement($item['product_id'], $item['quantity']); // 可能抛出
}
return $orders->create($items); // 仅在所有扣减成功后才到达
```

如果**第一个**商品失败，不会进行任何库存更改。如果**最后一个**商品失败，之前所有的扣减都会被回滚。测试套件验证了两种情况：

```php
// Widget：10 库存；Gadget：1 库存。订单 [Widget×3, Gadget×5] 在 Gadget 处失败。
$this->assertSame(10, $inventory->getStock(1)); // Widget 恢复到 10
$this->assertSame(0, $orders->count());          // 未创建订单
```

---

## 异常 → 回滚 → 422 映射

`transactional()` 内部抛出的异常传播到调用者：

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

`transactional()` 捕获异常，调用 `ROLLBACK`，然后重新抛出。调用者捕获重新抛出的异常并将其映射为 Problem Details 响应。

---

## 输入验证：数量的严格 `is_int` 检查

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

`is_int()` 拒绝 JSON 浮点数（`1.0`）和字符串（`"3"`）。PHP 的 JSON 解码器将 JSON 整数值转换为 PHP `int`，因此 `is_int()` 对 JSON 输入有效。仅对查询字符串参数（始终为字符串）使用 `is_numeric()`。

---

## `transactional()` 使用总结

| 应该做 | 不应该做 |
|---|---|
| 在回调内部创建 Repository | 在类构造时注入 Repository |
| 将回调中的 `$executor` 传给新 Repository | 使用构造函数中注入的 `$this->executor` |
| 让异常传播以触发回滚 | 在回调内部静默捕获异常 |
| 从回调返回值 | 在需要插入 ID 时返回 `void` |

---

## 相关指南

- [`transactions.md`](transactions.md) — `DatabaseTransactionManagerInterface` API 参考
- [`use-transactions.md`](use-transactions.md) — 为现有端点添加事务
- [`budget-tracking.md`](budget-tracking.md) — 通过事务转账（双账户余额更新）
- [`order-management.md`](order-management.md) — 完整订单生命周期（创建、状态、取消）
- [`optimistic-locking.md`](optimistic-locking.md) — 无事务的竞争条件防护
