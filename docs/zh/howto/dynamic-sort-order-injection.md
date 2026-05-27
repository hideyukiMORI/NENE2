# 操作指南：动态排序与过滤——ORDER BY 注入防护

> **FT 参考**：FT341（`NENE2-FT/sortlog`）——带 SQL ORDER BY 注入防护的动态排序/过滤 API（通过白名单），状态过滤白名单，ReDoS 免疫的 O(n) 校验，40+ 个测试覆盖 VULN-A 至 VULN-L 及 ATK-01 至 ATK-12，全部通过。

本指南演示如何安全地实现可排序、可过滤的列表端点。由于 SQL 中的 `ORDER BY` 不能使用参数化占位符，列名和排序方向必须在插值前针对严格白名单进行校验。

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft',
    created_at TEXT    NOT NULL
);
```

## 端点

```
GET /articles?sort=created_at&order=desc&status=published&limit=20
```

### 有效参数

| 参数 | 允许值 | 默认值 |
|------|--------|--------|
| `sort` | `id`、`title`、`status`、`created_at` | `created_at` |
| `order` | `asc`、`desc` | `desc` |
| `status` | `draft`、`published`、`archived` | （全部） |
| `limit` | 1–100 | 20 |

## 响应

```php
GET /articles?sort=title&order=asc&status=published
→ 200
{
  "data": [
    {"id": 2, "title": "Alpha", "status": "published", ...},
    {"id": 1, "title": "Beta",  "status": "published", ...}
  ],
  "total": 2,
  "sort": "title",
  "order": "asc"
}
```

## 白名单校验——唯一安全的模式

`ORDER BY` 子句**不能使用参数化绑定值**。列名必须直接插值到 SQL 中。这使得白名单校验成为强制要求。

```php
private const SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
private const SORT_ORDERS  = ['asc', 'desc'];
private const STATUSES     = ['draft', 'published', 'archived'];

public function parseSort(string $sort, string $order): array
{
    // 白名单中的精确字符串匹配——O(n)，区分大小写，无正则表达式
    if (!in_array($sort, self::SORT_COLUMNS, true)) {
        throw new ValidationException("Invalid sort column: {$sort}");
    }
    if (!in_array($order, self::SORT_ORDERS, true)) {
        throw new ValidationException("Invalid sort direction: {$order}");
    }
    return [$sort, $order];
}

public function parseStatus(?string $status): ?string
{
    if ($status === null) {
        return null;  // 无过滤
    }
    if (!in_array($status, self::STATUSES, true)) {
        throw new ValidationException("Invalid status: {$status}");
    }
    return $status;
}
```

**为什么用 `in_array()` 而非正则表达式：**
- `in_array($v, $list, true)` 是 O(n)——对 ReDoS 免疫
- 对攻击者控制的 50 字符载荷使用 `/^[a-z_]+$/` 正则可能导致灾难性回溯
- 严格第三个参数（`true`）启用类型安全比较

### 大小写敏感性

白名单设计为区分大小写：

```php
GET /articles?sort=ID       → 422  // 'ID' 不在白名单中
GET /articles?sort=TITLE    → 422
GET /articles?sort=Created_At → 422
GET /articles?sort=created_at → 200  ✅ 精确匹配
```

## 查询构建

```php
$sql = 'SELECT * FROM articles';

if ($status !== null) {
    // 状态使用参数化占位符（安全）
    $sql .= ' WHERE status = ?';
    $params[] = $status;
}

// 排序列和方向来自白名单——可以安全插值
$sql .= " ORDER BY {$sort} {$order}";
$sql .= ' LIMIT ?';
$params[] = $limit;
```

`ORDER BY` 使用来自白名单的插值；`WHERE` 子句的值始终使用 `?` 占位符。

## 被拒绝的载荷

### 注入模式 → 422

```php
// sort 中的 SQL 注入
?sort='; DROP TABLE articles--             → 422
?sort=id UNION SELECT 1,2,3,4,5           → 422
?sort=(SELECT name FROM sqlite_master)    → 422
?sort=CASE WHEN 1=1 THEN id ELSE title END → 422
?sort=created_at--                        → 422  // 注释
?sort=created_at%00                       → 422  // 空字节
?sort=1                                   → 422  // 列索引（不在白名单中）

// 方向注入
?order=asc; UNION SELECT 1,2,3--          → 422
?order=DESC;                              → 422

// status 过滤注入
?status=' OR '1'='1                       → 422
?status=draft UNION SELECT 1,2--          → 422
?status=1                                 → 422  // 必须是精确的状态名
?status=TRUE                              → 422

// 空白字符绕过
?sort=created_at%09                       → 422  // TAB
?sort= created_at                         → 422  // 前导空格

// 数组注入（PSR-7）
?sort[]=created_at                        → 422  // 数组，而非字符串
```

### Limit 注入 → 422

```php
?limit=999999           → 422  // 超出 MAX_LIMIT=100
?limit=9999999999999999999999  → 422  // 溢出（strlen > 18）
?limit=-1               → 422  // 负数
?limit=10.5             → 422  // 浮点数
```

### 有效请求 → 200

```php
GET /articles                                  → 200  // 使用默认值
GET /articles?sort=title&order=asc             → 200
GET /articles?sort=id&order=desc&status=draft  → 200
GET /articles?limit=50                         → 200
```

## 响应时间安全性

每次拒绝都是瞬间完成（< 100ms）。白名单检查使用 `in_array()`，第一次不匹配时即短路——无正则表达式回溯：

```php
// ReDoS 载荷："aaaa...a!"（50 个 'a' + '!'）
// in_array("aaaa...a!", ['id','title','status','created_at'], true) → 立即返回 false
```

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 直接插值 `?sort=`：`ORDER BY $sort` | SQL 注入——攻击者完全控制 `ORDER BY` 子句 |
| 仅用正则 `/^[a-z_]+$/` 校验 | 50+ 字符载荷导致 ReDoS；允许 `password` 等未知列名 |
| 大小写不敏感比较（`strcasecmp`） | `ORDER BY CREATED_AT` 是有效 SQL，但绕过了大小写敏感测试 |
| 将 `ORDER BY $sort` 参数化为绑定值 | 大多数 DB 驱动静默地将其视为字面量或抛出错误 |
| 只校验 `sort`，不校验 `order` 方向 | `order=asc; UNION SELECT ...` 绕过列检查 |
| 在 PSR-7 解析后信任 `sort[]` 数组 | 用数组注入的 `implode(', ', $sort)` 产生多列 ORDER BY |
| 省略 `status` 过滤白名单 | `status=admin' OR '1'='1` 变为 `WHERE status = 'admin' OR '1'='1'` |
