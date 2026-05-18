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

## 另请参阅

- `src/Example/Note/ListNotesHandler.php` — 使用解析器的参考实现
- `src/Example/Tag/ListTagsHandler.php` — 第二个示例
- `Nene2\Http\PaginationQuery` — readonly DTO
- `Nene2\Http\PaginationQueryParser` — 解析器类
