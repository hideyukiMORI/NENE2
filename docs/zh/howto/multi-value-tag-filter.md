# 操作指南：多值标签过滤 API

> **FT 参考**：FT250（`NENE2-FT/tagfilterlog`）——多值查询参数标签过滤

演示在帖子 API 上使用规范化的 M:N 连接表进行多标签过滤。支持 AND 语义（包含**所有**给定标签的帖子）和 OR 语义（包含**任意**给定标签的帖子），以及两种客户端查询格式：逗号分隔（`?tags=php,api`）和 PHP 风格数组（`?tags[]=php&tags[]=api`）。

---

## 路由

| 方法 | 路径 | 描述 |
|--------|---------------|---------------------------------------------------|
| `POST` | `/posts`      | 创建带可选标签数组的帖子            |
| `GET`  | `/posts`      | 列出帖子（可按标签过滤，AND 或 OR） |
| `GET`  | `/posts/{id}` | 获取单个帖子及其标签                    |

---

## 数据库结构：M:N 连接表

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag     TEXT    NOT NULL,
    PRIMARY KEY (post_id, tag)
);

CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag);
```

`PRIMARY KEY(post_id, tag)` 是一个复合主键——它既强制唯一性，又作为 `(post_id, tag)` 上的索引。仅针对 `tag` 的单独索引支持高效的 `WHERE tag IN (...)` 查找，无论 `post_id` 如何。

**替代方案：JSON 列方式**

标签可以以 JSON 数组存储在 `posts` 表的 TEXT 列中：`tags TEXT NOT NULL DEFAULT '[]'`。这更简单（无需 JOIN），但不支持索引标签查找，并且过滤需要 `json_each()` 或 `json_extract()`。当标签搜索性能重要时，推荐 M:N 连接表。

---

## 创建：标签去重和字母排序

```php
$rawTags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags    = array_values(array_filter(array_map(
    static fn (mixed $t): string => is_string($t) ? trim($t) : '',
    $rawTags,
), static fn (string $s): bool => $s !== ''));
```

从请求中提取标签，去除空白，并过滤为非空字符串。非字符串值（数字、null）被强制转换为空字符串并丢弃。

在事务中：

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($title, $body, $tags, $now): Post {
    $id = $tx->insert(
        'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$title, $body, $now],
    );

    // 去重并排序标签
    $uniqueTags = array_values(array_unique($tags));
    sort($uniqueTags);

    foreach ($uniqueTags as $tag) {
        $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$id, $tag]);
    }

    return new Post($id, $title, $body, $uniqueTags, $now);
});
```

`array_unique()` + `sort()` 在写入前在 PHP 中去重并按字母排序。`INSERT OR IGNORE` 是第二层防御——如果复合主键约束触发（例如并发写入），插入被跳过而不是抛出异常。

响应以排序顺序返回标签，使调用者始终看到稳定的列表。

---

## AND 过滤：`HAVING COUNT(DISTINCT tag) = N`

```php
public function findByAllTags(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         GROUP BY p.id
         HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
         ORDER BY p.created_at DESC",
        [...$tags, count($tags)],
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`WHERE pt.tag IN (...)` 缩小到至少有一个匹配标签的行。`GROUP BY p.id HAVING COUNT(DISTINCT pt.tag) = N` 只选择匹配**所有** N 个标签的帖子。

**`CAST(? AS INTEGER)` 是必需的**：PDO 默认将所有参数绑定为字符串。在 SQLite 中，将 `COUNT(...)`（整数）与 `'2'`（字符串）比较在简单情况下可以正常工作，但显式转型更安全并记录了意图。

---

## OR 过滤：`SELECT DISTINCT`

```php
public function findByAnyTag(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT DISTINCT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         ORDER BY p.created_at DESC",
        $tags,
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`SELECT DISTINCT` 防止帖子匹配 IN 列表中多个标签时出现重复行。不需要 `HAVING` 子句——任何单个匹配标签都可以使帖子符合条件。

---

## 双查询参数格式

列表端点接受两种格式的标签，以适应不同客户端：

| 格式 | 示例 | 来源 |
|--------|---------|--------|
| 逗号分隔 | `?tags=php,api` | `QueryStringParser::commaSeparated()` |
| PHP 数组风格 | `?tags[]=php&tags[]=api` | PSR-7 `getQueryParams()` |

```php
private function extractTags(ServerRequestInterface $request): array
{
    // 策略 1：逗号分隔（NENE2 原生）
    $csv = QueryStringParser::commaSeparated($request, 'tags');
    if ($csv !== null) {
        return $csv;
    }

    // 策略 2：PHP 风格数组参数（?tags[]=php&tags[]=api）
    // PSR-7 getQueryParams() 原生解析 PHP 数组语法。
    // NENE2 的 QueryStringParser 没有这个辅助方法——使用原始访问。
    $params   = $request->getQueryParams();
    $arrayVal = $params['tags'] ?? null;

    if (is_array($arrayVal)) {
        /** @var list<string> $filtered */
        $filtered = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $arrayVal),
            static fn (string $s): bool => $s !== '',
        ));

        return $filtered;
    }

    return [];
}
```

`QueryStringParser::commaSeparated()` 处理 `?tags=php,api`，参数缺失时返回 `null`。当为 `null` 时，回退检查 `getQueryParams()['tags']`，PSR-7 实现将其从 `?tags[]=php&tags[]=api` 解析为 PHP 数组。

模式参数选择 AND 或 OR：

```php
$mode  = QueryStringParser::string($request, 'mode') ?? 'all'; // 'all' = AND，'any' = OR
$posts = $mode === 'any'
    ? $this->repository->findByAnyTag($tags)
    : $this->repository->findByAllTags($tags);
```

未知的 `mode` 值会落入 AND（更安全的默认值——结果更少）。

---

## 水化：每帖子 N+1 查询

```php
private function hydrateWithTags(array $row): Post
{
    $tagRows = $this->executor->fetchAll(
        'SELECT tag FROM post_tags WHERE post_id = ? ORDER BY tag ASC',
        [(int) $row['id']],
    );

    $tags = array_map(static fn (array $r): string => (string) $r['tag'], $tagRows);

    return new Post(
        id:        (int) $row['id'],
        title:     (string) $row['title'],
        body:      (string) $row['body'],
        tags:      $tags,
        createdAt: (string) $row['created_at'],
    );
}
```

这对每个帖子执行一条额外查询来加载其标签。对于小数据集这是可接受的。对于大结果集，用单个 `GROUP_CONCAT` 或 `json_group_array` 查询替代：

```sql
SELECT p.*, GROUP_CONCAT(pt.tag ORDER BY pt.tag) AS tags_csv
FROM posts p
LEFT JOIN post_tags pt ON pt.post_id = p.id
GROUP BY p.id
ORDER BY p.created_at DESC
```

然后在 PHP 中用 `explode(',', ...)` 分割 `tags_csv`。注意 SQLite 的 `GROUP_CONCAT` 在聚合内不带 `ORDER BY` 时不保证顺序（SQLite 3.39+ 支持 `GROUP_CONCAT` 内的 `ORDER BY`）。

---

## AND 与 OR 比较

| 模式 | SQL 模式 | 对 `[php, api]` 与 `[php]` 与 `[js]` |
|------|-------------|-----------------------------------------------|
| AND（`mode=all`） | `HAVING COUNT(DISTINCT tag) = N` | 只有 `[php, api]` 匹配 `?tags=php,api` |
| OR（`mode=any`） | `SELECT DISTINCT` | `[php, api]` 和 `[php]` 都匹配 `?tags=php,api` |
| 无标签 | 无过滤器 | 返回所有帖子 |

空标签列表（`tags=[]` 或缺少 `tags`）在两种模式下都返回所有帖子。

---

## 相关操作指南

- [`tagging-system.md`](tagging-system.md) — 带实体范围 M:N 关系的标签/标记管理
- [`tag-label-api.md`](tag-label-api.md) — 带标签实体 CRUD 和列表过滤的标签分类
- [`note-management-with-tags.md`](note-management-with-tags.md) — 带所有者范围的笔记标签
- [`cursor-pagination.md`](cursor-pagination.md) — 将游标分页与标签过滤结合
