# Adicionar um endpoint com banco de dados

Este guia mostra como adicionar um endpoint que lê e escreve em um banco de dados, seguindo o padrão de camada de domínio do NENE2.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando com uma rota registrada. Se não, comece com [Adicionar uma rota personalizada](./add-custom-route.md).

---

## O padrão

NENE2 usa um padrão de três camadas entre o handler HTTP e o banco de dados:

```
HTTP Handler
  ↓ chama
UseCase          ← lógica de negócio, sem conhecimento de HTTP ou banco de dados
  ↓ chama
RepositoryInterface ← operações de banco de dados, definidas como interface
  ↓ implementada por
PdoRepository    ← as queries SQL reais
```

Esta é a mesma separação que você tem no FastAPI com uma camada de serviço, ou no Node.js com um padrão repository. O handler HTTP fica fino; o use case contém a lógica; o repository gerencia a persistência.

---

## Exemplo: um recurso `Product`

Vamos construir `GET /products/{id}` como exemplo concreto.

### 1 — Definir a entidade de domínio

Crie `src/Product/Product.php`:

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

`readonly` significa que as propriedades são definidas uma vez no construtor e não podem mudar — equivalente a um objeto congelado em JavaScript ou uma dataclass com `frozen=True` em Python.

### 2 — Definir a interface repository

Crie `src/Product/ProductRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

A interface declara *o que pode ser feito*, não *como*. Isso permite trocar um banco de dados real por um fake em memória nos testes.

### 3 — Definir o use case

Crie `src/Product/GetProductByIdUseCase.php`:

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

O use case não sabe nada sobre HTTP ou SQL. Ele recebe um repository e o chama. Isso torna fácil testar sem um banco de dados.

### 4 — Implementar o repository com PDO

Crie `src/Product/PdoProductRepository.php`:

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

Todo o SQL fica aqui. Nada fora desta classe precisa saber qual banco de dados ou sintaxe de query é usada.

### 5 — Conectar no front controller

Em `public/index.php`, conecte as peças e registre a rota:

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

// Conectar o banco de dados e o use case.
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

// ... tratamento de requisição (igual ao tutorial)
```

> **Nota de produção**: Para aplicações maiores, mova a fiação para um service provider
> e injete objetos de configuração tipados em vez de strings de conexão PDO brutas.
> Veja `src/DependencyInjection/` e `docs/development/domain-layer.md` para o padrão completo.

---

## Testando o use case sem banco de dados

Como `GetProductByIdUseCase` depende de `ProductRepositoryInterface` (não de `PdoProductRepository`), você pode testá-lo com um fake simples em memória:

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

// No seu teste:
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

Este é o mesmo padrão que mockar um serviço no Jest ou usar um test double no pytest.

---

## Estrutura de diretórios

Seguindo este padrão, seu projeto crescerá para:

```
src/
  Product/
    Product.php                    ← entidade de domínio
    ProductRepositoryInterface.php ← o que pode ser feito
    GetProductByIdUseCase.php      ← lógica de negócio
    PdoProductRepository.php       ← implementação SQL
public/
  index.php                        ← fiação + rotas
```

Cada recurso tem seu próprio diretório. Mantenha o handler fino e o use case focado em uma operação.

---

## Próximos passos

- Adicionar documentação OpenAPI para seu endpoint: veja `docs/development/endpoint-scaffold.md`
- Adicionar migrações de banco de dados: veja `docs/development/test-database-strategy.md`
- Ver o exemplo Note embutido do NENE2 como referência: `src/Example/Note/`
