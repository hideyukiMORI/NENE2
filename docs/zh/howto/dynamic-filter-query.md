# 操作指南：动态过滤查询（动态 WHERE 子句）

> **相关场景**：DX Scenario 03、18、22、25、29、30、33、37、38、41、47、48——在 50 个 DX 场景中被引用最多的缺失操作指南。

许多列表端点接受可选查询参数并将其转换为 SQL 条件。核心挑战在于：当参数缺失（`null`）时，对应条件必须**完全跳过**——而不是在 SQL 中与 `NULL` 进行比较。

本指南展示 NENE2 各 howto 中使用的标准模式。

---

## 核心模式：`$conditions` 数组 + `implode`

```php
public function search(
    ?string $status,
    ?int    $categoryId,
    ?string $keyword,
): array {
    $conditions = ['deleted_at IS NULL'];   // 必需条件——始终包含
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($categoryId !== null) {
        $conditions[] = 'category_id = ?';
        $bindings[]   = $categoryId;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = '(title LIKE ? OR description LIKE ?)';
        $bindings[]   = "%{$keyword}%";
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC",
        $bindings,
    );
}
```

**为什么这个方法有效**：
- `$conditions` 始终至少有一个元素（必需条件），因此 `implode(' AND ', $conditions)` 永远不会产生空字符串。
- 每个可选块同时追加 SQL 片段和绑定值——两者保持同步。
- 如果所有可选参数都为 `null`，查询简化为 `WHERE deleted_at IS NULL`。

---

## 反模式：`WHERE 1=1`

一种常见替代方法是以 `WHERE 1=1` 作为基础条件，然后始终追加 `AND`：

```php
// 可以工作，但不够清晰：
$where = 'WHERE 1=1';
if ($status !== null) {
    $where    .= ' AND status = ?';
    $bindings[] = $status;
}
```

这也能工作。`$conditions` 数组方式更受推荐，因为它将 SQL 片段与绑定值干净地分离，且更易于单独测试每个条件。

---

## 范围条件：最小值/最大值过滤器

价格范围、日期范围及类似的 `>=` / `<=` 过滤器：

```php
public function searchProperties(
    ?int    $priceMin,
    ?int    $priceMax,
    ?int    $bedroomsMin,
    ?string $dateFrom,
    ?string $dateTo,
): array {
    $conditions = ['status = ?'];
    $bindings   = ['available'];

    if ($priceMin !== null) {
        $conditions[] = 'price_yen >= ?';
        $bindings[]   = $priceMin;
    }

    if ($priceMax !== null) {
        $conditions[] = 'price_yen <= ?';
        $bindings[]   = $priceMax;
    }

    if ($bedroomsMin !== null) {
        $conditions[] = 'bedrooms >= ?';
        $bindings[]   = $bedroomsMin;
    }

    if ($dateFrom !== null) {
        $conditions[] = 'available_from >= ?';
        $bindings[]   = $dateFrom;
    }

    if ($dateTo !== null) {
        $conditions[] = 'available_from <= ?';
        $bindings[]   = $dateTo;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM properties {$where} ORDER BY price_yen ASC",
        $bindings,
    );
}
```

将 `min` 和 `max` 条件分开，而非使用 `BETWEEN`——这允许客户端只提供一个边界（例如"价格不超过 500 万，无下限"）。

---

## 枚举/白名单过滤器

当参数值必须来自固定集合时，在加入 `$conditions` 前先校验：

```php
private const VALID_STATUSES = ['draft', 'published', 'archived'];

public function listByStatus(?string $status): array
{
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    // ...
}
```

即使看起来安全，也**不要**将 `$status` 直接插值到 SQL 字符串中。始终使用绑定参数（`?`），让 PDO 处理转义。

---

## IN 子句：多值过滤器

当客户端可以传递多个值（例如 `?category_ids[]=1&category_ids[]=3`）时：

```php
public function filterByCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return $this->findAll();   // 无过滤——返回所有记录
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    return $this->db->fetchAll(
        "SELECT * FROM products WHERE category_id IN ({$placeholders}) ORDER BY created_at DESC",
        $categoryIds,
    );
}
```

`array_fill(0, count($ids), '?')` 生成正确数量的 `?` 占位符。
绝对不要使用 `implode(',', $categoryIds)` 来构建 `IN (1,2,3)` 字符串——那是 SQL 注入。

关于 AND 语义（匹配**所有**给定标签的条目），请参见 [`multi-value-tag-filter.md`](multi-value-tag-filter.md)。

---

## 安全的 ORDER BY：白名单插值

`ORDER BY` 的列名**不能**使用绑定参数——必须直接插值。始终对白名单进行校验：

```php
private const SORT_COLUMNS  = ['name', 'price_yen', 'created_at'];
private const SORT_DIRECTION = ['asc', 'desc'];

public function list(?string $sortBy, ?string $order): array
{
    $col = in_array($sortBy, self::SORT_COLUMNS, true)  ? $sortBy : 'created_at';
    $dir = in_array($order,  self::SORT_DIRECTION, true) ? $order  : 'desc';

    $conditions = ['deleted_at IS NULL'];

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY {$col} {$dir}",
        [],
    );
}
```

关于 ORDER BY 注入防护的完整说明，请参见 [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md)。

---

## 过滤与分页组合

常见模式——动态过滤 + 游标或偏移量分页：

```php
public function paginatedSearch(
    ?string $status,
    ?string $keyword,
    int     $limit,
    int     $offset,
): array {
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = 'title LIKE ?';
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    // COUNT 查询复用相同的 WHERE
    $total = (int) ($this->db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM products {$where}",
        $bindings,
    )['cnt'] ?? 0);

    // 数据查询追加 LIMIT/OFFSET——不要在 COUNT 之前将其加入 $bindings
    $rows = $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$bindings, $limit, $offset],
    );

    return ['total' => $total, 'items' => $rows];
}
```

先构建过滤条件的 `$bindings`，然后将其展开到 COUNT 查询和数据查询中。仅在数据查询中追加 `$limit` 和 `$offset`。

---

## 解析可选查询参数

使用 `QueryStringParser` 辅助方法从请求中获取空安全的类型化值：

```php
use Nene2\Http\QueryStringParser;

$status     = QueryStringParser::string($request, 'status');     // ?string
$priceMin   = QueryStringParser::int($request, 'price_min');     // ?int
$priceMax   = QueryStringParser::int($request, 'price_max');     // ?int
$keyword    = QueryStringParser::string($request, 'q');          // ?string
$sortBy     = QueryStringParser::string($request, 'sort');       // ?string
$categoryId = QueryStringParser::int($request, 'category_id');   // ?int
```

所有辅助方法在参数缺失或无法解析为目标类型时返回 `null`。将这些可空值直接传给仓库方法——方法会跳过值为 `null` 的条件。

---

## 常见错误

| 错误 | 问题 | 修复方法 |
|------|------|---------|
| `WHERE status = ?` 与 `null` 绑定 | SQLite 会评估 `status = NULL` → 始终为 false（应使用 `IS NULL`） | 值为 `null` 时跳过条件；仅在明确需要 NULL 行时使用 `IS NULL` |
| 无必需条件的 `WHERE 1=1` | 所有可选参数缺失且无租户/所有者过滤时泄露所有行 | 始终包含至少一个必需条件（租户、所有者、deleted_at） |
| 直接插值 `$status` | SQL 注入 | 始终使用 `?` 绑定参数 |
| `IN (implode(',', $ids))` | SQL 注入 | 使用 `array_fill` + `?` 占位符 |
| 在 COUNT 之前将 `LIMIT`/`OFFSET` 加入 `$bindings` | COUNT 得到错误结果 | 先构建过滤 `$bindings`；展开到 COUNT，然后为数据查询追加 LIMIT/OFFSET |

---

## 相关操作指南

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) ——N:M 标签过滤的 AND/OR 语义（`HAVING COUNT(DISTINCT)`）
- [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) ——带白名单的安全 ORDER BY
- [`add-pagination.md`](add-pagination.md) ——与偏移量/游标分页结合
- [`contact-management.md`](contact-management.md) ——带 LIKE + EXISTS 过滤器的完整示例
