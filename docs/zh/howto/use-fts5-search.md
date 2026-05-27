# 使用 SQLite FTS5 全文搜索

SQLite 的 FTS5 扩展使用倒排索引提供全文搜索功能。本指南涵盖数据库结构模式、基于触发器的索引同步，以及接受用户输入时会遇到的查询语法陷阱。

---

## 1. 结构：虚拟表 + 外部内容 + 触发器

使用 `content=<table>` 将数据保存在普通表中，让 FTS5 仅维护搜索索引：

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    body,
    author,
    content=articles,   -- FTS5 从 articles 表读取内容
    content_rowid=id    -- 将 FTS5 rowid 映射到 articles.id
);

-- 保持 FTS 索引与基础表同步
CREATE TRIGGER articles_ai AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_au AFTER UPDATE ON articles BEGIN
    -- 从索引中删除旧行，然后插入更新后的行
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
    INSERT INTO articles_fts(rowid, title, body, author)
    VALUES (new.id, new.title, new.body, new.author);
END;

CREATE TRIGGER articles_ad AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, body, author)
    VALUES ('delete', old.id, old.title, old.body, old.author);
END;
```

> **为什么使用触发器？** 带 `content=` 的 FTS5 不会自动跟踪变更——必须用触发器维护索引。`INSERT INTO fts(fts, rowid, ...) VALUES ('delete', ...)` 是 FTS5 从索引中删除行的方式。

---

## 2. 搜索查询

```php
$rows = $this->executor->fetchAll(
    "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
     FROM articles_fts
     JOIN articles a ON a.id = articles_fts.rowid
     WHERE articles_fts MATCH ?
     ORDER BY rank
     LIMIT ? OFFSET ?",
    [$query, $limit, $offset],
);
```

- `rank` 是 FTS5 暴露的虚拟列。值越小（越负）表示相关性越高。`ORDER BY rank` 将最佳匹配排在前面。
- `snippet(table, column_index, open, close, ellipsis, token_count)` 返回高亮片段。列索引从 0 开始：`0` = title，`1` = body。

---

## 3. 查询语法陷阱

### 3.1 连字符是列过滤前缀——不是词语分隔符

**这是接受用户输入时最常见的 bug 来源。**

FTS5 将 `word-other` 解释为列过滤：在名为 `word` 的列中搜索词 `other`。如果 `word` 不是 FTS5 表中的列，SQLite 会抛出错误：

```
General error: 1 no such column: text
```

```
full-text   ← 错误：将"full"列中的"text"作为搜索目标——但"full"不是列名
full text   ← 正确：AND 查询（同时匹配含"full"和"text"的文档）
"full text" ← 正确：短语查询（精确的连续顺序）
```

此错误会以 `DatabaseConnectionException` 的形式传播，若不先清理输入则会导致 500 响应。

**在传递给 FTS5 之前净化用户输入：**

```php
private function sanitizeFtsQuery(string $query): string
{
    // 将连字符替换为空格，使"full-text"变为"full text"（AND 逻辑）
    return str_replace('-', ' ', $query);
}
```

或用双引号转义以进行短语匹配：

```php
private function sanitizeFtsQuery(string $query): string
{
    // 将整个查询用双引号括起来以强制短语匹配
    $escaped = str_replace('"', '""', $query);
    return '"' . $escaped . '"';
}
```

### 3.2 默认不进行词干还原

默认的 `unicode61` 分词器不进行词干处理。`framework` 不匹配 `frameworks`，`run` 不匹配 `running`。

选项：

| 方式 | 如何实现 |
|---|---|
| 精确匹配 | 在文档和查询中都使用完整词形 |
| 前缀匹配 | 在查询词后追加 `*`：`framework*` 匹配 `framework`、`frameworks`、`framework-agnostic` |
| Porter 词干算法 | 在 `CREATE VIRTUAL TABLE` 语句中声明 `tokenize='porter ascii'` |

**前缀匹配示例：**

```php
// 用户输入"frame"→ 追加 * 以匹配"framework"、"frameworks"等
$query = trim($userInput) . '*';
```

**Porter 词干算法：**

```sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title, body, author,
    content=articles,
    content_rowid=id,
    tokenize='porter ascii'  -- 英语词干还原
);
```

> `porter` 分词器仅在 SQLite 编译时支持 FTS5 的情况下可用（标准构建包含它）。适用于英语文本；对于其他语言，考虑在建索引前进行外部词干处理。

### 3.3 AND / OR / NOT 运算符

FTS5 查询语法：

| 语法 | 含义 |
|---|---|
| `one two` | AND：两者都必须出现 |
| `one OR two` | OR：任一出现即可 |
| `one NOT two` | NOT：前者出现，后者不出现 |
| `"one two"` | 短语：精确的连续顺序 |
| `one*` | 前缀：匹配 `one`、`ones` 等（基于 `one` 的前缀匹配） |
| `title:query` | 列过滤：将匹配限制在 `title` 列 |

> **注意**：`NOT` 必须大写。小写的 `not` 会被当作搜索词处理。

---

## 4. 统计搜索结果数量

用单独的查询统计——对 FTS5 匹配结果执行 `COUNT(*)`：

```php
$count = $this->executor->fetchOne(
    'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
    [$query],
);
```

---

## 5. 完整 Repository 示例

```php
final readonly class ArticleRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $userQuery, int $limit, int $offset): array
    {
        $query = $this->sanitizeFtsQuery($userQuery);

        return $this->executor->fetchAll(
            "SELECT a.*, snippet(articles_fts, 1, '<b>', '</b>', '…', 10) AS snippet, rank
             FROM articles_fts
             JOIN articles a ON a.id = articles_fts.rowid
             WHERE articles_fts MATCH ?
             ORDER BY rank
             LIMIT ? OFFSET ?",
            [$query, $limit, $offset],
        );
    }

    public function countSearch(string $userQuery): int
    {
        $query = $this->sanitizeFtsQuery($userQuery);
        $row   = $this->executor->fetchOne(
            'SELECT COUNT(*) AS cnt FROM articles_fts WHERE articles_fts MATCH ?',
            [$query],
        );

        return (int) ($row['cnt'] ?? 0);
    }

    private function sanitizeFtsQuery(string $query): string
    {
        // 将连字符替换为空格："full-text" → "full text"（AND 逻辑）
        return str_replace('-', ' ', trim($query));
    }
}
```
