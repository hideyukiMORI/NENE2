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

---

## 可用的 HTTP 方法

| 方法 | Router 方法 | 典型用途 |
|---|---|---|
| GET | `$router->get()` | 读取资源 |
| POST | `$router->post()` | 创建资源 |
| PUT | `$router->put()` | 替换资源（完整更新） |
| DELETE | `$router->delete()` | 删除资源 |

---

## 下一步

如果您的路由需要从数据库读取或写入，请参见
[添加数据库端点](./add-database-endpoint.md)。
