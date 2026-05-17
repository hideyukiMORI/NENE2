# Problem Details 类型

NENE2 的所有错误响应均返回 `application/problem+json`，遵循 [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)。

## 类型目录

| `type` | HTTP 状态码 | `title` | 产生来源 |
|---|---|---|---|
| `…/not-found` | 404 | Not Found | 路由未找到；指定 id 的 Note 或 Tag 不存在 |
| `…/method-not-allowed` | 405 | Method Not Allowed | 已知路由的错误 HTTP 方法 |
| `…/validation-failed` | 422 | Validation Failed | 请求体无效或缺少必填字段 |
| `…/unauthorized` | 401 | Unauthorized | Bearer 令牌缺失或无效 |
| `…/payload-too-large` | 413 | Payload Too Large | 请求体超过配置的大小限制 |
| `…/internal-server-error` | 500 | Internal Server Error | 未处理异常 |

基础 URI 前缀：`https://nene2.dev/problems/`

## 添加自定义类型

1. 创建领域异常类（如 `ProductNotFoundException`）。
2. 实现 `DomainExceptionHandlerInterface`，调用 `ProblemDetailsResponseFactory::create()`。
3. 在 `RuntimeServiceProvider` 中注册处理程序。

请参考 `NoteNotFoundExceptionHandler` 和 `TagNotFoundExceptionHandler` 的具体实现。
