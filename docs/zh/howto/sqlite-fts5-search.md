# 操作指南：SQLite FTS5 全文搜索

> **FT 参考**：FT254（`NENE2-FT/ftslog`）— 使用 SQLite FTS5 的全文搜索

演示使用 SQLite 内置 FTS5 扩展的全文搜索（FTS）。虚拟 `posts_fts` 表镜像 `posts` 表，通过触发器保持同步。搜索使用 `MATCH` 并通过 `fts.rank` 返回按相关度排名的结果。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/posts` | 创建文章（自动建立索引） |
| `GET` | `/posts` | 列出所有文章 |
| `GET` | `/posts/search` | 全文搜索（`?q=`） |

> **路由顺序**：`/posts/search` 必须在 `/posts/{id}` **之前**注册，以免字面段 `search` 被捕获为路径参数。

---

## 数据库结构：FTS5 虚拟表 + 触发器

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '',  -- 空格分隔的标签字符串
    created_at TEXT    NOT NULL
);

-- FTS5 虚拟表：映射 posts 表用于全文搜索
CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
    title,
    body,
    tags,
    content='posts',      -- 外部内容表
    content_rowid='id'    -- 内容表中的 rowid 列
);

-- 保持 FTS 索引与 posts 同步
CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_ad AFTER DELETE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
END;

CREATE TRIGGER IF NOT EXISTS posts_au AFTER UPDATE ON posts BEGIN
    INSERT INTO posts_fts(posts_fts, rowid, title, body, tags)
    VALUES ('delete', old.id, old.title, old.body, old.tags);
    INSERT INTO posts_fts(rowid, title, body, tags)
    VALUES (new.id, new.title, new.body, new.tags);
END;
```

**`content='posts'`** 将 `posts_fts` 声明为内容表——它存储 FTS 分词，但将实际文本存储委托给 `posts`。这避免了全文的重复存储。

**`content_rowid='id'`** 告知 FTS5 `posts` 中用于 JOIN 的 rowid 列。

**触发器**保持 FTS 索引同步。没有触发器，对 `posts` 的插入和更新不会反映到 `posts_fts` 中。删除触发器使用特殊的 `'delete'` 命令语法从 FTS 索引中删除一行。

---

## 标签作为空格分隔字符串

```php
$tags = isset($body['tags']) && is_string($body['tags']) ? trim($body['tags']) : '';
// 例如 "php api backend"
$post = $this->repo->create($title, $postBody, $tags, $now);
```

标签以空格分隔字符串存储（例如 `"php api backend"`），而不是 JSON 数组或 M:N 关联表。这使标签无需 JOIN 即可被 FTS5 搜索——搜索 `kubernetes` 可以匹配标记了 `"docker kubernetes devops"` 的文章。

**权衡**：

| 方式 | FTS 可搜索 | 精确标签过滤 | 规范化标签实体 |
|---|---|---|---|
| 空格分隔字符串 | ✅ | ❌（需要 LIKE） | ❌ |
| M:N 关联表 | ❌（需要 JOIN） | ✅（IN 子句） | ✅ |
| JSON 数组列 | 有限（`json_each`） | 有限 | ❌ |

当精确标签过滤是主要用途时，使用 M:N 关联表方式（参见 [`multi-value-tag-filter.md`](multi-value-tag-filter.md)）。

---

## 全文搜索查询：`MATCH` + `rank`

```php
public function search(string $query): array
{
    if (trim($query) === '') {
        return [];
    }

    $rows = $this->executor->fetchAll(
        'SELECT p.*, fts.rank
         FROM posts_fts fts
         JOIN posts p ON p.id = fts.rowid
         WHERE posts_fts MATCH ?
         ORDER BY fts.rank',
        [$query],
    );

    return array_map(
        static fn (array $row): SearchResult => new SearchResult(
            new Post(...),
            (float) $row['rank'],
        ),
        $rows,
    );
}
```

`WHERE posts_fts MATCH ?` 跨所有建索引的列（`title`、`body`、`tags`）进行搜索。`?` 占位符是参数化值——查询字符串不会被插入到 SQL 中，因此无法改变查询结构。

`fts.rank` 是负浮点数——数值越小（越负）表示相关度越高。`ORDER BY fts.rank` 将最佳匹配排在最前面（升序，即最相关在前）。

---

## FTS5 查询语法

FTS5 支持丰富的查询语言作为 MATCH 值：

| 查询 | 匹配内容 |
|------|----------|
| `php` | 包含"php"的任何文章 |
| `php api` | **同时**包含"php"和"api"的文章（默认：隐式 AND） |
| `php AND api` | 同时包含"php"和"api"的文章（显式，同上） |
| `php OR api` | 包含"php"或"api"的文章 |
| `"quick brown"` | 包含精确短语"quick brown"的文章 |
| `php*` | 任何 token 以"php"开头的文章（前缀搜索） |
| `title:php` | title 列包含"php"的文章 |
| `php NOT python` | 有"php"但没有"python"的文章 |

短语搜索（`"..."`）匹配精确的 token 序列。列作用域搜索（`title:php`）将匹配限定在一列。

---

## 无效查询处理：try-catch → 400

FTS5 在查询语法无效时抛出 `PDOException`（或包装它）：

```php
private function searchPosts(ServerRequestInterface $request): ResponseInterface
{
    $query = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';

    if ($query === '') {
        return $this->json->create(['error' => 'q query parameter is required'], 422);
    }

    try {
        $results = $this->repo->search($query);
    } catch (\Exception $e) {
        // FTS5 在语法错误时抛出（例如未闭合引号：'"unclosed'）
        return $this->json->create(['error' => 'invalid search query'], 400);
    }

    return $this->json->create([...]);
}
```

无效的 FTS 查询（未闭合引号、格式错误的运算符）会导致 DB 异常。捕获它并返回 `400 Bad Request`，防止 `500` 泄漏给客户端。

---

## 大小写不敏感

FTS5 默认对 ASCII 字符大小写不敏感。搜索 `php` 可以匹配包含 `PHP`、`Php` 或 `php` 的文章。非 ASCII 大小写折叠需要自定义分词器（`unicode61` 或 `ascii`）。默认的 `porter` 分词器对英文单词应用词干处理。

---

## 搜索响应

```json
GET /posts/search?q=php

{
    "query": "php",
    "total": 2,
    "items": [
        {
            "id": 1,
            "title": "PHP Framework",
            "body": "Building APIs with PHP",
            "tags": "php backend",
            "created_at": "2026-05-27T10:00:00Z",
            "rank": -1.234
        }
    ]
}
```

每个结果包含 `rank`，用于客户端显示或排序。`rank` 越小（越负）= 相关度越高。

---

## 比较：FTS5 vs LIKE 搜索

| 功能 | FTS5 MATCH | LIKE `%term%` |
|---|---|---|
| 有索引 | ✅ | ❌（全表扫描） |
| 相关度排名 | ✅（`rank`） | ❌ |
| 多词搜索 | ✅（自然） | ❌（需要多个 LIKE） |
| 短语搜索 | ✅（`"..."`） | 部分（`%quick brown%`） |
| 大小写不敏感 | ✅（ASCII） | ✅（配合 NOCASE） |
| 前缀搜索 | ✅（`php*`） | ✅（`php%`） |
| 列作用域 | ✅（`title:php`） | ❌ |
| 设置成本 | FTS 虚拟表 + 触发器 | 无 |

FTS5 适用于搜索是主要功能的大型数据集。LIKE 对于小型表或简单的前缀自动补全已经足够。

---

## 相关指南

- [`use-fts5-search.md`](use-fts5-search.md) — 为现有表添加 FTS5
- [`search-autocomplete.md`](search-autocomplete.md) — 基于 LIKE 的前缀自动补全（searchlog FT157）
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — 带 AND/OR 语义的 M:N 标签过滤
- [`event-analytics-api.md`](event-analytics-api.md) — JSON 属性搜索的 `json_extract()`
