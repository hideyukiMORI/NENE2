# 操作指南：聚合报表 API

> **FT 参考**：FT245（`NENE2-FT/agglog`）——聚合报表 API

演示一个多维聚合报表 API，将单一订单表切分为汇总总量、每日明细、状态分布和热门商品——全部支持可选的日期范围过滤，使用 `COALESCE` 实现零安全聚合，以及 `COUNT(CASE WHEN...)` 实现无子查询的条件计数。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|----------------------|------------------------------------------------------|
| `POST` | `/orders` | 记录订单 |
| `GET` | `/reports/summary` | 总订单数、收入、平均订单金额、已完成数量 |
| `GET` | `/reports/daily` | 每日收入和订单数量 |
| `GET` | `/reports/by-status` | 按状态分组的订单数量和收入 |
| `GET` | `/reports/top-items` | 按收入排名的热门商品（有限制，已排名） |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
```

`status` 通过数据库层面的 `CHECK` 约束作为安全网。`amount` 以整数存储（最小货币单位）。`created_at` 是 ISO 字符串——日期比较使用 `YYYY-MM-DD` 格式的字符串排序，该格式的字典序与时间顺序一致。

---

## 汇总聚合：`COALESCE` + `COUNT(CASE WHEN ...)`

汇总端点在单个查询中返回多个聚合指标：

```php
$row = $this->db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(AVG(amount), 0) AS avg_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
     FROM orders {$where}",
    $params,
);
```

`COALESCE(SUM(amount), 0)` ——当表中没有匹配行时返回 `0` 而非 `NULL`。`SUM()` 和 `AVG()` 对空集返回 `NULL`；`COALESCE` 将其转换为安全的零值。

`COUNT(CASE WHEN status = 'completed' THEN 1 END)` ——无需子查询或第二次扫描，只计算 `status = 'completed'` 的行。`CASE WHEN` 对不匹配的行返回 `NULL`；`COUNT` 忽略 `NULL`，因此只统计已完成的订单。

这等价于过滤型 `COUNT`，但在单次扫描中运行，比针对每个状态分别查询更高效。

---

## 每日明细：用 `substr()` 截断日期

```php
$rows = $this->db->fetchAll(
    "SELECT substr(created_at, 1, 10) AS date,
            COUNT(*) AS order_count,
            SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY date
     ORDER BY date ASC",
    $params,
);
```

`substr(created_at, 1, 10)` 从 ISO 日期时间字符串中提取前 10 个字符（`YYYY-MM-DD`），将同一日历日的所有事件分组。这是 SQLite 中 `strftime('%Y-%m-%d', created_at)` 的替代方案，适用于固定前缀的 ISO 8601 格式时间戳字符串。

`GROUP BY date` 使用别名——SQLite 支持在 `GROUP BY` 中使用别名（与某些需要重复表达式的其他数据库不同）。

---

## 状态分布：`GROUP BY status ORDER BY count DESC`

```php
$rows = $this->db->fetchAll(
    "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY status
     ORDER BY order_count DESC",
    $params,
);
```

`ORDER BY order_count DESC` 将最常见的状态排在第一位。结果集中的行数最多等于不同状态值的数量（本数据库结构中为四个）。

---

## 热门商品：按收入排名并限制数量

```php
$rows = $this->db->fetchAll(
    "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY item_name
     ORDER BY revenue DESC
     LIMIT ?",
    $params,
);
```

`ORDER BY revenue DESC LIMIT ?` ——参数化的 `LIMIT` 按总收入选取前 N 个商品。`limit` 路径参数在服务器端被截断：

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
```

`min(..., MAX_LIMIT)` 防止客户端请求超过 100 个商品。注意：此处使用 `is_numeric($q['limit'])` 而非 `is_int`，因为查询字符串的值始终是字符串——`is_int` 对查询字符串输入永远返回 false。

---

## 动态 `WHERE` 子句与 `dateFilter()`

所有聚合查询共用一个 `dateFilter()` 辅助方法，仅在提供了日期边界时才追加条件：

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) {
        $conditions[] = 'created_at >= ?';
        $params[]     = $from;
    }
    if ($to !== null) {
        $conditions[] = 'created_at <= ?';
        $params[]     = $to;
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

当 `from` 和 `to` 都为 `null` 时，`$where` 为 `''`——扫描整张表。调用者在查询执行前将 `{$where}` 嵌入 SQL 字符串。实际值仍然是参数化的（`?`）——只有 `WHERE` 关键字部分通过字符串插值。

---

## 日期验证：`createFromFormat()` 往返验证

将 `from` 和 `to` 作为 YYYY-MM-DD 字符串接受时，需要验证日期是否格式正确且语义有效（例如，`2026-02-30` 应被拒绝）：

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
```

两步验证：
1. `preg_match` ——快速拒绝不匹配格式的输入，无需创建日期对象。
2. `createFromFormat` + 往返 `format()` ——检测语义无效的日期，如 `2026-02-30`（如果只用正则验证，PHP 会将其溢出为 `2026-03-02`）。

日期范围方向也会被验证：
```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

由于两个日期都是 `YYYY-MM-DD` 格式，字符串比较在这里正确有效——该格式的字典序与时间顺序一致。

---

## 使用的 NENE2 内置功能

| 内置功能 | 用途 |
|---|---|
| `ValidationException` / `ValidationError` | 带 `errors` 数组的结构化 `422` 响应 |
| `JsonResponseFactory::create()` | 编码 JSON 响应 |
| `Router` 常量 | `PARAMETERS_ATTRIBUTE` 用于路径参数 |

---

## 相关操作指南

- [`event-analytics-api.md`](event-analytics-api.md) ——带 `json_extract()`、`COUNT(DISTINCT)` 分组的 JSON blob 分析
- [`cqrs-pattern.md`](cqrs-pattern.md) ——使用 SQL VIEW 作为订单聚合的读模型
- [`credit-ledger.md`](credit-ledger.md) ——`COALESCE(SUM(amount * direction), 0)` 余额计算
- [`admin-report-aggregation.md`](admin-report-aggregation.md) ——管理员范围的聚合模式
