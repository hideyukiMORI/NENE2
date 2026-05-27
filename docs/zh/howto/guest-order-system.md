# 使用 NENE2 构建访客订单系统（购物车 → 订单 → 订单明细）

本指南介绍如何构建电商下单流程，用户将商品加入购物车、检查库存，并下订单（在订单明细中记录价格快照）。

**字段试验**：FT139
**NENE2 版本**：^1.5
**涵盖主题**：多表连接，库存校验，order_items 中的价格快照，购物车隔离，`array_sum` 合计计算

---

## 我们要构建的内容

- `POST /products` — 创建商品（名称、价格、库存）
- `POST /cart` — 将商品加入购物车（已有则累加数量）
- `GET /cart` — 查看购物车内容及合计（X-User-Id 标识用户）
- `DELETE /cart/{productId}` — 从购物车删除商品
- `POST /orders` — 下订单（校验库存、减少库存、清空购物车）
- `GET /orders/{orderId}` — 查看订单详情含明细（仅所有者）

---

## 数据库结构

```sql
CREATE TABLE products (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    stock      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE cart_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL DEFAULT 1,
    added_at   TEXT    NOT NULL,
    UNIQUE (user_id, product_id),
    FOREIGN KEY (user_id)    REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    total      INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

`cart_items` 上的 `UNIQUE (user_id, product_id)` 防止重复行——再次添加同一商品时累加数量。

---

## order_items 中的价格快照

下订单时，当前商品的 `name` 和 `price` 被复制到 `order_items` 中。这保护历史订单不受未来价格变更的影响。

```php
/** @param array<int, array{product_id: int, name: string, price: int, quantity: int}> $items */
public function createOrder(int $userId, array $items, int $total, string $now): int
{
    $this->executor->execute(
        'INSERT INTO orders (user_id, total, created_at) VALUES (?, ?, ?)',
        [$userId, $total, $now],
    );

    $orderId = (int) $this->executor->lastInsertId();

    foreach ($items as $item) {
        $this->executor->execute(
            'INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']],
        );
    }

    return $orderId;
}
```

---

## 购物车数量累加

`UNIQUE (user_id, product_id)` 意味着对同一商品再次 `POST /cart` 时必须 UPDATE，而非 INSERT：

```php
public function addToCart(int $userId, int $productId, int $quantity, string $now): void
{
    $existing = $this->findCartItem($userId, $productId);

    if ($existing !== null) {
        $this->executor->execute(
            'UPDATE cart_items SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $userId, $productId],
        );
        return;
    }

    $this->executor->execute(
        'INSERT INTO cart_items (user_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?)',
        [$userId, $productId, $quantity, $now],
    );
}
```

---

## 下订单前的库存校验

在减少任何库存之前检查所有商品。部分减少的回滚很复杂——先校验，再操作：

```php
// 校验所有商品
foreach ($items as $item) {
    $product = $this->repository->findProductById($item['product_id']);

    if ($product === null || $product['stock'] < $item['quantity']) {
        return $this->responseFactory->create([
            'error'      => 'insufficient stock',
            'product_id' => $item['product_id'],
        ], 422);
    }
}

// 减少库存并创建订单
foreach ($items as $item) {
    $this->repository->decrementStock($item['product_id'], $item['quantity']);
}

$orderId = $this->repository->createOrder($actorId, $items, $total, date('c'));
$this->repository->clearCart($actorId);
```

---

## 购物车合计计算

```php
$total = array_sum(array_map(fn(array $i) => $i['price'] * $i['quantity'], $items));
```

这在 PHP 中从连接查询结果计算，而非在 SQL 中。同样的计算用于购物车预览和存储的订单合计。

---

## 按用户隔离购物车

购物车条目始终按 `user_id` 过滤。每个用户只能查看和修改自己的购物车。`GET /cart` 处理程序对没有商品的用户返回空列表——绝不返回其他用户的购物车。

---

## 常见陷阱

| 陷阱 | 解决方案 |
|------|----------|
| 重复添加同一商品创建重复行 | `UNIQUE (user_id, product_id)` + 冲突时 UPDATE |
| 下订单后价格变更破坏历史 | 下单时将 `name` 和 `price` 复制到 `order_items` |
| 多商品失败时部分减少库存 | 先校验所有商品，再全部减少 |
| 订单详情返回商品当前价格 | 查询 `order_items.price`，而非 `products.price` |
| 购物车对所有用户可见 | 始终按 `user_id` 过滤 `cart_items` |
