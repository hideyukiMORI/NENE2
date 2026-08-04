# Utiliser les transactions de base de données

Ce guide explique comment effectuer des opérations atomiques multi-étapes en utilisant
`DatabaseTransactionManagerInterface` dans NENE2.

**Prérequis** : Vous avez un repository soutenu par `DatabaseQueryExecutorInterface`.
Si ce n'est pas le cas, commencez par [Ajouter un endpoint soutenu par une base de données](./add-database-endpoint.md).

> **🚫 SQLite `:memory:` est incompatible avec `transactional()`.**
>
> `PdoDatabaseTransactionManager` ouvre une *nouvelle* connexion à chaque appel. Chaque connexion
> `:memory:` pointe vers une base en mémoire vide *différente*, de sorte que l'exécuteur et la
> transaction voient des données différentes et que les annulations n'ont aucun effet sur la vue de
> l'exécuteur. Le symptôme est silencieux : les requêtes renvoient `null` au milieu du callback, ou
> les annulations ne défont pas les écritures effectuées par le test via un exécuteur distinct.
>
> Utilisez une base SQLite **basée sur un fichier** dans les tests (voir « [Tester avec une base de
> données SQLite basée sur des fichiers](#tester-avec-une-base-de-données-sqlite-basée-sur-des-fichiers) »
> ci-dessous). `Nene2\Testing\DatabaseTestKit::sqlite(':memory:')` rejette `:memory:` avec une
> `InvalidArgumentException` pour que l'échec soit immédiat.

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

Utiliser un **fichier temporaire** à la place. `Nene2\Testing\DatabaseTestKit` câble l'exécuteur et
le gestionnaire de transactions sur le même fichier en une ligne :

```php
use Nene2\Testing\DatabaseTestKit;

protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/' . uniqid('test-', true) . '.sqlite';

    // Injecter le schéma via une connexion jetable, puis la fermer avant que le kit ouvre la sienne.
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo);

    $this->kit = DatabaseTestKit::sqlite($this->dbFile);
    // $this->kit->queryExecutor       — pour les repositories en lecture
    // $this->kit->transactionManager  — pour les use cases
    // $this->kit->connectionFactory   — si vous devez construire d'autres exécuteurs
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

Le kit se trouve dans `Nene2\Testing\DatabaseTestKit` (ADR 0012, API publique). Il câble en interne
`PdoConnectionFactory` + `PdoDatabaseQueryExecutor` + `PdoDatabaseTransactionManager`, tous
partageant le même fichier, de sorte que les tests n'ont besoin de référencer aucune classe
`@internal` par son nom. `DatabaseTestKit::sqlite(':memory:')` ainsi que les combinaisons de
configuration sous-jacentes sont bloqués au niveau de la factory.

> **`DatabaseConfig::sqlite(string $path)`** est le raccourci équivalent lorsque vous voulez
> garder un câblage explicite (par exemple pour injecter votre propre sous-classe de
> `PdoConnectionFactory`). Il remplace la forme à 9 arguments `new DatabaseConfig(...)` montrée
> dans les guides plus anciens.

---

## Vérifier le comportement d'annulation

Un use case qui englobe plusieurs écritures n'est correct que si le chemin d'annulation **défait
réellement** les écritures précédentes. Un test qui ne couvre que le cas nominal passera même si le
use case oublie d'utiliser `$tx` — `$this->products` s'exécutera alors hors de la transaction et
sera validé silencieusement. C'est le test d'annulation qui attrape le bug.

### Annulation au niveau unitaire

Pilotez le use case directement avec `DatabaseTestKit` et vérifiez l'état de la base après
l'exception :

```php
public function testRollbackUndoesStockDecrementWhenOrderInsertFails(): void
{
    $kit = DatabaseTestKit::sqlite($this->dbFile);
    $kit->queryExecutor->execute(/* seed : produit 1 avec stock=10 */);

    $useCase = new CreateOrderUseCase($kit->transactionManager);

    try {
        // Passer une quantité qui réussit à decrementStock mais déclenche une violation de
        // contrainte d'unicité dans orders.save (p. ex. clé d'idempotence dupliquée).
        $useCase->execute(productId: 1, qty: 3, idempotencyKey: $existingKey);
        self::fail('Expected order creation to fail.');
    } catch (DatabaseConstraintException) {
        // attendu
    }

    // Le decrementStock de l'étape 1 doit avoir été annulé.
    $row = $kit->queryExecutor->fetchOne('SELECT stock FROM products WHERE id = ?', [1]);
    self::assertSame(10, $row['stock']);
}
```

Ce test échoue immédiatement si le use case appelle `$this->products->decrementStock()` au lieu de
construire un repository à partir de `$tx` — la décrémentation du stock survit à l'annulation et
l'assertion la détecte.

### Annulation au niveau HTTP

La même propriété au niveau de l'intégration :

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
