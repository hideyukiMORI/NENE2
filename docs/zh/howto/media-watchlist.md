# 操作指南：媒体观看清单 API

> **FT 参考**：FT59（`NENE2-FT/watchlog`）——媒体观看清单 API

演示一个个人媒体观看清单，使用字符串枚举（backed string enum）表示状态和类型，通过 `array_key_exists` 处理可选的可空字段，通过 POST 动作端点实现归档/恢复，以及 1–5 整数评分。所有状态和类型校验都使用 PHP 的 `BackedEnum::tryFrom()` 以确保只接受已知值。

---

## 路由

| 方法 | 路径 | 描述 |
|----------|----------------------------|-----------------------------------------------|
| `GET`    | `/watch`                   | 列出条目（带筛选和分页）         |
| `POST`   | `/watch`                   | 添加条目到观看清单                 |
| `GET`    | `/watch/{id}`              | 获取单个条目                            |
| `PATCH`  | `/watch/{id}/status`       | 更新状态（可选更新评分/备注）    |
| `POST`   | `/watch/{id}/archive`      | 将条目移至归档                         |
| `POST`   | `/watch/{id}/restore`      | 恢复已归档的条目                       |
| `DELETE` | `/watch/{id}`              | 永久删除条目                            |

---

## 枚举校验（Backed enum）

状态和媒体类型使用 `BackedEnum::tryFrom()` 校验。枚举也作为序列化中的类型使用，因此写入 DB 的字符串值和 JSON 响应中的字符串值会自动保持同步。

```php
enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching    = 'watching';
    case Completed   = 'completed';
    case Dropped     = 'dropped';
}

enum MediaType: string
{
    case Movie = 'movie';
    case Tv    = 'tv';
}
```

在控制器中，`tryFrom()` 对未知值返回 `null`，映射为 422：

```php
$statusRaw = isset($body['status']) && is_string($body['status']) ? $body['status'] : null;
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw === null) {
    $errors[] = new ValidationError('status', 'status is required.', 'required');
} elseif ($status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

两步检查区分了"字段缺失"（required）和"字段存在但无效"（invalid_value），从而产生更好的错误消息。

---

## 带枚举类型过滤器的列表查询

查询参数通过 `QueryStringParser` 解析，然后经由 `tryFrom()` 校验：

```php
$statusRaw = QueryStringParser::string($request, 'status');   // 缺失时为 null
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

这个模式——解析、尝试枚举转换、校验——将路由逻辑与领域代码隔离。数据仓库接受 `?WatchStatus` 和 `?MediaType` 并相应过滤。

**支持的过滤器**：
- `?status=watching` — 按状态筛选
- `?media_type=movie` — 按媒体类型筛选
- `?include_archived=1` — 包含已归档条目（默认排除）
- `?limit=20&offset=0` — 分页

---

## 使用 `array_key_exists` 处理可空字段

`rating` 和 `note` 是可空的——调用者可以显式将其设为 `null` 以清除值。使用 `isset()` 会遗漏显式发送的 `null`。应使用 `array_key_exists()`：

```php
// ✓ 正确：区分缺失与显式 null
$rating = array_key_exists('rating', $body) ? $body['rating'] : null;

// ✗ 错误：array_key_exists($body, 'rating') 会吞掉有意为之的 null
if ($rating !== null) {
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5.', 'out_of_range');
    }
}
```

`is_int($rating)` 拒绝 JSON 浮点数（`4.0` → PHP `float`）和字符串（`"4"`）。只有 JSON 整数字面量（`4`）才能通过严格类型检查。

---

## 通过 POST 动作端点实现归档/恢复

归档和恢复是变更操作（它们改变状态并记录时间戳），因此使用 `POST`，而不是 `DELETE` 或 `PATCH`。这遵循动作端点模式：

```php
// POST /watch/{id}/archive
private function archive(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}

// POST /watch/{id}/restore
private function restore(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) ($request->getAttribute(Router::PARAMETERS_ATTRIBUTE)['id'] ?? 0);
    $entry = $this->repository->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $this->json->create($this->serialize($entry));
}
```

`archive()` 将 `archived_at` 设置为当前时间戳；`restore()` 将其设回 `null`。列表端点默认隐藏已归档条目（`include_archived=false`）。

为什么用 `POST` 而不是 `DELETE` 进行归档？`DELETE` 意味着永久删除。归档是软性状态变更——条目仍保留在 DB 中，可以恢复。以动作命名端点（`/archive`、`/restore`）让意图更加明确。

---

## 数据库结构：CHECK 约束与枚举值匹配

```sql
CREATE TABLE watch_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    media_type  TEXT NOT NULL CHECK(media_type IN ('movie', 'tv')),
    status      TEXT NOT NULL DEFAULT 'want-to-watch'
                              CHECK(status IN ('want-to-watch', 'watching', 'completed', 'dropped')),
    rating      INTEGER CHECK(rating IS NULL OR (rating >= 1 AND rating <= 5)),
    note        TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    archived_at TEXT
);
```

DB 的 `CHECK` 约束与枚举 case 对应——如果向枚举中添加新状态而不更新 `CHECK`，INSERT 会在 DB 层失败。保持两者同步：将新 case 添加到枚举、`CHECK` 以及任何迁移中。

`rating CHECK(rating IS NULL OR ...)` 正确地允许列为 `NULL`，同时在有值时仍然强制执行 1–5 范围。

`archived_at TEXT`（可空）作为归档标志：`NULL` = 活跃，非空 = 已归档。这是最简洁的软归档模式——不需要单独的 `is_archived BOOLEAN` 列。

---

## 列表性能索引

```sql
CREATE INDEX idx_watch_status      ON watch_entries (status);
CREATE INDEX idx_watch_archived_at ON watch_entries (archived_at);
```

`idx_watch_archived_at` 支持常见的 `WHERE archived_at IS NULL` 过滤（活跃条目）。SQLite 可以通过部分索引模式对 `IS NULL` 条件使用此索引，但对于大多数观看清单，普通索引就足够了。

---

## 序列化

```php
/** @return array<string, mixed> */
private function serialize(WatchEntry $entry): array
{
    return [
        'id'          => $entry->id,
        'title'       => $entry->title,
        'media_type'  => $entry->mediaType->value,  // 枚举 → 字符串
        'status'      => $entry->status->value,      // 枚举 → 字符串
        'rating'      => $entry->rating,             // int|null
        'note'        => $entry->note,
        'created_at'  => $entry->createdAt,
        'updated_at'  => $entry->updatedAt,
        'archived_at' => $entry->archivedAt,         // string|null
    ];
}
```

在 backed enum 上调用 `->value` 返回字符串 case 值（例如 `'want-to-watch'`）。以这种方式序列化枚举，而非调用 `->name`——name 是 PHP 标识符（`WantToWatch`），不是 API 契约值。

---

## 相关操作指南

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — 带状态转换的状态机
- [`soft-delete.md`](soft-delete.md) — 使用 `deleted_at` 时间戳的软删除
- [`implement-patch-endpoint.md`](implement-patch-endpoint.md) — 使用 `array_key_exists` 的局部更新
- [`add-custom-route.md`](add-custom-route.md) — POST 动作端点模式（`/archive`、`/restore`、`/publish`）
