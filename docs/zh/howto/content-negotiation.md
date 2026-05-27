# 内容协商

NENE2 是 JSON 优先的框架。它不实现内容协商——无论客户端在 `Accept` 头中发送什么，所有响应都使用 `application/json`（错误响应使用 `application/problem+json`）。

## NENE2 的行为

| 客户端发送 | 服务器返回 |
|---|---|
| 无 `Accept` 头 | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**NENE2 从不返回 `406 Not Acceptable`。** RFC 7231 §6.5.6 指出，当没有可接受的类型时，服务器应当（SHOULD）返回 406，但这是应当（SHOULD，非 MUST）。对于纯 JSON API 服务器，始终返回 JSON 是最简单也是最常见的选择。

无论 `Accept` 如何，错误响应都使用 `application/problem+json`（RFC 9457）：

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## 请求体 Content-Type

`JsonRequestBodyParser::parse()` 不检查传入请求的 `Content-Type` 头。它无条件尝试对请求体进行 JSON 解码：

```php
// 以下三种情况调用 JsonRequestBodyParser::parse() 完全相同：
// Content-Type: application/json → 正常工作
// Content-Type: application/x-www-form-urlencoded → 400（JSON 解析表单体失败）
// （无 Content-Type）+ JSON 请求体 → 正常工作
```

这意味着：
- 没有 `Content-Type` 的有效 JSON 请求体会被接受——宽松输入策略。
- 表单编码的请求体（`name=Alice&age=30`）会导致 400 Bad Request（JSON 解析失败），而非 415 Unsupported Media Type。

## 如果需要 406 或 415 响应

添加一个在路由处理器之前检查 `Accept` 和 `Content-Type` 头的中间件：

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class JsonOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 强制要求 JSON Accept（可选——大多数客户端发送 */* 或 application/json）
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // 对状态变更请求强制要求 JSON Content-Type
        $method      = strtoupper($request->getMethod());
        $contentType = $request->getHeaderLine('Content-Type');
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && $contentType !== ''
            && !str_contains($contentType, 'application/json')
        ) {
            return $this->problems->create($request, 'unsupported-media-type', 'Unsupported Media Type', 415,
                'This API only accepts application/json request bodies.');
        }

        return $handler->handle($request);
    }
}
```

通过 `RuntimeApplicationFactory` 接入：

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **注意**：`authMiddleware` 在路由之前执行。如果想全局应用 Content-Type 强制，请在此处放置。
