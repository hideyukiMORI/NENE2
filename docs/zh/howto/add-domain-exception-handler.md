# 如何添加领域异常处理器

当路由处理器抛出领域异常（例如 `OrderNotFoundException`、`InsufficientStockException`）时，NENE2 的 `ErrorHandlerMiddleware` 会将其委托给第一个声明能处理该异常类型的已注册 `DomainExceptionHandlerInterface`。这使路由处理器无需 try/catch 块，并将错误序列化集中在一处。

## 1. 定义领域异常

```php
// src/Order/OrderNotFoundException.php
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Order #{$id} not found.");
    }
}
```

## 2. 实现 DomainExceptionHandlerInterface

```php
// src/Order/OrderNotFoundExceptionHandler.php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class OrderNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $probs,
    ) {}

    public function supports(Throwable $exception): bool
    {
        return $exception instanceof OrderNotFoundException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->probs->create(
            request: $request,        // ← 必填：用于填充响应中的 'instance'
            type: 'not-found',        // ← 仅使用 slug——工厂会自动加上基础 URL
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### 常见错误

**缺少 `$request`**——`ProblemDetailsResponseFactory::create()` 需要 PSR-7 请求作为第一个参数。省略它会导致运行时 `ArgumentCountError`。

**`type` 中使用完整 URL**——`type` 接受 slug（例如 `'not-found'`），而非完整 URI。工厂会自动加上 `https://nene2.dev/problems/`（或配置的基础 URL）。传入完整 URL 会产生重复路径，如 `https://nene2.dev/problems/https://nene2.dev/problems/not-found`。

**正确的签名：**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // slug
    title: 'Not Found',  // 人类可读的标题
    status: 404,         // HTTP 状态码
    detail: '...',       // 可选的详情字符串
);
```

## 3. 在 RuntimeApplicationFactory 中注册处理器

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // 处理器按顺序尝试——第一个匹配的获胜
    ],
))->create();
```

## 4. 在路由处理器中抛出异常

```php
$router->get('/orders/{id}', static function (ServerRequestInterface $request) use ($orders): ResponseInterface {
    /** @var array<string, string> $params */
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    $order = $orders->findById($id) ?? throw new OrderNotFoundException($id);

    return $json->create(['id' => $order->id]);
});
```

`ErrorHandlerMiddleware` 捕获异常，遍历 `$domainExceptionHandlers` 列表，对每个处理器调用 `supports()`，并将请求委托给第一个匹配的处理器。如果没有处理器匹配，该异常将被视为意外服务器错误（500）。

## 响应结构

上述示例产生的 404 响应：

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "detail": "Order #42 not found.",
    "instance": "/orders/42"
}
```

`instance` 会自动从 `$request->getUri()->getPath()` 填充。

## 故障排查：收到 500 而非预期的错误码

如果领域异常产生了 **500 Internal Server Error** 而非预期的 4xx 响应，最常见的原因是处理器缺失或注册错误：

1. **处理器未添加到 `domainExceptionHandlers`**——再次检查处理器类是否包含在传递给 `RuntimeApplicationFactory` 的数组中。
2. **`supports()` 方法不匹配**——确保 `supports()` 检查的是实际抛出的精确异常类。如果抛出的异常是子类而 `supports()` 使用 `instanceof ExactClass`，子类异常仍会匹配。但如果类层次结构相反（处理器检查父类，异常属于不同分支），则没有处理器会匹配。
3. **处理器已注册但顺序错误**——处理器按顺序尝试。如果一个捕获所有异常的处理器排在第一位，且其 `supports()` 范围太广，它可能会吞掉本应由后面处理器处理的异常。

快速诊断方法：在 `supports()` 检查之前临时添加 `error_log(get_class($exception))` 来打印实际的异常类名。
