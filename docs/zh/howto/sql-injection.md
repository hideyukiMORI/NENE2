# SQL 注入防御

NENE2 的数据库方法（`execute`、`insert`、`fetchOne`、`fetchAll`）内部使用 PDO 预处理语句。传入 `$parameters` 数组的任何值都作为 PDO 参数绑定——永远不会被插入到 SQL 字符串中。

## 默认安全：值参数

```php
// 所有值都通过 PDO 绑定——无论内容如何都是注入安全的
$product = $this->db->fetchOne(
    'SELECT * FROM products WHERE id = ?',
    [$userId],
);

// LIKE 搜索——通配符在 SQL 字面量中，值单独绑定
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$searchQuery],
);
```

经典负载（`' OR '1'='1`、`'; DROP TABLE products; --`、`UNION SELECT ...`）变成字面搜索字符串，因为 PDO 永远不会将它们插入到 SQL 中。

## ORDER BY 陷阱——需要白名单

**PDO 不能参数化列名或 SQL 结构元素。** `ORDER BY ?` 不起作用——它绑定的是字面字符串值，而非列引用。

如果开发者将用户输入直接放入 `ORDER BY`，就成了注入向量：

```php
// 不安全——永远不要这样做
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
$rows = $this->db->fetchAll("SELECT * FROM products ORDER BY {$sort} ASC");
// ?sort=id;+DROP+TABLE+products;+-- 会执行 DROP
```

**插入列名之前始终根据显式白名单进行验证：**

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'price', 'created_at'];

public function list(string $sortField, string $sortDir): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    // 只有 ASC 或 DESC——规范化，永远不直接插入原始用户输入
    $dir  = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $rows = $this->db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortField} {$dir}",
    );

    return $rows;
}
```

同样的原则适用于任何 SQL 结构元素：表名、`GROUP BY` 中的列名、`HAVING`、`INSERT INTO ... (col1, col2)`——这些都不能作为 PDO 参数绑定。白名单验证后再插入。

## 可变长度的 IN 子句

PDO 不支持直接绑定可变长度列表。显式构建占位符列表：

```php
$ids          = [1, 2, 3];
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$rows         = $this->db->fetchAll(
    "SELECT * FROM products WHERE id IN ({$placeholders})",
    $ids,
);
```

## 汇总

| 输入类型 | 安全方式 |
|---|---|
| 过滤值（`WHERE col = ?`） | `$parameters` 中的 `?` 占位符 |
| LIKE 值 | `'%' \|\| ? \|\| '%'` — `$parameters` 中的值 |
| ORDER BY 列 | 白名单 `in_array` + 通过后才插入 |
| ORDER 方向 | 规范化为字面量 `'ASC'` 或 `'DESC'` |
| IN 列表 | 从 `count()` 构建 `?` 占位符，将数组展开为参数 |
| 表/列名 | 仅白名单——永远不从用户输入接受 |
