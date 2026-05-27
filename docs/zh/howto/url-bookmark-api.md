# 操作指南：带标签过滤的 URL 书签 API

> **FT 参考**：FT265（`NENE2-FT/linklog`）— URL 书签 API：UNIQUE URL 约束、逗号分隔标签存储、基于 LIKE 的标签匹配

演示一个将 URL 和标签以逗号分隔的 TEXT 列存储的书签 API。重复 URL 通过 `UNIQUE` 约束检测，并映射为 `DuplicateUrlException` 返回 409 Conflict。标签过滤使用四种 LIKE 模式，无论标签在逗号分隔字符串中的位置都能匹配。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/links` | 创建书签 |
| `GET` | `/links` | 列出书签（搜索 + 标签过滤，分页） |
| `GET` | `/links/{id}` | 获取单个书签 |
| `DELETE` | `/links/{id}` | 删除书签 |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL UNIQUE,
    title       TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    tags        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);
```

`url TEXT NOT NULL UNIQUE` 在 DB 层强制每个 URL 只能有一个书签。
`tags TEXT` 存储逗号分隔列表（例如 `"php,api,rest"`）。对于小规模用例，这避免了单独的 `link_tags` 关联表。

---

## 标签：逗号分隔 TEXT vs M:N 关联表

| 方式 | 查询复杂度 | 适用场景 |
|---|---|---|
| 逗号分隔 TEXT | LIKE 模式（每标签 4 个） | 小型数据集；标签查询不频繁 |
| M:N 关联表（`link_tags`） | JOIN + GROUP BY 或 IN | 大型数据集；频繁的 AND/OR 过滤 |
| 带标签列的 FTS5 | `WHERE fts MATCH ?` | 多列全文搜索 |

逗号分隔 TEXT 实现更简单，适合链接和标签数量适中的场景。对于有数千条链接和复杂标签查询（AND 过滤、精确计数）的数据集，关联表（见 [`multi-value-tag-filter.md`](multi-value-tag-filter.md)）更好。

---

## 标签 LIKE 匹配：四种模式

存储在逗号分隔列中的标签可能出现在四个位置：
1. **完全匹配**：`tags = 'php'`（唯一标签）
2. **在开头**：`tags LIKE 'php,%'`（多个标签中的第一个）
3. **在中间**：`tags LIKE '%,php,%'`（不是第一个也不是最后一个）
4. **在末尾**：`tags LIKE '%,php'`（多个标签中的最后一个）

```php
if ($tags !== null) {
    foreach ($tags as $tag) {
        $sql      .= ' AND (tags = ? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
        $params[]  = $tag;            // 完全匹配："php"
        $params[]  = $tag . ',%';     // 前缀："php,..."
        $params[]  = '%,' . $tag . ',%';  // 中间："...,php,..."
        $params[]  = '%,' . $tag;     // 后缀："...,php"
    }
}
```

每个标签的四种模式都用 AND 连接：链接必须匹配所有请求的标签。这实现了跨标签的 AND 过滤。每个 `?` 都是参数化绑定——无注入风险。

**限制**：查询标签 `ph` 不会匹配存储标签 `php`，因为模式检查精确的分隔符（`,` 或字符串边界）。标签按精确字符串值匹配，而非子串。

---

## 逗号分隔标签的序列化和反序列化

**存储**：`implode(',', $tags)` — `['php', 'api', 'rest']` → `'php,api,rest'`

**读取**：
```php
$tagsStr = (string) $row['tags'];
$tags    = $tagsStr === '' ? [] : array_values(array_filter(explode(',', $tagsStr)));
```

`array_filter()` 移除由前/后缀逗号或双逗号产生的任何空字符串。
`array_values()` 重新索引为 `list<string>`。

**标签查询参数解析**：`?tags=php,api` → 按逗号分割 → `['php', 'api']`

```php
$rawTags = QueryStringParser::string($request, 'tags');
$tags    = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

---

## 重复 URL：自定义异常 + 处理器

```php
public function create(string $url, ...): Link
{
    try {
        $this->executor->execute(
            'INSERT INTO links (url, title, description, tags, created_at) VALUES (?, ?, ?, ?, ?)',
            [$url, $title, $description, implode(',', $tags), $createdAt],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new DuplicateUrlException($url);
        }
        throw $e;
    }

    return new Link($this->executor->lastInsertId(), ...);
}
```

Repository 捕获通用的 `DatabaseConnectionException`（框架在发生 PDO 异常时抛出），检查前一个异常的消息中是否含有 `UNIQUE constraint failed`，并以领域特定的 `DuplicateUrlException` 重新抛出。这保持了领域语言（`DuplicateUrlException`）与基础设施细节（`PDOException`）的分离。

`DuplicateUrlExceptionHandler` 中间件捕获 `DuplicateUrlException` 并返回 409 Conflict Problem Details：

```php
return $this->problems->create($request, 'duplicate-url', 'URL already exists.', 409, $e->url);
```

---

## 搜索：对标题和 URL 进行 LIKE

```php
if ($search !== null) {
    $sql      .= ' AND (title LIKE ? OR url LIKE ?)';
    $params[]  = '%' . $search . '%';
    $params[]  = '%' . $search . '%';
}
```

搜索查询应用于 `title` 和 `url` 两列。单个 `$search` 绑定为两列重复使用。与标签过滤一样，通配符 `%` 是查询字符串中的 SQL 字面量，而非来自用户输入——用户的搜索词作为参数绑定。

---

## 示例：标签 AND 过滤

**请求**：`GET /links?tags=php,api`

匹配在 `tags` 列中同时含有 `php` **和** `api` 的链接：
- `"php,api"` ✓（php：前缀匹配，api：后缀匹配）
- `"rest,php,api"` ✓（php：中间匹配，api：后缀匹配）
- `"php"` ✗（缺少 `api`）

---

## 相关指南

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — 带 AND/OR 标签过滤的 M:N 关联表（适用于较大数据集）
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 全文搜索作为 LIKE 的替代方案
- [`sql-injection-defence.md`](sql-injection-defence.md) — 参数化 LIKE 模式和注入防御
