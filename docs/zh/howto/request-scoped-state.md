# 如何在中间件与处理器之间传递请求作用域状态

某些中间件从传入请求中提取值——租户 ID、解码的 JWT 声明、追踪上下文——路由处理器需要在下游获取这些值。本指南展示使用 `RequestScopedHolder` 的推荐模式。

## 持有者模式

`RequestScopedHolder<T>` 是一个小型可变容器。将**一个共享实例**注入到写入它的中间件和读取它的处理器（或仓储层）中：

```php
use Nene2\Http\RequestScopedHolder;

// 共享实例——在组合根处一次性连接。
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// 中间件写入它。
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// 路由处理器读取它。
$routeRegistrar = new TaskRouteRegistrar($repository, $teamId, $json);
```

在中间件内部：

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $raw = $request->getHeaderLine('X-Team-Id');
    // ... 校验 ...
    $this->teamId->set((int) $raw);   // 写入
    return $handler->handle($request);
}
```

在路由处理器内部：

```php
$id = $this->teamId->get();  // 读取——如果中间件未运行则抛出 LogicException
```

## 为什么不用 PSR-7 请求属性？

PSR-7 请求属性是不可变的——每次 `withAttribute()` 调用都返回一个新实例。要将属性从中间件传递到处理器，必须在整个调用链中传递新的请求对象，NENE2 的调度器已经这样做了。当下游代码直接接收 `$request` 时，使用 `withAttribute()` 是合适的。

`RequestScopedHolder` 适用于下游消费者**不**接收 `$request` 的情况——例如，只了解领域类型且无法接受 PSR-7 请求作为依赖的仓储层。

## 堆叠多个中间件

传递列表给 `RuntimeApplicationFactory::$authMiddleware` 以顺序运行多个中间件：

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // 先运行
        new BearerTokenMiddleware($probs, $verifier), // 后运行
    ],
))->create();
```

两个中间件共享相同的管道位置（在请求大小限制之后，在限流之前）。列表中的第一项在第二项之前处理请求。

## 安全性：PHP 无共享模型

在 PHP-FPM 和 CLI 中，每个请求在全新进程中运行。请求 A 期间设置的 `RequestScopedHolder` 值对请求 B 永远不可见，因为每个进程在处理一个请求后退出。在此模型下，持有者可以安全使用。

### 异步运行时（Swoole、ReactPHP、FrankenPHP 工作者模式）

当多个请求共享同一个 PHP 进程时，请求 A 期间写入的持有者值会保留到请求 B，除非明确清除。在每个请求周期开始（或结束）时调用 `reset()`：

```php
// 示例 Swoole 请求处理器
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // 清除上一请求的值
    $psrRequest = /* 转换 */;
    $psrResponse = $app->handle($psrRequest);
    // 发送 $psrResponse ...
});
```

NENE2 目前面向 PHP-FPM / CLI，不内置异步支持。如果运行异步运行时，需要自行负责在请求间重置共享持有者。
