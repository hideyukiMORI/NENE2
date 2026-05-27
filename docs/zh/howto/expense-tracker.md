# 操作指南：支出追踪 API

本指南演示如何使用 NENE2 构建个人支出追踪系统，支持按类别过滤、日期范围查询、月度汇总聚合和完整的 CRUD 操作。
模式由 **expenselog** 字段试验（FT223）演示。

## 功能

- 创建、读取、更新、删除支出（日期、金额、类别、备注）
- 按日期范围（`?from=` / `?to=`）和类别过滤的列表
- 月度汇总聚合（每类别每月合计）
- 带总数的分页
- 类别白名单校验
- 金额校验：正整数（分为单位）

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category, date DESC);
```

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `GET` | `/expenses` | 列出支出（可过滤，分页） |
| `POST` | `/expenses` | 创建支出 |
| `GET` | `/expenses/summary` | 按类别的月度汇总 |
| `GET` | `/expenses/{id}` | 获取单条支出 |
| `PATCH` | `/expenses/{id}` | 部分更新 |
| `DELETE` | `/expenses/{id}` | 删除支出 |

## 校验模式

### 金额（正整数，以分为单位存储）

```php
if (!is_int($amount) || $amount <= 0) {
    $errors[] = new ValidationError('amount', 'Amount must be a positive integer (cents).');
}
```

使用 `is_int()` 拒绝来自 JSON 的浮点数（在 PHP 严格模式下 `1.5` 不是整数）。

### 日期（ISO 8601 格式）

```php
$date = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    $errors[] = new ValidationError('date', 'date must be a valid YYYY-MM-DD date.');
}
```

往返校验：解析后重新格式化——保证字符串是规范形式。

### 类别白名单

```php
private const array VALID_CATEGORIES = [
    'food', 'transport', 'utilities', 'entertainment',
    'health', 'shopping', 'travel', 'other',
];

if (!in_array($category, self::VALID_CATEGORIES, true)) {
    $errors[] = new ValidationError('category', 'Invalid category.');
}
```

## 日期范围过滤

```php
public function findAll(
    ?string $from = null,
    ?string $to = null,
    ?string $category = null,
    int $limit = 20,
    int $offset = 0,
): array
```

过滤器是可选的——省略则查询全部时间段。日期按字典序比较（ISO 8601 字符串在 UTC 下可排序）。

## 月度汇总查询

使用 SQLite 的 `strftime` 按年月聚合：

```sql
SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
FROM expenses
WHERE date BETWEEN :from AND :to
GROUP BY month, category
ORDER BY month DESC, category ASC
```

返回每月每类别的合计：

```json
{
  "summary": [
    { "month": "2026-05", "category": "food", "total": 42000, "count": 12 },
    { "month": "2026-05", "category": "transport", "total": 8500, "count": 5 }
  ]
}
```

## PATCH 部分更新

只有请求体中提供的字段才会被更新——缺失的字段保留当前值：

```php
if (isset($body['amount'])) {
    if (!is_int($body['amount']) || $body['amount'] <= 0) {
        $errors[] = new ValidationError('amount', 'Amount must be a positive integer.');
    } else {
        $updates['amount'] = $body['amount'];
    }
}
// 对 date、category、note 使用相同模式
```

## 校验模式总结

| 字段 | 检查 | 原因 |
|------|------|------|
| `amount` | `is_int() && > 0` | 拒绝浮点数、零、负数 |
| `date` | 往返 `Y-m-d` 解析 | 仅允许规范的 ISO 8601 格式 |
| `category` | `in_array(strict: true)` | 防止拼写错误和注入 |
| `limit` / `offset` | `max(1, min(100, $limit))` | 防止 DoS 和 SQL 注入 |
