# 添加自定义路由

本指南展示如何在 NENE2 应用程序中添加带路径参数的 GET 和 POST 路由。

**前提条件**：您已有一个可运行的 NENE2 应用程序。如果没有，请从[教程](../tutorial/first-api.md)开始。

---

## 添加简单的 GET 路由

路由通过 `routeRegistrars` 注册——这是一个函数数组，每个函数接收路由器并在其上注册路由。

```php
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['items' => [], 'count' => 0]);
            });
        },
    ],
))->create();
```

在 Express 中这是 `app.get('/items', (req, res) => res.json(...))`。模式完全相同——路由、处理器、响应。

---

## 添加路径参数

在路由路径中使用 `{name}` 语法。在处理器中，从 `Router::PARAMETERS_ATTRIBUTE` 请求属性读取所有路径参数——它们存储为命名数组，而非单独的属性。

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
    // 路径参数在单个数组属性中——不是单独的属性。
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

> **常见错误**：`$req->getAttribute('id')` 总是返回 `null`。
> 请始终使用 `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id']`。

在 Express 中是 `req.params.id`，在 FastAPI 中是类型化函数参数。在 NENE2 中是显式数组读取——更冗长但不可能与查询字符串参数混淆。

### 多个参数

```php
$router->get('/users/{userId}/posts/{postId}', static function (ServerRequestInterface $req) use ($json) {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $userId = (int) ($params['userId'] ?? 0);
    $postId = (int) ($params['postId'] ?? 0);

    return $json->create(['userId' => $userId, 'postId' => $postId]);
});
```

---

## 添加查询字符串参数

查询字符串参数从解析后的查询数组中读取，而非从路由模式中读取。

```php
$router->get('/items', static function (ServerRequestInterface $req) use ($json) {
    $query  = $req->getQueryParams();          // ['limit' => '20', 'offset' => '0']
    $limit  = (int) ($query['limit']  ?? 20);
    $offset = (int) ($query['offset'] ?? 0);

    return $json->create(['limit' => $limit, 'offset' => $offset]);
});
```

这等同于 Express 中的 `req.query.limit` 或 FastAPI 中的 `request.query_params['limit']`。

---

## 添加 POST 路由

```php
$router->post('/items', static function (ServerRequestInterface $req) use ($json, $psr17) {
    $body  = json_decode((string) $req->getBody(), true) ?? [];
    $name  = (string) ($body['name'] ?? '');

    if ($name === '') {
        // 返回 422 Validation Failed——完整的验证模式请参见
        // docs/development/endpoint-scaffold.md
        return $json->create(['error' => 'name is required'], 422);
    }

    // 在真实端点中，您会在这里保存到数据库。
    return $json->create(['name' => $name], 201);
});
```

> 对于生产端点，请使用 `ValidationException` 和领域层模式
> 而非内联验证。参见[添加数据库端点](./add-database-endpoint.md)。

---

## 在一个 registrar 中注册多个路由

您可以在单个 registrar 函数中注册任意数量的路由：

```php
routeRegistrars: [
    static function (Router $router) use ($json): void {
        $router->get('/items',         /* handler */);
        $router->get('/items/{id}',    /* handler */);
        $router->post('/items',        /* handler */);
        $router->put('/items/{id}',    /* handler */);
        $router->delete('/items/{id}', /* handler */);
    },
],
```

当路由列表变长时，也可以拆分到多个 registrar 函数中以提高可读性。

> **路由注册顺序**：路由器按**注册顺序**匹配。当静态路由与带参数的路由共享同一前缀时，
> **务必先注册静态路由**。如果先注册 `GET /items/{id}` 再注册 `GET /items/summary`，
> 那么对 `/items/summary` 的请求会以 `id = "summary"` 匹配到 `{id}` 路由 —
> 返回的是令人困惑的领域相关 404，而不是路由错误。
>
> ```php
> // 错误 —— /items/summary 会以 id="summary" 匹配 {id}
> $router->get('/items/{id}',    $this->show(...));
> $router->get('/items/summary', $this->summary(...)); // 永远不会到达
>
> // 正确 —— 先匹配静态段
> $router->get('/items/summary', $this->summary(...));
> $router->get('/items/{id}',    $this->show(...));
> ```

---

## 动作端点（非 CRUD 操作）

有些操作不符合标准 CRUD 形态 —— 归档、发布、审批、恢复。
这类操作请使用 `POST /resource/{id}/action`，响应为 `200 OK`，正文是更新后的资源。

```php
$router->post('/items/{id}/archive', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});

$router->post('/items/{id}/restore', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});
```

> **关于注册顺序**：动作路由（`/items/{id}/archive`）与 show/update 路由共享 `{id}` 段，
> 但因为动作路径在 `{id}` 之后还有一个额外的静态段，所以无论注册顺序如何都不会产生歧义。

### 带可选正文的动作端点

有些动作接受可选的 JSON 正文（例如带可选 `reason` 的 `reject` 动作）。
`JsonRequestBodyParser::parse()` 在正文为空时会抛出 400，因此调用解析器之前请先检查正文是否为空：

```php
$router->post('/items/{id}/reject', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $raw    = (string) $req->getBody();
    $reason = null;

    if ($raw !== '') {
        $body   = JsonRequestBodyParser::parse($req);
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null;
    }

    $item = $repo->reject($id, $reason, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item));
});
```

---

## 可用的 HTTP 方法

| 方法 | Router 方法 | 典型用途 |
|---|---|---|
| GET | `$router->get()` | 读取资源 |
| POST | `$router->post()` | 创建资源或触发动作 |
| PUT | `$router->put()` | 替换资源（完整更新） |
| PATCH | `$router->patch()` | 部分更新 |
| DELETE | `$router->delete()` | 删除资源 |

---

## 框架保留路径

以下路径由 `RuntimeApplicationFactory` 在您的路由 registrar 运行**之前**注册。
由于框架的路由会先被检查，用户为这些路径注册的路由永远不会匹配。

| 路径 | 方法 | 说明 |
|---|---|---|
| `/` | GET | 框架冒烟端点（名称、描述、状态） |
| `/health` | GET | 健康检查 |
| `/machine/health` | GET | 机器客户端健康检查（需要 API 密钥） |
| `/examples/ping` | GET | 示例 ping |
| `/examples/protected` | GET | 示例受保护端点（配置 JWT 时） |

如果您的应用需要首页，请使用其他路径（例如 `/welcome`、`/home`），
或注册健康检查并通过框架的冒烟响应返回 HTML。

---

## 返回 204 No Content（DELETE 端点）

使用 `JsonResponseFactory::createEmpty()` 返回无正文的 204 响应：

```php
private function delete(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $id     = (int) ($params['id'] ?? 0);
    $this->repository->delete($id); // 未找到时抛出 NotFoundException
    return $this->json->createEmpty(204);
}
```

> **为什么不用 `create([], 204)`？** 传入空数组会生成 `{}` 这样的 JSON 正文。
> `createEmpty()` 返回真正没有正文的响应，这才符合 204 No Content 的语义。

---

## 下一步

如果您的路由需要从数据库读取或写入，请参见
[添加数据库端点](./add-database-endpoint.md)。
