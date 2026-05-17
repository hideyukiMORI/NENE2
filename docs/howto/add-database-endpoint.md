# Add a Database-backed Endpoint

This guide shows how to add an endpoint that reads from and writes to a database, following NENE2's domain layer pattern.

**Prerequisite**: You have a working NENE2 application with a route registered. If not, start with [Add a custom route](./add-custom-route.md).

---

## The pattern

NENE2 uses a three-layer pattern between the HTTP handler and the database:

```
HTTP Handler
  ↓ calls
UseCase          ← business logic, no HTTP or database knowledge
  ↓ calls
RepositoryInterface ← database operations, defined as an interface
  ↓ implemented by
PdoRepository    ← the actual SQL queries
```

This is the same separation you get in FastAPI with a service layer, or in Node.js with a repository pattern. The HTTP handler stays thin; the use case holds the logic; the repository handles persistence.

---

## Example: a `Product` resource

We will build `GET /products/{id}` as a concrete example.

### 1 — Define the domain entity

Create `src/Product/Product.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

final readonly class Product
{
    public function __construct(
        public int    $id,
        public string $name,
        public int    $price,
    ) {}
}
```

`readonly` means properties are set once in the constructor and cannot change — equivalent to a frozen object in JavaScript or a dataclass with `frozen=True` in Python.

### 2 — Define the repository interface

Create `src/Product/ProductRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

The interface declares *what* can be done, not *how*. This lets you swap a real database for an in-memory fake in tests.

### 3 — Define the use case

Create `src/Product/GetProductByIdUseCase.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

final readonly class GetProductByIdUseCase
{
    public function __construct(private ProductRepositoryInterface $products) {}

    public function execute(int $id): ?Product
    {
        return $this->products->findById($id);
    }
}
```

The use case knows nothing about HTTP or SQL. It receives a repository and calls it. This makes it easy to test without a database.

### 4 — Implement the repository with PDO

Create `src/Product/PdoProductRepository.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

use PDO;

final readonly class PdoProductRepository implements ProductRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Product(
            id:    (int) $row['id'],
            name:  (string) $row['name'],
            price: (int) $row['price'],
        );
    }
}
```

All SQL lives here. Nothing outside this class needs to know which database or query syntax is used.

### 5 — Wire it in the front controller

In `public/index.php`, connect the pieces and register the route:

```php
<?php

declare(strict_types=1);

use MyApp\Product\GetProductByIdUseCase;
use MyApp\Product\PdoProductRepository;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

// Wire the database and use case.
$pdo        = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'user', 'password');
$useCase    = new GetProductByIdUseCase(new PdoProductRepository($pdo));

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json, $useCase): void {
            $router->get('/products/{id}', static function (ServerRequestInterface $req) use ($json, $useCase) {
                $params  = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
                $id      = (int) ($params['id'] ?? 0);
                $product = $useCase->execute($id);

                if ($product === null) {
                    return $json->create([
                        'type'   => 'https://nene2.dev/problems/not-found',
                        'title'  => 'Not Found',
                        'status' => 404,
                    ], 404);
                }

                return $json->create([
                    'id'    => $product->id,
                    'name'  => $product->name,
                    'price' => $product->price,
                ]);
            });
        },
    ],
))->create();

// ... request handling (same as tutorial)
```

> **Production note**: For larger applications, move the wiring to a service provider
> and inject typed config objects instead of raw PDO connection strings.
> See `src/DependencyInjection/` and `docs/development/domain-layer.md` for the full pattern.

---

## Test the use case without a database

Because `GetProductByIdUseCase` depends on `ProductRepositoryInterface` (not `PdoProductRepository`), you can test it with a simple in-memory fake:

```php
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @param array<int, Product> $products */
    public function __construct(private array $products = []) {}

    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }
}

// In your test:
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

This is the same pattern as mocking a service in Jest or using a test double in pytest.

---

## Directory layout

Following this pattern, your project will grow into:

```
src/
  Product/
    Product.php                   ← domain entity
    ProductRepositoryInterface.php ← what can be done
    GetProductByIdUseCase.php     ← business logic
    PdoProductRepository.php      ← SQL implementation
public/
  index.php                       ← wiring + routes
```

Each resource gets its own directory. Keep the handler thin and the use case focused on one operation.

---

## Next steps

- Add OpenAPI documentation for your endpoint: see `docs/development/endpoint-scaffold.md`
- Add database migrations: see `docs/development/test-database-strategy.md`
- See NENE2's built-in Note example as a reference: `src/Example/Note/`
