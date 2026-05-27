# 操作指南：CQRS 模式

> **FT 参考**：FT233（`NENE2-FT/cqrslog`）——CQRS 模式 API

演示命令查询职责分离（CQRS）：写入侧接受 Command 并变更写入模型；读取侧接受 Query 并从去规范化的读取模型（SQL VIEW）中读取数据。两侧共享同一个 SQLite 数据库，但具有独立的处理器类、独立的模型对象，且没有共享状态。

---

## CQRS 核心概念

| 概念 | 描述 |
|------|------|
| **Command** | 改变状态的意图——`PlaceOrderCommand`、`UpdateOrderStatusCommand` |
| **Query** | 数据请求——`GetOrderSummaryQuery`、`ListOrderSummariesQuery` |
| **CommandHandler** | 在写入模型（规范化表）上执行 Command |
| **QueryHandler** | 在读取模型（去规范化视图）上执行 Query |
| **写入模型** | 为事务写入优化的规范化表 |
| **读取模型** | 为查询输出形状优化的去规范化视图 |

---

## 路由

| 方法 | 路径 | 侧 | 描述 |
|------|------|-----|------|
| `POST` | `/orders` | 写入 | 下新订单（Command） |
| `PATCH` | `/orders/{id}/status` | 写入 | 更新订单状态（Command） |
| `GET` | `/orders` | 读取 | 列出订单摘要（Query） |
| `GET` | `/orders/{id}` | 读取 | 获取单个订单摘要（Query） |

---

## Command 对象（写入侧）

Command 是携带已校验数据传入处理器的不可变值对象：

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

Command 不包含业务逻辑——它们是控制器已校验输入的类型化容器。使用 `readonly` 防止构造后发生变更。

---

## Command 处理器（写入模型）

`OrderCommandHandler` 拥有所有变更操作。它写入规范化表：

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

处理器返回原始值（`int` orderId、`bool` success）——而非读取模型对象。Command 成功后，控制器重新查询读取侧以获取响应结构。

---

## Query 对象（读取侧）

Query 是查询参数的类型化包装：

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

将查询参数封装在对象中使 Query 处理器的契约更加明确，避免了跨处理器签名的原始类型过度使用。

---

## Query 处理器（读取模型）

`OrderQueryHandler` 从 `order_summary` 读取，这是一个在数据库层预计算 JOIN 的 SQL VIEW：

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

`OrderSummary` 是读取模型 DTO——它从不被写入；它只表示查询结果。将其与任何写入侧的 `Order` 实体分开，防止读取侧关注点泄漏到写入模型。

---

## 读取模型：SQL VIEW 作为去规范化投影

读取模型是一个 SQLite `VIEW`，在数据库层预计算 JOIN 和聚合：

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

该视图提供稳定的查询接口——Query 处理器无需了解规范化的 `orders`/`order_items` JOIN。如果写入模型更改了表结构，只需更新视图定义，而无需修改 Query 处理器。

`total_cents` 以整数分为单位存储货币金额（无浮点舍入误差）。`?? 0` 防止没有条目时出现 `NULL`。

---

## 写入模型结构

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

写入模型是规范化的：`orders` + `order_items` 为 1:N 关系。没有计算列——读取投影在视图中。

---

## 控制器：连接 Command 和 Query

写入 Command 成功后，控制器使用读取侧构建响应：

```php
private function placeOrder(ServerRequestInterface $request): ResponseInterface
{
    // ... 校验输入 ...
    $orderId = $this->commands->place(new PlaceOrderCommand($customer, $items), $now);

    // 通过读取模型重新查询以获取响应结构
    $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));

    return $this->json->create($summary->toArray(), 201);
}
```

这种"先写后查"的模式使写入侧对响应结构一无所知，并确保响应始终反映视图的投影（包括 `total_cents` 等计算字段）。

Command 之前的条目校验：

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

对 `quantity` 和 `unit_price` 的 `is_int()` 严格检查拒绝来自 JSON 的浮点数和字符串。`unit_price >= 0` 允许零（免费商品）；`quantity > 0` 要求至少一个。

---

## 何时使用 CQRS

CQRS 增加了结构性开销。在以下情况下使用它：

- 读取和写入数据形状差异显著（例如，列表需要写入模型不存储的聚合数据）
- 读取负载远超写入负载，需要独立扩展
- 领域有复杂的写入不变量（事务、校验、领域事件），应与读取优化隔离
- 正在向事件溯源演进（CQRS 与事件溯源写入模型天然配合）

避免 CQRS 的情况：
- 读取和写入形状相同（简单的 CRUD 端点）
- 代码库较小，间接层带来的复杂度超过清晰度收益
- 团队不熟悉该模式（会引入认知负担）

---

## 相关操作指南

- [`event-sourcing.md`](event-sourcing.md) ——由事件存储支持的 CQRS 写入侧
- [`approval-workflow.md`](approval-workflow.md) ——订单状态转换的状态机
- [`transactions.md`](transactions.md) ——将 Command 写入包装在事务中
- [`batch-api-partial-success.md`](batch-api-partial-success.md) ——带逐项结果的批量 Command
