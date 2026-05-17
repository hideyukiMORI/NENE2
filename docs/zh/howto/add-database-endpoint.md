# 添加数据库端点

本指南展示如何按照 NENE2 的领域层模式添加一个读写数据库的端点。

**前提条件**：您已有一个注册了路由的可运行 NENE2 应用程序。如果没有，请从[添加自定义路由](./add-custom-route.md)开始。

---

## 模式

HTTP 处理器与数据库之间有三层模式：

```
HTTP Handler
  ↓ 调用
UseCase          ← 业务逻辑，不了解 HTTP 或数据库
  ↓ 调用
RepositoryInterface ← 数据库操作，定义为接口
  ↓ 由...实现
PdoRepository    ← 实际的 SQL 查询
```

这与 FastAPI 中的服务层或 Node.js 中的仓库模式是相同的分离。HTTP 处理器保持精简；用例持有逻辑；仓库负责持久化。

---

## 示例：`Product` 资源

我们将以 `GET /products/{id}` 为具体示例进行构建。

### 1 — 定义领域实体

创建 `src/Product/Product.php`：

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

`readonly` 意味着属性在构造函数中设置一次后不可更改——相当于 JavaScript 中的冻结对象或 Python 中 `frozen=True` 的 dataclass。

### 2 — 定义仓库接口

创建 `src/Product/ProductRepositoryInterface.php`：

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

接口声明*可以做什么*，而非*如何做*。这使您可以在测试中用内存中的假对象替换真实数据库。

### 3 — 定义用例

创建 `src/Product/GetProductByIdUseCase.php`：

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

用例对 HTTP 或 SQL 一无所知。它接收仓库并调用它。这使得在没有数据库的情况下测试变得容易。

### 4 — 用 PDO 实现仓库

创建 `src/Product/PdoProductRepository.php`：

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

所有 SQL 都在这里。这个类之外的任何地方都不需要知道使用了哪个数据库或查询语法。

### 5 — 在前端控制器中装配

在 `public/index.php` 中连接各部分并注册路由：

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

// 装配数据库和用例。
$pdo     = new PDO('mysql:host=127.0.0.1;dbname=myapp', 'user', 'password');
$useCase = new GetProductByIdUseCase(new PdoProductRepository($pdo));

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

// ... 请求处理（与教程相同）
```

> **生产注意事项**：对于更大的应用程序，将装配移到服务提供者中
> 并注入类型化配置对象而非原始 PDO 连接字符串。
> 完整模式请参见 `src/DependencyInjection/` 和 `docs/development/domain-layer.md`。

---

## 不使用数据库测试用例

由于 `GetProductByIdUseCase` 依赖 `ProductRepositoryInterface`（而非 `PdoProductRepository`），您可以用简单的内存假对象测试它：

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

// 在您的测试中：
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

这与在 Jest 中模拟服务或在 pytest 中使用测试替身是相同的模式。

---

## 目录结构

遵循此模式，您的项目将成长为：

```
src/
  Product/
    Product.php                    ← 领域实体
    ProductRepositoryInterface.php ← 可以做什么
    GetProductByIdUseCase.php      ← 业务逻辑
    PdoProductRepository.php       ← SQL 实现
public/
  index.php                        ← 装配 + 路由
```

每个资源有自己的目录。保持处理器精简，用例专注于单一操作。

---

## 下一步

- 为您的端点添加 OpenAPI 文档：参见 `docs/development/endpoint-scaffold.md`
- 添加数据库迁移：参见 `docs/development/test-database-strategy.md`
- 参考 NENE2 内置的 Note 示例：`src/Example/Note/`
