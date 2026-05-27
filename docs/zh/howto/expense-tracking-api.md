# 操作指南：支出追踪 API

> **FT 参考**：FT311（`NENE2-FT/expenselog`）——支出追踪：YYYY-MM-DD 日期格式校验，分类为开放字符串（无枚举），按类别月度汇总聚合，带 limit/offset 的偏移分页，PATCH 部分更新（仅更新提供的字段），日期范围过滤，静态路由 `/summary` 注册在动态 `/{id}` 之前，34 个测试 / 67 个断言全部通过。

本指南展示如何构建支持日期过滤、类别聚合、分页和部分更新的支出追踪 API。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        TEXT    NOT NULL,  -- YYYY-MM-DD
    amount      INTEGER NOT NULL,  -- 分为单位
    category    TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);
```

`date` 和 `category` 上的索引支持快速过滤。`amount` 使用整数分为单位以避免浮点精度问题。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `GET` | `/expenses` | 列出支出（可选过滤 + 分页） |
| `POST` | `/expenses` | 创建支出 |
| `GET` | `/expenses/summary` | 按类别的月度汇总 |
| `GET` | `/expenses/{id}` | 获取单条支出 |
| `PATCH` | `/expenses/{id}` | 部分更新 |
| `DELETE` | `/expenses/{id}` | 删除 |

**路由顺序**：`/expenses/summary` 必须在 `/expenses/{id}` **之前**注册——否则 `summary` 会被捕获为 `id` 参数。

## 日期校验——YYYY-MM-DD

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

仅接受严格的 `YYYY-MM-DD` 格式。带时间组件或时区偏移的 ISO 8601 字符串会被拒绝。

## 类别——开放字符串（无枚举）

```php
$category = isset($body['category']) && is_string($body['category']) && $body['category'] !== ''
    ? $body['category']
    : null;
if ($category === null) {
    $errors[] = new ValidationError('category', 'category is required.', 'required');
}
```

类别是自由格式字符串（非封闭枚举）。任何非空字符串均有效，允许使用 `"food"`、`"transport"`、`"entertainment"` 等类别，无需修改 schema。

## 月度汇总——YYYY-MM 格式

```php
// 查询：SELECT category, SUM(amount), COUNT(*) WHERE date LIKE 'YYYY-MM-%'
$summary = $this->repository->monthlySummary($month);

return $this->json->create([
    'month'       => $summary->month,
    'total'       => $summary->total,
    'count'       => $summary->count,
    'by_category' => $summary->byCategory, // [{category, total, count}, ...]
]);
```

月份参数格式：`YYYY-MM`。聚合该月所有支出，按类别分组。

## 分页——基于偏移量

```php
$pagination = PaginationQueryParser::parse($request);
// 返回：{ limit: int, offset: int }

$items = $this->repository->findAll($from, $to, $category, $pagination->limit, $pagination->offset);
$total = $this->repository->count($from, $to, $category);

return $this->json->create([
    'items'  => $items,
    'total'  => $total,
    'limit'  => $pagination->limit,
    'offset' => $pagination->offset,
]);
```

无效的 `limit`（非整数、负数、过大）→ 422。

## PATCH——部分更新

```php
// 仅更新请求体中存在的字段
$date     = array_key_exists('date', $body)     ? $body['date']     : null;
$amount   = array_key_exists('amount', $body)   ? $body['amount']   : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$note     = array_key_exists('note', $body)     ? $body['note']     : null;
```

`array_key_exists()` 能区分"字段未提供"和"字段值为 null"。只有提供的字段才会被校验和更新。

## 日期范围过滤

```php
// GET /expenses?date_from=2026-01-01&date_to=2026-01-31
$from = QueryStringParser::string($request, 'date_from');
$to   = QueryStringParser::string($request, 'date_to');
$category = QueryStringParser::string($request, 'category');

$items = $this->repository->findAll($from, $to, $category, $limit, $offset);
```

所有过滤参数均为可选。null 表示"该维度不过滤"。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 在 `/expenses/summary` 之前注册 `/expenses/{id}` | `"summary"` 被匹配为 `id`；summary 端点不可达 |
| 将 `amount` 存储为 FLOAT | 浮点精度问题：`0.1 + 0.2 ≠ 0.3`；应使用整数分 |
| 接受任意日期字符串（带时间的 ISO 8601） | WHERE 子句中日期比较不一致 |
| 封闭类别枚举 | 新类别需要数据库迁移 |
| PATCH 使用 `isset($body['field'])` | `isset()` 对 `null` 值返回 false；应使用 `array_key_exists()` |
| count 查询与 list 查询过滤条件不同 | 分页总数与实际过滤数量不匹配 |
| date/category 无索引 | 每次过滤列表请求都进行全表扫描 |
