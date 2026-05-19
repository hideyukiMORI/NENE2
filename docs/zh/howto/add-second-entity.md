# 添加第二个领域实体

本指南介绍如何按照内置 `Note` 和 `Tag` 示例的模式添加第二个领域实体。

**前提条件**：已完成[添加数据库端点](./add-database-endpoint.md)。

---

## 概述

每个实体遵循相同的结构：

```
src/Example/YourEntity/
  YourEntity.php
  YourEntityRepositoryInterface.php
  PdoYourEntityRepository.php
  GetYourEntityByIdHandler.php
  YourEntityRouteRegistrar.php   ← 通过 __invoke(Router) 注册路由
  YourEntityServiceProvider.php  ← DI 配置 + 路由注册器
```

只需在 `RuntimeServiceProvider` 中添加新的注册器和异常处理器 — **`RuntimeApplicationFactory` 无需修改**。

---

## 关键步骤

### 1 — RouteRegistrar

路径参数（如 `/products/{id}` 中的 `id`）在处理器中通过 `Router::PARAMETERS_ATTRIBUTE` 读取，而非独立的 PSR-7 属性：

```php
$params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
$id     = (int) ($params['id'] ?? 0);
// $request->getAttribute('id') 总是返回 null。
```

```php
final readonly class ProductRouteRegistrar
{
    public function __construct(
        private GetProductByIdHandler $getHandler,
        private ListProductsHandler $listHandler,
    ) {}

    public function __invoke(Router $router): void
    {
        $get  = $this->getHandler;
        $list = $this->listHandler;
        $router->get('/examples/products', static fn ($r) => $list->handle($r));
        $router->get('/examples/products/{id}', static fn ($r) => $get->handle($r));
    }
}
```

### 2 — ServiceProvider

在 `register()` 中将注册器注册为 `nene2.route_registrar.product`。

### 3 — 在 RuntimeServiceProvider 中配置

```php
$builder->addProvider(new ProductServiceProvider());

// set() 在 addProvider() 之后调用时，会覆盖提供者注册的服务（后写优先）。
// $builder->set(RuntimeApplicationFactory::class, static fn($c) => new RuntimeApplicationFactory(/* ... */));

return new RuntimeApplicationFactory(
    /* ... */,
    [$noteNotFoundHandler, $tagNotFoundHandler, $productNotFoundHandler],
    $requestIdHolder,
    [$noteRegistrar, $tagRegistrar, $productRegistrar],
    $bearerMiddleware,
);
```

详细内容请参阅英文版或 `src/Example/` 中的 `Note`/`Tag` 示例。
