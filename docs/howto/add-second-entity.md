# Add a Second Domain Entity

This guide shows how to add a second domain entity alongside the built-in `Note` and `Tag` examples, following NENE2's multi-entity pattern.

**Prerequisite**: You have completed [Add a database-backed endpoint](./add-database-endpoint.md) and understand the UseCase → Repository → Handler three-layer pattern.

---

## Overview

Adding a second entity follows the same structure as the first. The key is how it plugs into the application:

```
src/Example/YourEntity/
  YourEntity.php               ← readonly domain object
  YourEntityRepositoryInterface.php
  PdoYourEntityRepository.php
  GetYourEntityByIdUseCase.php (+ interface)
  GetYourEntityByIdHandler.php
  YourEntityRouteRegistrar.php ← registers routes with __invoke(Router)
  YourEntityServiceProvider.php ← wires DI + route registrar
```

`RuntimeServiceProvider` receives the new route registrar and exception handler — **no changes to `RuntimeApplicationFactory`** are needed.

---

## Step-by-step: a `Product` resource

We will build full CRUD for `/examples/products` — the same pattern used by `Note` and `Tag`.

### 1 — Domain entity

```php
// src/Example/Product/Product.php
<?php
declare(strict_types=1);
namespace MyApp\Example\Product;

final readonly class Product
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $price,
    ) {}
}
```

### 2 — Repository interface and PDO adapter

```php
// src/Example/Product/ProductRepositoryInterface.php
interface ProductRepositoryInterface
{
    public function findById(int $id): Product;
    /** @return list<Product> */
    public function findAll(int $limit, int $offset): array;
}
```

```php
// src/Example/Product/PdoProductRepository.php
final readonly class PdoProductRepository implements ProductRepositoryInterface
{
    public function __construct(private DatabaseQueryExecutorInterface $query) {}

    public function findById(int $id): Product
    {
        $rows = $this->query->select('SELECT id, name, price FROM products WHERE id = ?', [$id]);
        if ($rows === []) {
            throw new ProductNotFoundException($id);
        }
        $r = $rows[0];
        return new Product((int) $r['id'], (string) $r['name'], (int) $r['price']);
    }

    public function findAll(int $limit, int $offset): array
    {
        $rows = $this->query->select('SELECT id, name, price FROM products LIMIT ? OFFSET ?', [$limit, $offset]);
        return array_map(fn ($r) => new Product((int) $r['id'], (string) $r['name'], (int) $r['price']), $rows);
    }
}
```

### 3 — Use cases and handlers

Follow the same pattern as `GetNoteByIdUseCase` / `GetNoteByIdHandler`. Each use case is a single-method class; each handler converts the result to a JSON response.

### 4 — Route registrar

This is the key integration point. Instead of adding parameters to `RuntimeApplicationFactory`, create a `__invoke`-able class:

```php
// src/Example/Product/ProductRouteRegistrar.php
<?php
declare(strict_types=1);
namespace MyApp\Example\Product;

use Nene2\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ProductRouteRegistrar
{
    public function __construct(
        private ListProductsHandler      $listHandler,
        private GetProductByIdHandler    $getHandler,
        private CreateProductHandler     $createHandler,
        private UpdateProductHandler     $updateHandler,
        private DeleteProductHandler     $deleteHandler,
    ) {}

    public function __invoke(Router $router): void
    {
        $list   = $this->listHandler;
        $get    = $this->getHandler;
        $create = $this->createHandler;
        $update = $this->updateHandler;
        $delete = $this->deleteHandler;

        $router->get('/examples/products',      static fn (ServerRequestInterface $r) => $list->handle($r));
        $router->post('/examples/products',     static fn (ServerRequestInterface $r) => $create->handle($r));
        $router->get('/examples/products/{id}', static fn (ServerRequestInterface $r) => $get->handle($r));
        $router->put('/examples/products/{id}', static fn (ServerRequestInterface $r) => $update->handle($r));
        $router->delete('/examples/products/{id}', static fn (ServerRequestInterface $r) => $delete->handle($r));
    }
}
```

### 5 — Service provider

```php
// src/Example/Product/ProductServiceProvider.php
<?php
declare(strict_types=1);
namespace MyApp\Example\Product;

use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
// ... other imports

final readonly class ProductServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(ProductRepositoryInterface::class, static function ($c) { /* ... */ })
            ->set(ListProductsHandler::class,         static function ($c) { /* ... */ })
            ->set(GetProductByIdHandler::class,       static function ($c) { /* ... */ })
            ->set(CreateProductHandler::class,        static function ($c) { /* ... */ })
            ->set(UpdateProductHandler::class,        static function ($c) { /* ... */ })
            ->set(DeleteProductHandler::class,        static function ($c) { /* ... */ })
            ->set(ProductNotFoundExceptionHandler::class, static function ($c) { /* ... */ })
            ->set('nene2.route_registrar.product', static function ($c): ProductRouteRegistrar {
                return new ProductRouteRegistrar(
                    $c->get(ListProductsHandler::class),
                    $c->get(GetProductByIdHandler::class),
                    $c->get(CreateProductHandler::class),
                    $c->get(UpdateProductHandler::class),
                    $c->get(DeleteProductHandler::class),
                );
            });
    }
}
```

### 6 — Wire into RuntimeServiceProvider

Open `src/Http/RuntimeServiceProvider.php` and make two small additions:

```php
// 1. Register the new provider (alongside Note and Tag):
$builder->addProvider(new ProductServiceProvider());

// 2. Pull the registrar and exception handler in the RuntimeApplicationFactory binding:
$productRegistrar      = $container->get('nene2.route_registrar.product');
$productNotFoundHandler = $container->get(ProductNotFoundExceptionHandler::class);

// Add to the arrays:
return new RuntimeApplicationFactory(
    $responseFactory, $streamFactory, $logger, $config->machineApiKey,
    [$noteNotFoundHandler, $tagNotFoundHandler, $productNotFoundHandler],  // ← add here
    $requestIdHolder,
    [$noteRegistrar, $tagRegistrar, $productRegistrar],                    // ← add here
    $bearerMiddleware,
);
```

That is all — `RuntimeApplicationFactory` itself does not change.

---

## Existing examples to reference

| Entity | Source | Endpoints |
|---|---|---|
| `Note` | `src/Example/Note/` | GET/POST `/examples/notes`, GET/PUT/DELETE `/examples/notes/{id}` |
| `Tag` | `src/Example/Tag/` | GET/POST `/examples/tags`, GET/PUT/DELETE `/examples/tags/{id}` |

Both follow this exact pattern. Copy the structure that fits your endpoint set.

---

## Testing

Follow the same approach as `NoteHttpTest`:

```php
$registrar = new ProductRouteRegistrar(
    $listHandler, $getHandler, $createHandler, $updateHandler, $deleteHandler,
);

$application = (new RuntimeApplicationFactory(
    $factory, $factory,
    domainExceptionHandlers: [new ProductNotFoundExceptionHandler($problemDetails)],
    routeRegistrars: [$registrar],
))->create();
```

Pass only the registrar you need — no other entity routes are loaded.

---

## Adding an MCP tool

Once the endpoint is live, add an entry to `docs/mcp/tools.json` and run `composer mcp` to validate. See the [MCP tools policy](../integrations/mcp-tools.md) for the `safety` field guidance — write tools require `NENE2_LOCAL_JWT_SECRET` to be set.
