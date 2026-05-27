# 分页

列表端点分页有两种模式可选：**OFFSET（偏移量）** 和 **游标**（键集）。根据数据量和 UI 需求进行选择。

## 快速对比

| | OFFSET | 游标 |
|---|---|---|
| 实现难度 | 简单 | 中等（fetch+1 模式） |
| 总记录数 | 需要 `COUNT(*)` | 不需要 |
| 深页速度 | 线性退化 | 恒定（索引查找） |
| 页码 UI | 简单 | 困难 |
| 无限滚动/信息流 | 脆弱（行漂移） | 安全 |
| 浏览期间数据变化 | 可能导致行漂移 | 稳定 |

**经验法则**：对小数据集使用 OFFSET，适合带页码的管理表格。对信息流、无限滚动以及超过约 10,000 行的表使用游标。

## OFFSET 分页

```php
private function listByOffset(ServerRequestInterface $request): ResponseInterface
{
    $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

    $items = $repo->fetchAll(
        'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset],
    );
    $total = $repo->fetchOne('SELECT COUNT(*) AS cnt FROM articles', [])['cnt'] ?? 0;

    return $json->create([
        'items'       => $items,
        'limit'       => $limit,
        'offset'      => $offset,
        'total'       => (int) $total,
        'has_more'    => ($offset + $limit) < (int) $total,
        'next_offset' => ($offset + $limit) < (int) $total ? $offset + $limit : null,
    ]);
}
```

**为何 OFFSET 越来越慢**：数据库必须扫描并丢弃偏移量之前的所有行。对于 `OFFSET 5000`，引擎读取 5001 行并丢弃前 5000 行。可用 SQLite 验证：

```sql
EXPLAIN QUERY PLAN
SELECT * FROM articles ORDER BY id DESC LIMIT 20 OFFSET 5000;
-- SCAN articles USING INDEX idx_articles_id_desc
-- 扫描仍然涉及 5020 行。
```

## 游标分页

游标是最后一条可见行的 `id`。每页使用 `WHERE id < cursor` 获取游标之前的行（降序），这通过索引查找来完成——游标之前的行不会被触碰。

```php
private function listByCursor(ServerRequestInterface $request): ResponseInterface
{
    $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $afterId = QueryStringParser::int($request, 'after'); // null = 首页

    // fetch+1 模式：无需 COUNT 查询即可检测 has_more
    $fetch = $limit + 1;

    if ($afterId === null) {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    } else {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // 丢弃额外的哨兵行
    }

    $nextCursor = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;

    return $json->create([
        'items'       => $rows,
        'limit'       => $limit,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
}
```

### fetch+1 模式

在不发出 `COUNT(*)` 的情况下判断是否存在下一页：

1. 请求 `limit + 1` 条记录。
2. 如果结果超过 `limit` 条，则存在下一页。
3. 返回前丢弃最后一条记录（`array_pop`）。
4. 使用最后剩余记录的 `id` 作为 `next_cursor`。

这以总是多取一条记录为代价避免了额外的查询。

### 客户端使用

```
GET /articles/cursor?limit=20
→ { items: [...20 条], has_more: true, next_cursor: 42 }

GET /articles/cursor?limit=20&after=42
→ { items: [...20 条], has_more: true, next_cursor: 22 }

GET /articles/cursor?limit=20&after=22
→ { items: [...2 条], has_more: false, next_cursor: null }
```

## limit 限制

始终将 limit 限制在合理范围内以防止无界查询：

```php
$limit = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
```

接受 `1–100` 范围，参数缺失时默认为 `20`。

## 何时从 OFFSET 切换到游标

基于表大小和典型页深度的粗略指南：

| 行数 | 典型页深度 | 建议 |
|---|---|---|
| < 10,000 | 任意 | 两者均可；OFFSET 更简单 |
| 10,000–100,000 | 浅（第 1–5 页） | 两者均可；在排序列上添加索引 |
| 10,000–100,000 | 深（第 10 页以上） | 优先使用游标 |
| > 100,000 | 任意 | 强烈推荐游标 |

无论使用哪种方式，都要在排序列上添加索引：

```sql
CREATE INDEX idx_articles_id_desc ON articles (id DESC);
```

## 在相同位置比较结果

从 OFFSET 迁移到游标时，通过两种方式获取相同的"窗口"行来验证正确性：

```php
// OFFSET：第 11–20 行（0 索引 offset=10）
$offsetPage = $get('/articles/offset?limit=10&offset=10');

// 游标：获取位置 10 处的 id（offset=9），将其用作锚点
$anchor     = $get('/articles/offset?limit=1&offset=9');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $get("/articles/cursor?limit=10&after={$anchorId}");

// 这些应该是相同的
assert(array_column($offsetPage['items'], 'id') === array_column($cursorPage['items'], 'id'));
```
