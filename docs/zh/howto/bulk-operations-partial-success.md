# 操作指南：带部分成功语义的批量操作

> **FT 参考**：FT258（`NENE2-FT/bulklog`）——带部分成功语义和 HTTP 207 Multi-Status 的批量创建/批量删除

演示如何处理某些项目可能成功而其他项目可能失败的批量 API 操作。每个项目独立处理——项目 N 的验证失败不会中止项目 N+1 及之后的处理。响应携带两个数组：`created`（成功）和 `errors`（失败及原因）。混合结果时返回 HTTP 207 Multi-Status；全部成功时返回 201 Created。

---

## 路由

| 方法 | 路径 | 描述 |
|----------|---------------|-----------------------------------------------|
| `POST` | `/items` | 创建单个条目 |
| `GET` | `/items/{id}` | 获取单个条目 |
| `POST` | `/items/bulk` | 批量创建条目（部分成功） |
| `DELETE` | `/items/bulk` | 按 ID 批量删除条目（部分成功） |

> **路由顺序**：`/items/bulk` 必须在 `/items/{id}` 之前注册，以防止字面量段 `bulk` 被捕获为路径参数。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    price      INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

`sku TEXT NOT NULL UNIQUE` 在数据库层面防止重复 SKU。`price INTEGER` 以最小货币单位（分/日元）存储价格，避免浮点舍入错误。

---

## BulkResult DTO

```php
final readonly class BulkResult
{
    /**
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        public array $created,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`created` 保存成功创建的记录。`errors` 保存逐项错误描述符。`hasErrors()` 是一个简单的谓词，控制器用它来选择 HTTP 状态码。

---

## 批量创建：逐项验证

```php
public function bulkCreate(array $inputs, string $now): BulkResult
{
    $created = [];
    $errors  = [];

    foreach ($inputs as $index => $input) {
        $sku   = isset($input['sku'])   && is_string($input['sku'])   ? trim($input['sku'])   : '';
        $name  = isset($input['name'])  && is_string($input['name'])  ? trim($input['name'])  : '';
        $price = isset($input['price']) && is_int($input['price'])    ? $input['price']       : -1;

        $itemErrors = [];
        if ($sku === '') {
            $itemErrors[] = 'sku is required';
        } elseif ($this->skuExists($sku)) {
            $itemErrors[] = "sku \"{$sku}\" already exists";
        }
        if ($name === '') {
            $itemErrors[] = 'name is required';
        }
        if ($price < 0) {
            $itemErrors[] = 'price must be a non-negative integer';
        }

        if ($itemErrors !== []) {
            $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
            continue;   // 跳过插入，继续处理下一个项目
        }

        $item      = $this->create($sku, $name, $price, $now);
        $created[] = $item->toArray();
    }

    return new BulkResult($created, $errors);
}
```

**关键决策**：
- 验证失败时 `continue`：失败的项目不中止循环。
- 错误条目中包含 `$index`：客户端知道输入数组中的哪个位置失败了。
- SKU 唯一性在 PHP 中检查（`skuExists()`），而非从数据库异常捕获。这提供了更清晰的应用级错误消息，而非原始约束违规。
- 所有成功的 INSERT 共享相同的 `$now` 时间戳：批次被视为单一时间点。

---

## 批量删除：追踪未找到的项目

```php
public function bulkDelete(array $ids): array
{
    $deleted  = [];
    $notFound = [];

    foreach ($ids as $id) {
        $item = $this->findById($id);
        if ($item === null) {
            $notFound[] = $id;
            continue;
        }
        $this->executor->execute('DELETE FROM items WHERE id = ?', [$id]);
        $deleted[] = $id;
    }

    return ['deleted' => $deleted, 'not_found' => $notFound];
}
```

未找到的 ID 被追踪但不中止操作。响应让调用者审计哪些 ID 实际被删除，哪些已经不存在。这里返回 200（而非 207）是合理的，因为所有请求的删除要么成功，要么已经不存在——没有"错误"状态。

---

## 控制器：HTTP 207 Multi-Status

```php
private function bulkCreate(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['items']) || !is_array($body['items'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'items', 'code' => 'required', 'message' => 'items array is required.']],
        ]);
    }

    $inputs = array_values($body['items']);
    $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $result = $this->repo->bulkCreate($inputs, $now);

    $status = $result->hasErrors() ? 207 : 201;   // ← 混合成功+错误时返回 207

    return $this->json->create($result->toArray(), $status);
}
```

**HTTP 状态码选择**：

| 结果 | 状态码 | 含义 |
|---|---|---|
| 全部创建成功 | `201 Created` | 完全成功 |
| 部分创建，部分失败 | `207 Multi-Status` | 部分成功——客户端必须检查响应体 |
| 全部失败 | `207 Multi-Status` | 完全失败——`created` 数组为空 |
| 无 `items` 数组 | `422 Unprocessable Entity` | 格式错误的请求 |

`207` 告知客户端：_不要假设成功——检查响应体_。看到 `201` 的客户端可以假设所有项目都已处理；看到 `207` 的客户端必须检查 `errors`。

**为什么部分失败不返回 422？** `422` 表示整个请求被拒绝。部分成功的批量端点确实成功处理了一些输入，因此 `422` 会产生误导。

---

## 批量删除控制器

```php
private function bulkDelete(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['ids']) || !is_array($body['ids'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'ids', 'code' => 'required', 'message' => 'ids array is required.']],
        ]);
    }

    $ids    = array_values(array_filter($body['ids'], 'is_int'));
    $result = $this->repo->bulkDelete($ids);

    return $this->json->create($result);   // 始终返回 200
}
```

`array_filter($body['ids'], 'is_int')` 静默丢弃 IDs 数组中的非整数值。这是一个设计选择：格式错误的 ID 被忽略而不会导致 422。另一种方法是在任何 ID 为非整数时拒绝整个请求。

---

## 示例请求和响应

### 批量创建——部分成功

**请求** `POST /items/bulk`：
```json
{
  "items": [
    {"sku": "A001", "name": "Widget A", "price": 1000},
    {"sku": "",     "name": "Bad Item",  "price": 500},
    {"sku": "A001", "name": "Duplicate", "price": 200}
  ]
}
```

**响应** `207 Multi-Status`：
```json
{
  "created": [
    {"id": 1, "sku": "A001", "name": "Widget A", "price": 1000, "created_at": "2026-01-01 00:00:00"}
  ],
  "errors": [
    {"index": 1, "sku": "", "errors": ["sku is required"]},
    {"index": 2, "sku": "A001", "errors": ["sku \"A001\" already exists"]}
  ]
}
```

`index` 指向输入 `items` 数组中的位置（从 0 开始）。客户端无需扫描载荷即可将每个错误与原始输入关联起来。

### 批量删除——部分成功

**请求** `DELETE /items/bulk`：
```json
{"ids": [1, 999, 2]}
```

**响应** `200 OK`：
```json
{
  "deleted": [1, 2],
  "not_found": [999]
}
```

---

## 设计权衡

| 方案 | 行为 | 适用场景 |
|---|---|---|
| 全有或全无 | 任何失败都回滚所有 | 金融、库存——需要一致性 |
| 部分成功（本模式） | 每个项目独立处理 | 导入/导出、数据摄取 |
| 即发即忘队列 | 异步处理，延迟结果 | 大批次、后台任务 |

当项目彼此独立时，部分成功是适当的。如果项目 A 的成功依赖于项目 B 的成功（例如，在项目间转移库存），则改用全有或全无的事务。

---

## 相关操作指南

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) ——原子全有或全无多次写入
- [`job-queue-with-retry.md`](job-queue-with-retry.md) ——通过作业队列进行异步批量处理
- [`mass-assignment-defence.md`](mass-assignment-defence.md) ——每个项目的明确 DTO 白名单
