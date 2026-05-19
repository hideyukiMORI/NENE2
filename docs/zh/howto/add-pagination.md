# 添加分页

本指南介绍如何使用 `Nene2\Http` 中的 `PaginationQueryParser` 助手，为集合端点添加 `?limit=` / `?offset=` 分页功能。

## 前提条件

- 有一个可用的集合处理器（如 `ListNotesHandler`）。
- 处理器返回包含 `items`、`limit` 和 `offset` 的 JSON 信封。

## 步骤 1 — 调用 `PaginationQueryParser::parse()`

用解析器替换手动查询参数提取。它会验证值，并在超出范围时抛出 `ValidationException`（→ 422）。

```php
use Nene2\Http\PaginationQueryParser;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request); // 默认：limit=20, max=100

    $output = $this->useCase->execute(
        new ListWidgetsInput($pagination->limit, $pagination->offset),
    );

    return $this->response->create([
        'items'  => /* 映射 $output->items */,
        'limit'  => $output->limit,
        'offset' => $output->offset,
    ]);
}
```

`PaginationQuery` 是一个包含两个属性的 readonly DTO：`limit: int` 和 `offset: int`。

## 步骤 2 — 自定义限制（可选）

传入 `$defaultLimit` 和 `$maxLimit` 来覆盖默认值（20 和 100）：

```php
$pagination = PaginationQueryParser::parse($request, defaultLimit: 10, maxLimit: 50);
```

| 参数 | 默认值 | 含义 |
|---|---|---|
| `$defaultLimit` | `20` | 当 `?limit=` 缺失时使用的值 |
| `$maxLimit` | `100` | 允许的最大值；超出则返回 422 |

## 步骤 3 — 处理 422 错误

`PaginationQueryParser::parse()` 在以下情况下抛出 `ValidationException`：

- `limit < 1` 或 `limit > $maxLimit`
- `offset < 0`

`ErrorHandlerMiddleware` 会自动将 `ValidationException` 映射为 `422 validation-failed`，处理器中无需额外错误处理。

**422 响应示例：**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    { "field": "limit", "message": "limit must be between 1 and 100.", "code": "out_of_range" }
  ]
}
```

## 工作原理

`PaginationQueryParser::parse()` 读取 PSR-7 请求的 `getQueryParams()`，将值转换为 `int`，进行验证后返回 `PaginationQuery` DTO。非数字值会被转换为 `0`（PHP 的 `(int)` 转换行为），然后被 `limit < 1` 检查捕获。

## 第 4 步 — 使用 `PaginationResponse` 标准化响应结构

`PaginationResponse` 是一个 readonly DTO，用于构建标准的列表响应结构：

```php
use Nene2\Http\PaginationResponse;

return $this->response->create(
    (new PaginationResponse(
        items:  array_map(fn ($item) => ['id' => $item->id, 'name' => $item->name], $output->items),
        limit:  $output->limit,
        offset: $output->offset,
    ))->toArray(),
);
```

## 第 5 步 — 包含总记录数（可选）

当仓储支持计数查询时传入 `total`：

```php
$total = $this->repository->countAll(); // SELECT COUNT(*) AS n FROM ...

return $this->response->create(
    (new PaginationResponse(items: /* ... */, limit: $output->limit, offset: $output->offset, total: $total))->toArray(),
);
```

当 `total` 为 `null`（默认）时，响应中不包含该键。

> **权衡**：`COUNT(*)` 每次请求增加一个查询。若开销不可接受，可省略 `total`，
> 让客户端通过 `items.length < limit` 判断是否为最后一页。

## 另请参阅

- `src/Example/Note/ListNotesHandler.php` — 使用 `PaginationResponse` 的参考实现
- `src/Example/Tag/ListTagsHandler.php` — 第二个示例
- `Nene2\Http\PaginationQuery` — 解析后参数的 readonly DTO
- `Nene2\Http\PaginationQueryParser` — 解析器类
- `Nene2\Http\PaginationResponse` — 列表结构 DTO
