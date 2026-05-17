# Datenbankendpunkt hinzufügen

Diese Anleitung zeigt, wie man einen Endpoint hinzufügt, der eine Datenbank liest und beschreibt, und dabei dem Domain-Layer-Pattern von NENE2 folgt.

**Voraussetzung**: Sie haben eine funktionierende NENE2-Anwendung mit einer registrierten Route. Falls nicht, beginnen Sie mit [Eine Route hinzufügen](./add-custom-route.md).

---

## Das Pattern

NENE2 verwendet ein Drei-Schichten-Pattern zwischen dem HTTP-Handler und der Datenbank:

```
HTTP Handler
  ↓ ruft auf
UseCase          ← Geschäftslogik, ohne HTTP- oder Datenbankkenntnis
  ↓ ruft auf
RepositoryInterface ← Datenbankoperationen, als Interface definiert
  ↓ implementiert durch
PdoRepository    ← die eigentlichen SQL-Queries
```

Das ist die gleiche Trennung wie in FastAPI mit einer Service-Schicht oder in Node.js mit einem Repository-Pattern. Der HTTP-Handler bleibt schlank; der Use Case enthält die Logik; das Repository verwaltet die Persistenz.

---

## Beispiel: eine `Product`-Ressource

Wir werden `GET /products/{id}` als konkretes Beispiel aufbauen.

### 1 — Die Domain-Entität definieren

Erstellen Sie `src/Product/Product.php`:

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

`readonly` bedeutet, dass Eigenschaften einmal im Konstruktor gesetzt werden und sich nicht ändern können — äquivalent zu einem gefrorenen Objekt in JavaScript oder einer Dataclass mit `frozen=True` in Python.

### 2 — Das Repository-Interface definieren

Erstellen Sie `src/Product/ProductRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

Das Interface deklariert *was getan werden kann*, nicht *wie*. Das ermöglicht es, eine echte Datenbank in Tests durch einen In-Memory-Fake zu ersetzen.

### 3 — Den Use Case definieren

Erstellen Sie `src/Product/GetProductByIdUseCase.php`:

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

Der Use Case weiß nichts über HTTP oder SQL. Er empfängt ein Repository und ruft es auf. Das macht es einfach, ohne Datenbank zu testen.

### 4 — Das Repository mit PDO implementieren

Erstellen Sie `src/Product/PdoProductRepository.php`:

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

Alle SQL-Statements befinden sich hier. Nichts außerhalb dieser Klasse muss wissen, welche Datenbank oder Abfragesyntax verwendet wird.

### 5 — Im Front Controller verdrahten

In `public/index.php` verbinden Sie die Teile und registrieren die Route:

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

// Datenbank und Use Case verdrahten.
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

// ... Request-Handling (wie im Tutorial)
```

> **Produktionshinweis**: Für größere Anwendungen verschieben Sie die Verdrahtung in einen Service Provider
> und injizieren Sie typisierte Config-Objekte statt roher PDO-Verbindungsstrings.
> Siehe `src/DependencyInjection/` und `docs/development/domain-layer.md` für das vollständige Pattern.

---

## Den Use Case ohne Datenbank testen

Da `GetProductByIdUseCase` von `ProductRepositoryInterface` abhängt (nicht von `PdoProductRepository`), können Sie ihn mit einem einfachen In-Memory-Fake testen:

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

// In Ihrem Test:
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

Das ist das gleiche Pattern wie das Mocken eines Services in Jest oder die Verwendung eines Test-Doubles in pytest.

---

## Verzeichnisstruktur

Diesem Pattern folgend, wird Ihr Projekt zu:

```
src/
  Product/
    Product.php                    ← Domain-Entität
    ProductRepositoryInterface.php ← was getan werden kann
    GetProductByIdUseCase.php      ← Geschäftslogik
    PdoProductRepository.php       ← SQL-Implementierung
public/
  index.php                        ← Verdrahtung + Routen
```

Jede Ressource bekommt ihr eigenes Verzeichnis. Halten Sie den Handler schlank und den Use Case auf eine Operation fokussiert.

---

## Nächste Schritte

- OpenAPI-Dokumentation für Ihren Endpoint hinzufügen: siehe `docs/development/endpoint-scaffold.md`
- Datenbankmigrationen hinzufügen: siehe `docs/development/test-database-strategy.md`
- NENE2's eingebautes Note-Beispiel als Referenz ansehen: `src/Example/Note/`
