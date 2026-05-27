# 使用 NENE2 构建限时特卖系统

本指南介绍如何构建时间限制、数量约束的限时特卖系统，用户可以在特卖窗口内以折扣价购买商品。

**字段试验**：FT140
**NENE2 版本**：^1.5
**涵盖主题**：时间窗口校验，使用 COUNT(*) 进行库存计数，UNIQUE 约束实现每用户一次购买，`match` 表达式处理状态，破解者思维攻击测试

---

## 我们要构建的内容

- `POST /products` — 创建商品
- `POST /sales` — 创建限时特卖（product_id、price、quantity、starts_at、ends_at）
- `GET /sales/{saleId}` — 查看特卖详情（含剩余数量和状态）
- `POST /sales/{saleId}/purchase` — 在活跃窗口内购买（每用户一次）
- `GET /sales/{saleId}/purchases` — 列出所有购买者

---

## 数据库结构

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (sale_id, user_id)` 在 DB 层面防止用户对同一特卖重复购买，即使在并发请求下也有效。

---

## 时间窗口校验

```php
$now = date('c');

if ($now < $sale['starts_at']) {
    return $this->responseFactory->create(['error' => 'sale has not started yet'], 422);
}

if ($now > $sale['ends_at']) {
    return $this->responseFactory->create(['error' => 'sale has ended'], 422);
}
```

将 `starts_at` / `ends_at` 存储为 ISO 8601 字符串。字符串比较对 ISO 8601 有效，因为该格式按词典顺序排序。

---

## 使用 COUNT(*) 进行库存计数

不维护可变的 `remaining` 列，而是对实际购买数量计数：

```php
public function countPurchases(int $saleId): int
{
    $row = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
        [$saleId],
    );

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}
```

然后检查：

```php
$purchased = $this->repository->countPurchases($saleId);

if ($purchased >= $sale['quantity']) {
    return $this->responseFactory->create(['error' => 'sold out'], 422);
}
```

`remaining` 在读取时派生：`$sale['quantity'] - $purchased`。使用 `max(0, $remaining)` 限制以避免负数显示。

---

## 每用户一次购买——UNIQUE 约束

`UNIQUE (sale_id, user_id)` 在 DB 层面防止重复。`DatabaseConstraintException` 映射为 409：

```php
public function purchase(int $saleId, int $userId, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
            [$saleId, $userId, $now],
        );

        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

当 `purchase()` 返回 `false` 时，处理程序返回 409。

---

## 使用 match 表达式处理特卖状态

```php
$status = match (true) {
    $now < $sale['starts_at'] => 'upcoming',
    $now > $sale['ends_at']   => 'ended',
    default                   => 'active',
};
```

三种状态：`upcoming`（即将开始）、`active`（进行中）、`ended`（已结束）。`match` 表达式是穷举的，因为 `default` 覆盖了所有其他情况。

---

## 破解者攻击测试结果（FT140）

| ID | 攻击 | 期望 | 结果 |
|----|------|------|------|
| ATK-01 | 商品名称 SQL 注入 | 201（原样存储） | 通过 |
| ATK-02 | 无 X-User-Id 购买 | 400 | 通过 |
| ATK-03 | 非数字 X-User-Id | 非 201 | 通过 |
| ATK-04 | URL 中的负数 saleId | 非 201 | 通过 |
| ATK-05 | 特卖开始前购买 | 422 | 通过 |
| ATK-06 | 特卖结束后购买 | 422 | 通过 |
| ATK-07 | 同一特卖重复购买 | 第二次 409 | 通过 |
| ATK-08 | 耗尽库存后购买 | 422 售罄 | 通过 |
| ATK-09 | 创建 quantity=0 特卖 | 422 | 通过 |
| ATK-10 | 创建负价格特卖 | 422 | 通过 |
| ATK-11 | 以不存在的用户购买 | 404 | 通过 |
| ATK-12 | ends_at 早于 starts_at | 422 | 通过 |

12 个攻击测试全部通过。

---

## 常见陷阱

| 陷阱 | 解决方案 |
|------|----------|
| 可变 `remaining` 列在并发下失准 | 从 `purchases` 表计数，读取时派生 `remaining` |
| API 允许 quantity=0 | 在处理程序中校验 `$quantity > 0`；schema 中也添加 `CHECK (quantity > 0)` |
| 负价格漏网 | 校验 `$price >= 0`；schema 中也添加 `CHECK (price >= 0)` |
| 用户对同一特卖购买两次 | `UNIQUE (sale_id, user_id)` + `DatabaseConstraintException` → 409 |
| 非 ISO 字符串的时间比较 | 使用 ISO 8601（如 `date('c')`）——词典顺序比较正确 |
| `ends_at` 和 `starts_at` 倒置 | 在 INSERT 前校验 `$starts_at < $ends_at` |
