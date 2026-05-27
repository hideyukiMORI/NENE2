# 操作指南：SQL 注入防御

> **FT 参考**：FT264（`NENE2-FT/injectionlog`）— SQL 注入防御：参数化查询、LIKE 注入、ORDER BY 白名单
> **ATK**：FT264 — 攻击者思维攻击测试（ATK-01 到 ATK-12）

演示 PHP API 中三种主要 SQL 注入向量——值注入、LIKE 通配符注入和 ORDER BY 列注入——以及每种情况的正确防御方式。包含完整的攻击者思维攻击评估。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `GET` | `/products` | 列出/搜索商品（可过滤，可排序） |
| `POST` | `/products` | 创建商品 |
| `GET` | `/products/{id}` | 获取单个商品 |
| `DELETE` | `/products/{id}` | 删除商品 |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    category    TEXT    NOT NULL,
    price       REAL    NOT NULL DEFAULT 0.0,
    description TEXT    NOT NULL DEFAULT ''
);
```

---

## 三种 SQL 注入攻击面

### 1. 值注入：参数化查询

```php
// ❌ 字符串插值——可注入
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ 参数化——驱动程序转义所有值
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

PDO 的 `?` 占位符将值绑定为类型化参数。值永远不会被插入到 SQL 字符串中。发送 `id = "1; DROP TABLE products; --"` 的攻击者，其全部输入都以字面字符串绑定存储——SQL 不会被修改。

### 2. LIKE 通配符注入：参数化通配符

```php
// ❌ 插值 LIKE——可注入且存在通配符问题
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ 参数化通配符——? 值在 || 拼接之后绑定
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` 是标准 SQL 字符串拼接（SQLite、PostgreSQL）。`?` 值作为参数绑定——`%` 通配符是 SQL 字符串中的字面量，不来自用户输入。

**LIKE 元字符转义**：此实现中用户输入 `$q` 内的 `%` 和 `_` 未被转义。搜索 `%` 会匹配所有内容。生产环境中应转义 LIKE 元字符：

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. ORDER BY 注入：列白名单

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // 安全：白名单列名 + 规范化方向

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` 不能使用参数化占位符——列名必须被插入。正确的防御是显式白名单：只有 `ALLOWED_SORT_FIELDS` 中的值才可以出现在 SQL 字符串中。任何其他值都抛出异常（控制器中返回 400）。

`sortDir` 被精确映射到 `'ASC'` 或 `'DESC'`——用户输入永远不会直接插入。

---

## ATK——攻击者思维攻击测试（FT264）

### ATK-01 — 通过 GET 参数的经典 SELECT 注入

**攻击**：通过搜索查询 `?q=' OR '1'='1` 注入 SQL。

```
GET /products?q=' OR '1'='1
```

**观察结果**：`$q` 在 `LIKE '%' || ? || '%'` 中作为 `?` 参数绑定。整个字符串 `' OR '1'='1` 被视为要匹配的字面文本值。不返回额外的行。

**结论**：**BLOCKED** — 参数化 LIKE 防止值注入。

---

### ATK-02 — 通过搜索的 DROP TABLE 注入

**攻击**：注入破坏性语句。

```
GET /products?q='; DROP TABLE products; --
```

**观察结果**：负载作为 LIKE 模式绑定。`'; DROP TABLE products; --` 作为字面文本搜索。表未被删除。

**结论**：**BLOCKED** — 参数化查询无法执行注入的语句。

---

### ATK-03 — ORDER BY 列注入：任意列

**攻击**：注入未知的排序列。

```
GET /products?sort=password
```

**观察结果**：`in_array('password', self::ALLOWED_SORT_FIELDS, true)` 返回 `false`。抛出 `InvalidSortFieldException`。控制器捕获后返回 400。

**结论**：**BLOCKED** — 列白名单拒绝未知列名。

---

### ATK-04 — ORDER BY 注入：子查询注入

**攻击**：将子查询注入为排序列。

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**观察结果**：解码后的值 `(SELECT name FROM users LIMIT 1)` 不在 `ALLOWED_SORT_FIELDS` 中。抛出 `InvalidSortFieldException`。返回 400。

**结论**：**BLOCKED** — 白名单拒绝不在已知列表中的任何值，包括子查询。

---

### ATK-05 — ORDER BY 注入：方向篡改

**攻击**：通过排序方向参数注入 SQL。

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**观察结果**：`strtolower($sortDir) === 'desc'` 对注入值为 `false`。方向降级为 `'ASC'`。注入的 SQL 永远不会被插入。返回 200，商品按 ASC 排序。

**结论**：**BLOCKED** — 方向被精确映射到 `'ASC'` 或 `'DESC'`，永远不直接插入。

---

### ATK-06 — 通过搜索查询的 UNION 注入

**攻击**：注入 `UNION SELECT` 以提取数据。

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**观察结果**：完整的注入字符串作为 LIKE 参数值绑定。`UNION SELECT` 在 `name` 和 `description` 列中作为字面文本搜索。不返回用户数据。

**结论**：**BLOCKED** — 参数化查询防止 UNION 注入。

---

### ATK-07 — 通过路径参数的 ID 注入

**攻击**：通过路径参数注入 SQL。

```
GET /products/1;%20DROP%20TABLE%20products;
```

**观察结果**：路径参数 `{id}` 通过 `(int) $params['id']` 转换为整数。SQL 变为 `WHERE id = 1`——注入后缀被强制转换截断。表未被删除。

**结论**：**BLOCKED** — `(int)` 强制转换在第一个非数字字符处截断。

---

### ATK-08 — 通过搜索的基于布尔值的盲注

**攻击**：通过布尔条件泄露数据。

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**观察结果**：两个字符串都作为 LIKE 参数绑定。两个查询都返回名称或描述中包含字面文本 `' AND '1'='1` 的商品。两者都不修改 SQL WHERE 逻辑。两者返回相同（空）结果集。

**结论**：**BLOCKED** — 参数化绑定防止布尔注入。

---

### ATK-09 — 二阶注入：存储的负载稍后被检索

**攻击**：创建名称包含 SQL 的商品，然后搜索所有商品。

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**观察结果**：`INSERT` 使用参数化 `?`——注入负载以字面文本存储。`SELECT *` 和 `LIKE` 查询也使用参数化查询。负载作为字符串值返回，永远不会作为 SQL 执行。

**结论**：**BLOCKED** — 所有读写路径都使用参数化查询。

---

### ATK-10 — LIKE 元字符洪水：`%` 搜索

**攻击**：发送 `?q=%` 以匹配所有商品，绕过预期的空搜索默认行为。

```
GET /products?q=%25   （URL 解码后：%）
```

**观察结果**：`$q = '%'` 作为 LIKE 参数绑定。`LIKE '%' || '%' || '%'` = `LIKE '%%%'`，匹配每一行。所有商品都被返回。

**结论**：**EXPOSED** — 用户输入中的 `%` 和 `_` 未被转义。搜索 `%` 匹配所有内容；搜索 `_` 匹配任意单个字符。转义 LIKE 元字符，或将此行为记录为有意为之。

---

### ATK-11 — 空字节注入

**攻击**：在搜索查询中嵌入空字节。

```
GET /products?q=widget%00extra
```

**观察结果**：PHP 的 `?` 绑定将包含空字节的原始字符串传递给 SQLite 的参数化查询。SQLite 将空字节视为字符串的一部分。`LIKE '%widget\0extra%'` 不匹配正常商品名称。不发生注入。

**结论**：**BLOCKED** — 参数化查询将空字节作为字面字符串内容处理。

---

### ATK-12 — 堆叠查询（多语句注入）

**攻击**：在分号后注入第二个语句。

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**观察结果**：PDO 每次 `query()`/`prepare()` 调用只执行一条语句——默认不支持堆叠查询。即使 PDO 允许多条语句，值也作为参数绑定（不插入）。注入的 INSERT 作为字面 LIKE 搜索文本存储。

**结论**：**BLOCKED** — 参数化绑定 + PDO 单语句模式防止堆叠查询。

---

## ATK 汇总

| # | 攻击向量 | 结论 |
|---|---------|------|
| ATK-01 | 通过 `?q=` 的经典 SELECT 注入 | BLOCKED |
| ATK-02 | 通过搜索的 DROP TABLE | BLOCKED |
| ATK-03 | ORDER BY 未知列 | BLOCKED |
| ATK-04 | ORDER BY 子查询注入 | BLOCKED |
| ATK-05 | 排序方向注入 | BLOCKED |
| ATK-06 | 通过搜索的 UNION SELECT | BLOCKED |
| ATK-07 | 通过路径参数的 ID 注入 | BLOCKED |
| ATK-08 | 基于布尔值的盲注 | BLOCKED |
| ATK-09 | 二阶注入 | BLOCKED |
| ATK-10 | LIKE 元字符洪水（`%`） | EXPOSED |
| ATK-11 | 空字节注入 | BLOCKED |
| ATK-12 | 堆叠查询 | BLOCKED |

**生产前需修复的真实漏洞**：
1. **ATK-10** — 绑定前转义 LIKE 元字符（`%`、`_`、`\`），防止通配符洪水。

---

## 防御汇总

| 攻击面 | 脆弱模式 | 安全模式 |
|---|---|---|
| WHERE 中的值 | `WHERE id = {$id}` | `WHERE id = ?` 配合 `[$id]` |
| LIKE 搜索 | `WHERE name LIKE '%{$q}%'` | `WHERE name LIKE '%' \|\| ? \|\| '%'` |
| ORDER BY 列 | `ORDER BY {$sortField}` | `in_array($sortField, ALLOWED, true)` + 白名单后插入 |
| ORDER BY 方向 | `ORDER BY col {$dir}` | `$dir === 'desc' ? 'DESC' : 'ASC'` |
| 路径参数 ID | `WHERE id = {$id}` | `(int) $id` + 参数化 |

---

## 相关指南

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — 显式 DTO 白名单作为更广泛的防御模式
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 作为 LIKE 全文搜索的替代方案
- [`jwt-authentication.md`](jwt-authentication.md) — 包含 SQL 注入的 VULN 评估（V-08）
