# 操作指南：基于游标的分页

> **FT 参考**：FT242（`NENE2-FT/cursorlog`）——基于游标的分页 API

演示基于游标的（键集）分页作为偏移量分页的替代方案。使用基于 ID 的游标（`WHERE id < ?`）获取条目，通过 `limit+1` 技巧在无需 COUNT 查询的情况下检测 `has_more`，响应携带 `next_cursor` 值供调用者在下一次请求中传入。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/posts` | 创建帖子 |
| `GET` | `/posts` | 带游标分页的帖子列表 |
| `GET` | `/posts/{id}` | 获取单个帖子 |

---

## 偏移量 vs. 游标分页

| 关注点 | 偏移量（`LIMIT ? OFFSET ?`） | 游标（`WHERE id < ? ORDER BY id`） |
|--------|--------------------------|----------------------------------|
| 大数据集性能 | 下降——数据库必须跳过 N 行 | 恒定——索引定位到游标位置 |
| 结果稳定性 | 新行导致后续页面移位 | 稳定——锚定到特定行 |
| 随机访问 | 支持（`?page=5`） | 不支持（仅向前） |
| 总数 | 需要单独的 `COUNT(*)` 查询 | 无需总数（使用 `has_more` 标志） |
| 游标类型 | 整数偏移量（基于位置） | 行标识值（基于 ID） |

游标分页更适合高吞吐量的实时信息流，在这种场景中偏移量偏移（新条目在页面间插入）会导致行重复或缺失。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_id ON posts(id DESC);
```

`id` 上的降序索引高效支持 `ORDER BY id DESC`。SQLite 的 `INTEGER PRIMARY KEY` 已经是 `rowid` 的别名，因此显式索引加速了超出主键单独能提供的范围查询。

---

## 游标逻辑：`WHERE id < ? ORDER BY id DESC LIMIT ?`

仓库多获取一行（`limit + 1`）来检测是否还有更多页：

```php
/**
 * 以 ID 降序获取一页帖子。
 *
 * @param int|null $afterCursor  最后看到的帖子 ID；返回 id < afterCursor 的帖子
 * @param int      $limit        最多返回的条目数（上限为 100）
 */
public function paginate(?int $afterCursor, int $limit): CursorPage
{
    $limit = max(1, min(100, $limit));
    // 多获取一行来检测是否有下一页
    $fetch = $limit + 1;

    if ($afterCursor !== null) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterCursor, $fetch],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);   // 丢弃额外的行
    }

    $items      = array_map(fn (array $row): Post => $this->hydrate($row), $rows);
    $nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;

    return new CursorPage($items, $nextCursor, $hasMore);
}
```

关键步骤：
1. **限制 limit**：`max(1, min(100, $limit))`——防止 0 行或失控查询。
2. **获取 `limit + 1`**：如果返回超过 `$limit` 行，则存在下一页。
3. **弹出额外行**：`array_pop($rows)` 丢弃仅用于检测的第（limit+1）行。
4. **计算 `nextCursor`**：最后一个条目的 `id` 成为调用者下次发送的游标。
5. **`$hasMore = false`** 当 `$nextCursor === null` 时——没有更多页面。

第一页没有游标（`$afterCursor === null`），返回最新的帖子。每个后续请求发送 `?cursor=<nextCursor>` 从上次停止的地方继续。

---

## `CursorPage` 值对象

```php
final readonly class CursorPage
{
    /** @param list<Post> $items */
    public function __construct(
        public array $items,
        public ?int  $nextCursor,
        public bool  $hasMore,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'       => array_map(static fn (Post $p): array => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_more'    => $this->hasMore,
        ];
    }
}
```

最后一页时 `next_cursor` 为 `null`（没有更多条目）。`has_more` 与之对应：`next_cursor` 有值时为 `true`，最后一页时为 `false`。调用者在 `has_more === false` 或 `next_cursor === null` 时停止。

响应结构：
```json
{
    "items": [...],
    "next_cursor": 42,
    "has_more": true
}
```

---

## 控制器：读取和校验游标

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $query       = $request->getQueryParams();
    $limit       = isset($query['limit']) ? (int) $query['limit'] : 10;
    $cursorRaw   = isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null;
    $afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

    $page = $this->repo->paginate($afterCursor, $limit);

    return $this->json->create($page->toArray());
}
```

`ctype_digit($cursorRaw)` 在转换为 `int` 之前校验游标字符串：
- `ctype_digit()` 对空字符串、负号、浮点数和非数字字符串返回 `false`——全部视为"无游标"（第一页）。
- 无效游标回退到第一页而非返回错误——传入过期或垃圾游标的调用者看到第一页，而非 `400`。

这是一个务实的选择：无效游标被静默视为缺失。对于更严格的 API，当 `$cursorRaw` 非空但 `ctype_digit()` 失败时返回 `422 Unprocessable Entity`。

---

## Limit 裁剪

```php
$limit = max(1, min(100, $limit));
```

- 最小值 `1`：防止零行查询。
- 最大值 `100`：限制页大小以避免失控获取。

裁剪在仓库中进行而非控制器，确保 `paginate()` 的调用者无法绕过边界。控制器在缺失时以 `10` 作为 `$query['limit']` 的默认值读取。

---

## 分页契约总结

| 查询参数 | 类型 | 默认值 | 行为 |
|---------|------|--------|------|
| `?limit=N` | 整数 | 10 | 每页条目数（裁剪为 1–100） |
| `?cursor=ID` | 整数字符串 | 缺失 | 获取 `id < ID` 的条目；缺失 = 第一页 |

| 响应字段 | 类型 | 含义 |
|---------|------|------|
| `items` | 数组 | 本页的序列化条目 |
| `next_cursor` | int \| null | 在下一次请求中作为 `?cursor=` 传入；`null` = 最后一页 |
| `has_more` | bool | `true` 表示还有更多页 |

---

## 与偏移量分页的对比

NENE2 内置的 `PaginationQueryParser` / `PaginationResponse` 使用 `LIMIT ? OFFSET ?`。在以下情况下使用它们：
- 需要随机页面访问（`?page=5`）。
- 向用户显示总条目数。
- 数据集较小且遍历期间很少增长。

在以下情况下使用游标分页：
- 信息流数据持续增长（聊天、活动流、日志）。
- 需要在插入负载下进行稳定遍历。
- 数据集足够大，使得 `OFFSET N` 变慢。

---

## 相关操作指南

- [`pagination.md`](pagination.md) ——带 `PaginationQueryParser` 和 `PaginationResponse` 的偏移量分页
- [`activity-feed.md`](activity-feed.md) ——实时信息流模式
- [`add-pagination.md`](add-pagination.md) ——向现有端点添加分页
