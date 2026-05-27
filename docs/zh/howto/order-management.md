# 操作指南：订单管理 API

> **FT 参考**：FT274（`NENE2-FT/orderlog`）——订单管理：带 SKU 校验的行项目、total_cents 自动计算、状态生命周期（pending→confirmed→shipped→delivered→cancelled）、IDOR → 404、管理员覆盖、取消冲突检测，36 个测试全部通过。
>
> 也在 FT215（`NENE2-FT/orderlog` 前身）中验证——相同模式，较早实现。

本指南展示如何使用 NENE2 构建多行项目的订单管理 API。

## 功能

- 创建含行项目的订单（SKU + 数量 + 单价）
- 从行项目自动计算总金额
- 状态生命周期：`pending → confirmed → shipped → delivered → cancelled`
- 用户范围的 IDOR 保护（返回 404 而非 403，以隐藏资源是否存在）
- 管理员覆盖以支持跨用户操作
- 带冲突检测的原子取消（不能取消已 `cancelled` 或 `delivered` 的订单）

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    total_cents INTEGER NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
CREATE TABLE IF NOT EXISTS order_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL,
    sku         TEXT    NOT NULL,
    quantity    INTEGER NOT NULL,
    unit_cents  INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id, id DESC);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/orders` | 创建含行项目的订单 |
| `GET` | `/orders/{id}` | 获取含行项目的订单（所有者或管理员） |
| `POST` | `/orders/{id}/cancel` | 取消订单（所有者或管理员） |
| `GET` | `/users/{userId}/orders` | 列出某用户的订单（本人或管理员） |

## 行项目校验

```php
/** SKU：大写字母数字和连字符，1–32 个字符 */
private const string SKU_PATTERN = '/\A[A-Z0-9\-]{1,32}\z/';

// 每个行项目：
// - sku：必须匹配 SKU_PATTERN
// - quantity：整数 1–9999
// - unit_cents：非负整数
// 每个订单最多 50 个行项目
```

## 仓储模式

```php
/**
 * @param list<array{sku: string, quantity: int, unit_cents: int}> $items
 * @return array<string, mixed>
 */
public function create(int $userId, string $note, array $items): array
{
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += $item['quantity'] * $item['unit_cents'];
    }
    // 插入订单，然后插入行项目，返回 findById()
}

/** @return 'not_found'|'not_cancellable'|'ok' */
public function cancel(int $id, int $userId, bool $isAdmin): string
{
    // 用户错误时返回 'not_found'（IDOR 保护）
    // 已取消/已交付的订单返回 'not_cancellable'
}
```

## IDOR 保护

用户范围的端点在用户访问其他用户资源时返回 `404`（而非 `403`）：

```php
// GET /orders/{id}
if (!$isAdmin && (int) $order['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Order not found.');
}

// GET /users/{userId}/orders
if (!$isAdmin && $callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## 使用 match 表达式进行取消

```php
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);

return match ($result) {
    'not_found'       => $this->problem(404, 'not-found', 'Order not found.'),
    'not_cancellable' => $this->problem(409, 'conflict', 'Order cannot be cancelled.'),
    default           => $this->json(['message' => 'Order cancelled.']),
};
```

## 安全模式

- **管理员故障关闭**：在 `hash_equals()` 之前执行 `if ($this->adminKey === '') return false;`
- **`ctype_digit()`**：路径和请求头 ID 的 ReDoS 安全整数校验
- **`is_int()`**：严格类型检查——拒绝以 JSON 传入的浮点数（如 `1.5`）
- **最大行项目守护**：限制为 50 个行项目以防止超大载荷

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 将价格存储为 FLOAT | 总金额中的浮点舍入误差（使用整数分） |
| 接受自由格式的 SKU 字符串 | 注入攻击面；使用正则表达式白名单（如 `[A-Z0-9\-]{1,32}`） |
| 无最大行项目限制 | 攻击者发送 10,000 个行项目数组导致 INSERT 循环缓慢 |
| 客户端计算总金额 | 客户端可发送任意总金额；始终从 `quantity × unit_cents` 推导 |
| 错误用户访问订单时返回 403 | 暴露订单是否存在；使用 404 隐藏所有权 |
| 允许取消已交付的订单 | 已履行订单应不可变；使用状态机 |
| 省略 order_items 上的 `ON DELETE CASCADE` | 删除订单后留下孤立行项目 |
