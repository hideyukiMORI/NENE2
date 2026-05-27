# 操作指南：内容协商——JSON API

> **FT 参考**：FT301（`NENE2-FT/contentlog`）——JSON API 内容协商：无论 `Accept` 头是什么都始终返回 `application/json`，错误时使用 `application/problem+json`（404/422/405），POST 请求使用非 JSON `Content-Type` 时返回 415，16 个测试 / 28 个断言全部通过。

本指南介绍 NENE2 运行时如何处理 JSON API 的 HTTP 内容协商——接受哪些 `Accept` 头值、何时 `Content-Type` 重要，以及错误响应如何使用 `application/problem+json`。

## 始终返回 JSON——忽略 Accept 头

NENE2 JSON API 的成功响应始终返回 `application/json`，无论客户端发送的 `Accept` 头是什么：

| 发送的 Accept 头 | 响应 Content-Type |
|---|---|
| _（无）_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

这对于纯 API 服务是有意为之的：服务器是纯 API 端点，而非支持内容协商的多格式服务器。发送 `Accept: text/html` 的客户端仍会收到 JSON。

## 错误响应——application/problem+json

错误响应使用 `application/problem+json`（RFC 9457），无论 `Accept` 头是什么：

| 场景 | 状态码 | Content-Type |
|---|---|---|
| 路由未找到 | 404 | `application/problem+json` |
| 方法不允许 | 405 | `application/problem+json` |
| 校验失败 | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory 始终生成 application/problem+json
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

客户端可以通过 HTTP 状态码或检查 `Content-Type: application/problem+json` 来检测错误。

## 请求 Content-Type——POST 请求体

对于带 JSON 请求体的 `POST` 请求，NENE2 使用 `JsonRequestBodyParser::parse()`：

```php
$body = JsonRequestBodyParser::parse($request);
```

如果请求有明确的 `Content-Type: text/plain` 或类似的非 JSON 类型，解析器可能返回空数组。但如果请求体是有效 JSON 而根本没有 `Content-Type` 头，解析器会接受它：

```
POST /articles（无 Content-Type，JSON 请求体）→ 201 Created ✅
POST /articles（Content-Type: text/plain）→ 415 Unsupported Media Type ✅
```

## 校验——必填字段

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

if ($title === '') {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
    ]);
}
```

`trim()` 之后，空字符串与缺失字段同等对待。校验错误返回结构化的 `errors` 数组，包含 `field`、`code` 和 `message` 键——标准 RFC 9457 扩展。

## 响应结构

```json
// GET /articles
{
    "items": [
        { "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }
    ],
    "total": 1
}

// POST /articles → 201
{ "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }

// GET /articles/999 → 404 (application/problem+json)
{ "type": "https://nene2.dev/problems/not-found", "title": "Article Not Found", "status": 404 }
```

## 路由注册

```php
$router->post('/articles', $this->createArticle(...));
$router->get('/articles', $this->listArticles(...));
$router->get('/articles/{id}', $this->getArticle(...));
```

`GET /articles`（列表）在 `GET /articles/{id}`（单个）之前注册——不过在这种情况下，两者都是 GET 但路径不同，所以顺序不会造成捕获冲突。列表路由使用静态路径；单个路由使用 `{id}` 动态捕获。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 对不支持的 `Accept` 头返回 406 | 纯 API 服务应该向所有客户端提供 JSON，而不是拒绝 |
| 使用 `text/json` 而不是 `application/json` | 非标准 MIME 类型；某些客户端无法识别 |
| 错误响应使用普通 `application/json` | 客户端无法通过 Content-Type 区分错误和成功；使用 `application/problem+json` |
| 省略校验错误的 `errors` 数组 | 客户端无法向用户显示字段级错误消息 |
| 接受 `Content-Type: text/plain` 作为 JSON 请求体 | 输入不明确；明确规定接受哪些内容类型 |
| 在校验之后才 trim | `trim()` 必须在空字符串检查之前；如果先检查再 trim，`" "` 会通过 |
