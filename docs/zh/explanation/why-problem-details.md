# 为什么使用 RFC 9457 Problem Details？

NENE2 的 API 错误使用 RFC 9457 Problem Details 格式。本页解释这一选择。

## Problem Details 的外观

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "title", "code": "required", "message": "Title is required." }
  ]
}
```

## 为什么使用标准而非自定义格式？

### 1. 客户端可以通用地处理错误

了解 RFC 9457 的客户端可以显示任何 RFC 9457 API 的 `title` 和 `status`，无需了解特定应用程序。

### 2. `Content-Type: application/problem+json` 是机器可读的

当响应携带 `application/problem+json` 时，客户端知道收到了错误对象。这对 MCP 工具等机器客户端至关重要。

### 3. `type` URI 赋予错误稳定的标识

每个问题类型都有一个 URI，如 `https://nene2.dev/problems/validation-failed`。该 URI 稳定、可文档化，且客户端可通过 `type` 字符串进行模式匹配。

### 4. 这是已发布的标准

RFC 9457（RFC 7807 的继承者）是已发布的 IETF 标准，无需为每个 API 消费者单独说明错误格式。

## `nene2.dev` URI 说明

NENE2 的 `type` URI 目前使用 `https://nene2.dev/problems/...` 作为占位域名。在项目投产前，部署者需要注册该域名或在 `ProblemDetailsResponseFactory` 中替换为项目特定域名。
