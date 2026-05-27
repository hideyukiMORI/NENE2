# 全文搜索与自动补全 API 实现指南

## 概述

本指南说明如何使用 NENE2 实现全文搜索和自动补全端点。提供跨多字段的 LIKE 搜索、相关度评分和前缀补全 REST API。

---

## 数据库结构

```sql
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL,
    price_cents INTEGER NOT NULL DEFAULT 0 CHECK (price_cents >= 0),
    created_at TEXT NOT NULL
);
```

---

## 端点设计

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/search` | 全文搜索 |
| GET | `/autocomplete` | 名称前缀补全 |

### 查询参数

**GET /search**

| 参数 | 必填 | 默认值 | 说明 |
|---|---|---|---|
| `q` | ✓ | — | 搜索查询（2~100 个字符） |
| `category` | — | — | 分类过滤 |
| `limit` | — | 10 | 最大 50 |
| `offset` | — | 0 | 分页 |

**GET /autocomplete**

| 参数 | 必填 | 默认值 | 说明 |
|---|---|---|---|
| `q` | ✓ | — | 前缀（2~100 个字符） |
| `limit` | — | 5 | 最大 10 |

---

## 实现

### SearchRepository

```php
class SearchRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $query, ?string $category, int $limit, int $offset): array
    {
        $lq = strtolower($query);
        $escaped = $this->escapeLike($lq);
        $pattern = '%' . $escaped . '%';
        $prefix  = $escaped . '%';

        $whereConditions = [
            "LOWER(name) LIKE ? ESCAPE '!'",
            "LOWER(description) LIKE ? ESCAPE '!'",
            "LOWER(category) LIKE ? ESCAPE '!'",
        ];
        $whereParams = [$pattern, $pattern, $pattern];
        $whereClause = 'WHERE (' . implode(' OR ', $whereConditions) . ')';

        if ($category !== null) {
            $whereClause .= ' AND LOWER(category) = ?';
            $whereParams[] = strtolower($category);
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM products ' . $whereClause,
            $whereParams
        ) ?? ['cnt' => 0];
        $total = (int) $row['cnt'];

        // 相关度：0 = 名称完全匹配，1 = 名称前缀匹配，2 = 包含在任意位置
        $selectParams = [$lq, $prefix, ...$whereParams, $limit, $offset];
        $items = $this->db->fetchAll(
            "SELECT id, name, description, category, price_cents, created_at,
                    CASE WHEN LOWER(name) = ? THEN 0
                         WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
                         ELSE 2
                    END AS relevance
             FROM products " . $whereClause . "
             ORDER BY relevance ASC, id ASC
             LIMIT ? OFFSET ?",
            $selectParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<string> */
    public function autocomplete(string $prefix, int $limit): array
    {
        $escaped = $this->escapeLike(strtolower($prefix));
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
            [$escaped . '%', $limit]
        );
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    private function escapeLike(string $value): string
    {
        // 使用 ! 作为转义字符，避免 SQL 字符串字面量中的反斜杠混乱
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
```

### RouteRegistrar（摘录）

```php
public function register(Router $router): void
{
    $router->get('/search', $this->handleSearch(...));
    $router->get('/autocomplete', $this->handleAutocomplete(...));
}

private function handleSearch(ServerRequestInterface $request): ResponseInterface
{
    $params = $request->getQueryParams();
    $q      = isset($params['q']) ? trim((string) $params['q']) : '';
    $errors = $this->validateQuery($q);

    $limit  = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
    $offset = max(0, (int) ($params['offset'] ?? 0));
    $cat    = isset($params['category']) && trim((string) $params['category']) !== ''
                ? trim((string) $params['category']) : null;

    if ($errors !== []) {
        throw new ValidationException($errors);
    }

    $result = $this->repo->search($q, $cat, $limit, $offset);

    return $this->json->create([
        'query'    => $q,
        'category' => $cat,
        'total'    => $result['total'],
        'limit'    => $limit,
        'offset'   => $offset,
        'items'    => array_map($this->formatProduct(...), $result['items']),
    ]);
}
```

---

## 设计要点

### LIKE 特殊字符转义

`%` 和 `_` 是 SQL LIKE 通配符。直接传入用户输入会导致意外的全量匹配或类 SQL 注入行为。

```php
// 错误：用户输入 "%_" 会匹配所有记录
$this->db->fetchAll('SELECT * FROM products WHERE name LIKE ?', ['%' . $query . '%']);

// 正确：转义特殊字符
private function escapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}
// SQL: WHERE name LIKE ? ESCAPE '!'
```

使用 `!` 作为转义字符，避免反斜杠的双重转义问题（SQL/PHP 双重转义地狱）。

### 相关度评分

LIKE 搜索对所有结果赋予相同权重，使用 `CASE WHEN` 添加简单评分：

| 分数 | 条件 | 示例 |
|---|---|---|
| 0 | 名称完全匹配 | 搜索 "apple iphone 15" 匹配 "Apple iPhone 15" |
| 1 | 名称前缀匹配 | 以 "Apple" 开头的商品 |
| 2 | 名称/描述/分类中包含 | 描述中包含 "ergonomic" |

```sql
CASE WHEN LOWER(name) = ? THEN 0
     WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 1
     ELSE 2
END AS relevance
```

参数按顺序传递：`[$lq（完全匹配字符串）, $prefix（前缀匹配模式）, ...WHERE 子句参数, $limit, $offset]`。

### 自动补全仅用前缀匹配

搜索（`%query%`）和自动补全（`query%`）用途不同。自动补全返回"包含"搜索结果会使预测输入不自然。

```php
// 仅前缀匹配："Apple" → ["Apple iPhone 15", "Apple Watch Series 9"]
$rows = $this->db->fetchAll(
    "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
    [$escaped . '%', $limit]
);
// "Green Apple Juice" 不以 "Apple" 开头，因此不包含在内
```

### limit 钳位

如果客户端可以发送任意 limit，可能导致全量获取。必须在服务器端进行钳位。

```php
private function clamp(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

// 搜索：最大 50 / 自动补全：最大 10
$limit = $this->clamp((int) ($params['limit'] ?? 10), 1, 50);
```

### SQLite vs MySQL/PostgreSQL 的全文搜索

| 方式 | 适用 | 特点 |
|---|---|---|
| `LIKE '%query%'` | SQLite / MySQL / PgSQL | 小~中规模。不使用索引（前缀匹配 `LIKE 'q%'` 使用索引） |
| SQLite FTS5 虚拟表 | SQLite | 高速全文搜索。内置分词器配置和排名 |
| MySQL FULLTEXT | MySQL | `MATCH ... AGAINST` 支持 AND/OR/短语搜索 |
| PostgreSQL `tsvector` | PgSQL | GIN 索引、语言词干化支持 |

原型或小规模场景 LIKE 已足够。数十万行以上应迁移到 FTS。

---

## 响应示例

### GET /search?q=apple&category=Electronics

```json
{
  "query": "apple",
  "category": "Electronics",
  "total": 2,
  "limit": 10,
  "offset": 0,
  "items": [
    {
      "id": 1,
      "name": "Apple iPhone 15",
      "description": "Flagship smartphone by Apple",
      "category": "Electronics",
      "price_cents": 129900,
      "created_at": "2026-01-01T00:00:00Z"
    },
    {
      "id": 2,
      "name": "Apple Watch Series 9",
      "description": "Smartwatch with health tracking",
      "category": "Electronics",
      "price_cents": 49900,
      "created_at": "2026-01-01T00:00:00Z"
    }
  ]
}
```

### GET /autocomplete?q=Apple

```json
{
  "query": "Apple",
  "suggestions": [
    "Apple iPhone 15",
    "Apple Watch Series 9"
  ]
}
```

### GET /search?q=a（q 太短 → 422）

```json
{
  "status": 422,
  "errors": [
    { "field": "q", "message": "q must be at least 2 characters", "code": "too_short" }
  ]
}
```

---

## 参考实现

`../NENE2-FT/searchlog/` — FT157 字段试验（22 个测试）
