# 操作指南：嵌套 JSON 校验

> **FT 参考**：FT322（`NENE2-FT/nestedlog`）——带嵌套 items 校验的订单 API，`items.N.field` 错误路径，单次响应返回多个错误，错误代码，总额计算，19 个测试 / 43 个断言全部通过。

本指南展示如何校验嵌套 JSON 数组（例如订单行项目），并返回精确标识哪个嵌套字段失败的结构化错误路径。

## 数据库结构

```sql
CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    total      REAL    NOT NULL DEFAULT 0.0,
    created_at TEXT    NOT NULL
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price REAL    NOT NULL
);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/orders` | 创建带 items 的订单 |
| `GET`  | `/orders` | 列出订单 |
| `GET`  | `/orders/{id}` | 获取带 items 的订单 |

## 创建订单

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": 1, "quantity": 2, "unit_price": 9.99},
    {"product_id": 2, "quantity": 1, "unit_price": 4.50}
  ]
}
→ 201
{
  "id": 1,
  "customer": "Alice",
  "items": [...],
  "total": 24.48      // 2×9.99 + 1×4.50
}
```

## 嵌套错误路径——`items.N.field`

每个 item 错误在字段路径中包含数组索引：

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": "not-an-int", "quantity": 2, "unit_price": 9.99},
    {"product_id": 1, "quantity": 1, "unit_price": -5.0}
  ]
}
→ 422
{
  "errors": [
    {"field": "items.0.product_id", "message": "...", "code": "invalid-type"},
    {"field": "items.1.unit_price",  "message": "...", "code": "min-value"}
  ]
}
```

## 单次响应返回所有错误

所有校验失败——包括顶层和嵌套的——都被收集并一起返回。对于批量提交，绝不一次只返回一个错误：

```php
POST /orders
{
  "customer": "",      // 错误：required
  "items": [
    {"product_id": 0, "quantity": -1, "unit_price": 1.0}  // 2 个错误
  ]
}
→ 422
{
  "errors": [
    {"field": "customer",          "code": "required"},
    {"field": "items.0.product_id","code": "min-value"},
    {"field": "items.0.quantity",  "code": "min-value"}
  ]
}
```

## 校验规则

| 字段 | 规则 |
|-------|------|
| `customer` | 必填，非空，最多 200 字符 |
| `items` | 必填，非空数组 |
| `items[].product_id` | 整数，≥ 1 |
| `items[].quantity` | 整数，≥ 1 |
| `items[].unit_price` | 数字（int 或 float），> 0 |

## 实现模式

```php
final class OrderValidator
{
    /** @return list<ValidationError> */
    public function validate(array $data): array
    {
        $errors = [];

        // 顶层校验
        $customer = trim($data['customer'] ?? '');
        if ($customer === '') {
            $errors[] = new ValidationError('customer', 'required', 'required');
        } elseif (strlen($customer) > 200) {
            $errors[] = new ValidationError('customer', 'max 200 chars', 'max-length');
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = new ValidationError('items', 'required non-empty array', 'required');
            return $errors;  // 无法进一步校验 items
        }

        // 带索引的嵌套 item 校验
        foreach ($items as $i => $item) {
            $prefix = "items.{$i}";

            $productId = $item['product_id'] ?? null;
            if (!is_int($productId) || $productId < 1) {
                $errors[] = new ValidationError("{$prefix}.product_id", 'must be int >= 1', 'min-value');
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = new ValidationError("{$prefix}.quantity", 'must be int >= 1', 'min-value');
            }

            $price = $item['unit_price'] ?? null;
            if ((!is_int($price) && !is_float($price)) || $price <= 0) {
                $errors[] = new ValidationError("{$prefix}.unit_price", 'must be number > 0', 'min-value');
            }
        }

        return $errors;
    }
}
```

## 错误代码

| 代码 | 含义 |
|------|---------|
| `required` | 字段缺失或为空 |
| `max-length` | 超过最大长度 |
| `min-value` | 低于最小值（int/float） |
| `invalid-type` | 类型错误（例如期望 int 但收到 string） |

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 只返回第一个错误 | 客户端必须提交、获取错误、修复、再次提交 N 次——对批量表单来说是极差的用户体验 |
| 嵌套 item 用平坦错误路径 `"product_id"` | 客户端无法确定哪个 item（索引 0、1、...）失败了 |
| 静默接受 `unit_price: 0` | 零价格 item 会破坏订单总额 |
| 只在顶层通过后才校验 items | 延迟反馈；应该在一次扫描中收集所有错误 |
| 遇到第一个 item 错误就停止校验 | 掩盖剩余 item 中的进一步错误 |
