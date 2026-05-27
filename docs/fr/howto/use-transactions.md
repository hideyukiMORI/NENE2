# Utiliser les transactions de base de données

Ce guide explique comment effectuer des opérations atomiques multi-étapes en utilisant
`DatabaseTransactionManagerInterface` dans NENE2.

**Prérequis** : Vous avez un repository soutenu par `DatabaseQueryExecutorInterface`.
Si ce n'est pas le cas, commencez par [Ajouter un endpoint soutenu par une base de données](./add-database-endpoint.md).

---

## Pourquoi utiliser les transactions dans NENE2

`DatabaseTransactionManagerInterface` enveloppe plusieurs instructions SQL dans une seule transaction :
soit toutes réussissent (commit), soit toutes sont annulées en cas de `Throwable`.

L'interface a une seule méthode :

```php
public function transactional(callable $callback): mixed;
```

Le callback reçoit un `DatabaseQueryExecutorInterface` **fraîchement créé** lié à la
transaction ouverte. **Cet exécuteur est différent de celui que vous injectez au moment de la construction.**

---

## Le pattern de repository transactionnel

> **Avertissement — ne pas réutiliser les repositories injectés à l'intérieur du callback.**
>
> Les repositories injectés au moment de la construction détiennent une **connexion différente** de
> celle sur laquelle la transaction s'exécute. Les utiliser à l'intérieur du callback signifie que
> leurs requêtes s'exécutent en dehors de la transaction : les annulations ne défont pas ces
> modifications, et les lignes non commitées écrites à l'intérieur du callback peuvent ne pas leur
> être visibles.
>
> Cette erreur compile et les tests peuvent passer — le bug ne se manifeste que sous des
> écritures concurrentes ou quand vous comptez sur le comportement d'annulation.

Parce que le callback fournit son propre exécuteur, vous devez **instancier les classes de repository
à l'intérieur du callback** en utilisant l'exécuteur que le callback fournit.

```php
<?php

declare(strict_types=1);

namespace MyApp\Order;

use MyApp\Product\ProductNotFoundException;
use MyApp\Product\SqliteProductRepository;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;

final class CreateOrderUseCase
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $transactionManager,
    ) {}

    public function execute(int $productId, int $qty): Order
    {
        return $this->transactionManager->transactional(
            function (DatabaseQueryExecutorInterface $tx) use ($productId, $qty): Order {
                // Doit instancier les classes concrètes ici — l'exécuteur $tx est lié à
                // la connexion de cette transaction. Les instances injectées utilisent un exécuteur différent.
                $products = new SqliteProductRepository($tx);
                $orders   = new SqliteOrderRepository($tx);

                $product = $products->findById($productId)
                    ?? throw new ProductNotFoundException($productId);

                $products->decrementStock($productId, $qty);

                return $orders->save($product->price * $qty, [
                    new OrderItem($productId, $qty, $product->price),
                ]);
            },
        );
    }
}
```

### Pourquoi ne pas réutiliser les repositories injectés ?

`PdoDatabaseTransactionManager::transactional()` ouvre une **nouvelle connexion** via
`DatabaseConnectionFactoryInterface::create()` et commence une transaction sur elle.
L'exécuteur du callback est lié à cette connexion spécifique.

Un `SqliteProductRepository` injecté détient un `PdoDatabaseQueryExecutor` séparé qui
ouvre paresseusement sa propre connexion à la première utilisation. Les requêtes via le repository
injecté s'exécutent sur cette autre connexion — en dehors de la transaction — donc une annulation
ne les défait pas, et un insert via le repository injecté peut ne pas voir les lignes non commitées
du callback.

---

## Le câbler dans votre front controller

Vous avez besoin de deux objets séparés :

| Objet | Objectif |
|-------|---------|
| `PdoDatabaseQueryExecutor` | Lectures non-transactionnelles (ex. `GET /products`) |
| `PdoDatabaseTransactionManager` | Enveloppe les écritures multi-étapes dans une transaction |

Les deux partagent la même `PdoConnectionFactory` :

```php
$connectionFactory = new PdoConnectionFactory($dbConfig);

$executor  = new PdoDatabaseQueryExecutor($connectionFactory);  // pour les repositories en lecture
$txManager = new PdoDatabaseTransactionManager($connectionFactory); // pour les use cases

$products = new SqliteProductRepository($executor);  // utilisé par GET /products
$createOrder = new CreateOrderUseCase($txManager);   // utilise $tx en interne
```

---

## Tester avec une base de données SQLite basée sur des fichiers

Le SQLite en mémoire (`sqlite::memory:`) crée une **base de données séparée par connexion**, donc
`PdoDatabaseTransactionManager` (qui ouvre une nouvelle connexion par appel `transactional()`)
ne verrait pas les lignes écrites par `PdoDatabaseQueryExecutor` et vice-versa.

Utiliser un **fichier temporaire** à la place :

```php
protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo); // fermer la connexion d'initialisation avant que la factory ouvre la sienne

    $dbConfig = new DatabaseConfig(
        url:         null,
        environment: 'test',
        adapter:     'sqlite',
        host:        '',      // inutilisé pour SQLite
        port:        1,       // inutilisé pour SQLite
        name:        $this->dbFile,
        user:        '',      // inutilisé pour SQLite
        password:    '',      // inutilisé pour SQLite
        charset:     '',      // inutilisé pour SQLite
    );

    $factory   = new PdoConnectionFactory($dbConfig);
    $executor  = new PdoDatabaseQueryExecutor($factory);
    $txManager = new PdoDatabaseTransactionManager($factory);
    // ... câbler les repositories et les use cases
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

Chaque test obtient un fichier fraîchement créé, `PdoDatabaseQueryExecutor` et
`PdoDatabaseTransactionManager` se connectent au même fichier, et `tearDown` le supprime.

> **Note sur les champs SQLite `DatabaseConfig`** : Pour SQLite, seuls `adapter` et `name`
> sont requis. Passer des chaînes vides pour `host`, `user`, `password`, et `charset` — ils
> ne sont pas validés quand `adapter` est `'sqlite'`.

> **Note** : `PdoDatabaseQueryExecutor` n'accepte pas un `PDO` brut comme argument de constructeur
> — il nécessite un `DatabaseConnectionFactoryInterface`. Utiliser `PdoConnectionFactory`
> (montré ci-dessus) pour relier une configuration `PDO` brute à l'exécuteur.

---

## Vérifier le comportement d'annulation

Tester qu'un échec au milieu d'une transaction défait toutes les modifications précédentes :

```php
public function testTransactionRollsBackOnDomainException(): void
{
    // initialiser deux produits
    // ...

    // Commande qui échouera sur le deuxième produit (stock insuffisant)
    $response = $this->request('POST', '/orders', [
        'items' => [
            ['product_id' => $p1Id, 'qty' => 3],   // réussirait
            ['product_id' => $p2Id, 'qty' => 99],  // échouera
        ],
    ]);

    self::assertSame(409, $response->getStatusCode());

    // Le stock du produit 1 doit être inchangé — la transaction a été annulée
    $products = $this->json($this->request('GET', '/products'))['items'];
    self::assertSame($originalStock1, $products[0]['stock']);
}
```

---

## Direction future

Le pattern actuel nécessite d'instancier des classes de repository concrètes à l'intérieur du
callback, ce qui signifie que le use case connaît l'implémentation du repository
(`SqliteProductRepository`) plutôt que son interface. C'est une limitation connue.

Une abstraction `RepositoryFactory` — une interface acceptée par les use cases qui peut produire
un repository pour un exécuteur donné — restaurerait la dépendance uniquement par interface.
Cela est suivi pour considération dans une future version de NENE2.
