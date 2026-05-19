# Ajouter un endpoint avec base de données

Ce guide montre comment ajouter un endpoint qui lit et écrit dans une base de données, en suivant le pattern de couche domaine de NENE2.

**Prérequis** : Vous avez une application NENE2 fonctionnelle avec une route enregistrée. Sinon, commencez par [Ajouter une route personnalisée](./add-custom-route.md).

---

## Le pattern

NENE2 utilise un pattern à trois couches entre le handler HTTP et la base de données :

```
HTTP Handler
  ↓ appelle
UseCase          ← logique métier, sans connaissance HTTP ou base de données
  ↓ appelle
RepositoryInterface ← opérations base de données, définies comme interface
  ↓ implémentée par
PdoRepository    ← les vraies requêtes SQL
```

C'est la même séparation que dans FastAPI avec une couche service, ou dans Node.js avec un pattern repository. Le handler HTTP reste mince ; le use case contient la logique ; le repository gère la persistance.

---

## Exemple : une ressource `Product`

Nous allons construire `GET /products/{id}` comme exemple concret.

### 1 — Définir l'entité domaine

Créez `src/Product/Product.php` :

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

`readonly` signifie que les propriétés sont définies une fois dans le constructeur et ne peuvent pas changer — équivalent à un objet gelé en JavaScript ou une dataclass avec `frozen=True` en Python.

### 2 — Définir l'interface repository

Créez `src/Product/ProductRepositoryInterface.php` :

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

L'interface déclare *ce qui peut être fait*, pas *comment*. Cela vous permet de remplacer une vraie base de données par un faux en mémoire dans les tests.

### 3 — Définir le use case

Créez `src/Product/GetProductByIdUseCase.php` :

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

Le use case ne sait rien sur HTTP ou SQL. Il reçoit un repository et l'appelle. Cela facilite les tests sans base de données.

### 4 — Implémenter le repository avec PDO

Créez `src/Product/PdoProductRepository.php` :

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

Tout le SQL se trouve ici. Rien à l'extérieur de cette classe n'a besoin de savoir quelle base de données ou syntaxe de requête est utilisée.

### 5 — Câbler dans le contrôleur frontal

Dans `public/index.php`, connectez les pièces et enregistrez la route :

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

// Câbler la base de données et le use case.
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

// ... gestion des requêtes (comme dans le tutoriel)
```

> **Note pour la production** : Pour les applications plus grandes, déplacez le câblage dans un service provider
> et injectez des objets de config typés plutôt que des chaînes de connexion PDO brutes.
> Voir `src/DependencyInjection/` et `docs/development/domain-layer.md` pour le pattern complet.

---

## Tester le use case sans base de données

Comme `GetProductByIdUseCase` dépend de `ProductRepositoryInterface` (pas de `PdoProductRepository`), vous pouvez le tester avec un simple faux en mémoire :

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

// Dans votre test :
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

C'est le même pattern que de mocker un service dans Jest ou d'utiliser un test double dans pytest.

---

## Structure des répertoires

En suivant ce pattern, votre projet évoluera vers :

```
src/
  Product/
    Product.php                    ← entité domaine
    ProductRepositoryInterface.php ← ce qui peut être fait
    GetProductByIdUseCase.php      ← logique métier
    PdoProductRepository.php       ← implémentation SQL
public/
  index.php                        ← câblage + routes
```

Chaque ressource obtient son propre répertoire. Gardez le handler mince et le use case centré sur une opération.

---

## Lever une erreur de validation depuis un handler

Quand un handler doit rejeter une requête parce qu'une valeur de champ est hors limites ou
viole une règle métier, lancez `ValidationException`. `ErrorHandlerMiddleware` la transforme
automatiquement en une réponse `422 validation-failed` Problem Details.

```php
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;

if ($price <= 0) {
    throw new ValidationException([
        new ValidationError(
            field:   'price',
            message: 'Price must be greater than zero.',
            code:    'out_of_range',
        ),
    ]);
}
```

`ValidationError` requiert trois chaînes non vides : `field` (champ concerné), `message` (description), `code` (code lisible par machine, ex. `required`, `out_of_range`).

---

## Initialiser une base de données SQLite

Avec `DB_ADAPTER=sqlite`, le fichier `.db` est créé automatiquement, mais le schéma doit
être appliqué manuellement. Deux approches courantes :

### Approche A — script `composer db:init` (recommandée)

Créer `database/schema.sql` :

```sql
CREATE TABLE IF NOT EXISTS products (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    name  TEXT    NOT NULL,
    price INTEGER NOT NULL
);
```

Ajouter un script dans `composer.json` :

```json
{
    "scripts": {
        "db:init": "php -r \"$pdo = new PDO('sqlite:' . getenv('DB_NAME')); $pdo->exec(file_get_contents('database/schema.sql')); echo 'Schema applied.' . PHP_EOL;\""
    }
}
```

Exécuter une fois avant de démarrer le serveur :

```bash
DB_NAME=./myapp.db composer db:init
```

### Approche B — auto-initialisation dans le contrôleur frontal

Pour les petits projets, vérifier l'existence du fichier dans `public_html/index.php` :

```php
// Appliquer le schéma SQLite automatiquement au premier démarrage.
$dbFile = getenv('DB_NAME') ?: ':memory:';
if ($dbFile !== ':memory:' && !file_exists($dbFile)) {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
}
```

> **Compromis** : l'approche A rend l'initialisation explicite et reproductible en CI.
> L'approche B est commode en développement mais couple la logique de démarrage au contrôleur frontal.

---

## Étapes suivantes

- Ajouter la documentation OpenAPI pour votre endpoint : voir `docs/development/endpoint-scaffold.md`
- Ajouter des migrations de base de données : voir `docs/development/test-database-strategy.md`
- Voir l'exemple Note intégré de NENE2 comme référence : `src/Example/Note/`
