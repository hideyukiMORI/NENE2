# 如何添加管理员报表聚合

构建仪表板风格的聚合端点，支持日期范围过滤、分组和限制截断。

## 数据库结构

```sql
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT NOT NULL, item_name TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','refunded','cancelled')),
    created_at TEXT NOT NULL
);
```

## 路由

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/orders` | 插入订单 |
| `GET` | `/reports/summary` | 总订单数、收入、平均值、已完成数量 |
| `GET` | `/reports/daily` | 按日期分组的订单 |
| `GET` | `/reports/by-status` | 按状态分组的订单 |
| `GET` | `/reports/top-items` | 按收入排名前 N 的商品 |

查询参数（所有报表）：`from=YYYY-MM-DD`、`to=YYYY-MM-DD`

## 日期范围过滤（安全参数化）

动态构建 WHERE 子句，以绑定参数传递值——绝不进行字符串拼接：

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) { $conditions[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $conditions[] = 'created_at <= ?'; $params[] = $to; }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

## 日期验证（防止注入）

在日期到达查询之前，拒绝非 ISO-8601 格式的日期：

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // 拒绝 2026-13-01
}
```

拒绝 `from > to`：

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## 限制截断

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
if ($limit <= 0) { /* 422 */ }
```

## 聚合查询

**汇总**：
```sql
SELECT COUNT(*) AS total_orders,
       COALESCE(SUM(amount), 0) AS total_revenue,
       COALESCE(AVG(amount), 0) AS avg_order_value,
       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
FROM orders {where}
```

**按日分解**（SQLite 日期子串）：
```sql
SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY date ORDER BY date ASC
```

**热门商品**：
```sql
SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY item_name ORDER BY revenue DESC LIMIT ?
```

## 安全说明

- 所有查询参数在使用前经过验证——通过 `from`/`to`/`limit` 的 SQL 注入会返回 422。
- 商品名称和客户 ID 通过参数化查询存储——特殊字符和注入尝试均作为字面字符串处理。
- `COALESCE(SUM(...), 0)` 防止在没有行匹配时汇总结果中出现 NULL。
- 限制截断至 `MAX_LIMIT`——防止因巨大的 `LIMIT` 值导致资源耗尽。
