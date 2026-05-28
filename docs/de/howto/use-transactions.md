# Datenbanktransaktionen verwenden

Diese Anleitung erklärt, wie atomare mehrstufige Operationen mit `DatabaseTransactionManagerInterface` in NENE2 durchgeführt werden.

**Voraussetzung**: Ein Repository, das auf `DatabaseQueryExecutorInterface` basiert.
Falls nicht, mit [Datenbankgestützten Endpunkt hinzufügen](./add-database-endpoint.md) beginnen.

---

## Warum Transaktionen in NENE2

`DatabaseTransactionManagerInterface` umschließt mehrere SQL-Anweisungen in einer einzigen Transaktion: Entweder alle gelingen (Commit) oder alle werden bei einem beliebigen `Throwable` zurückgerollt.

Das Interface hat eine Methode:

```php
public function transactional(callable $callback): mixed;
```

Der Callback erhält einen **frischen** `DatabaseQueryExecutorInterface`, der an die offene Transaktion gebunden ist. **Dieser Executor unterscheidet sich von dem, der zur Konstruktionszeit injiziert wird.**

---

## Das transaktionale Repository-Muster

> **Warnung — injizierte Repositories nicht im Callback wiederverwenden.**
>
> Zur Konstruktionszeit injizierte Repositories halten eine **andere Verbindung** als die, auf der die Transaktion läuft. Die Verwendung im Callback bedeutet, dass ihre Abfragen außerhalb der Transaktion ausgeführt werden: Rollbacks machen diese Änderungen nicht rückgängig, und uncommittete Zeilen, die innerhalb des Callbacks geschrieben wurden, sind für sie möglicherweise nicht sichtbar.
>
> Dieser Fehler kompiliert und Tests können bestehen — der Bug tritt nur bei gleichzeitigen Schreibvorgängen oder wenn Rollback-Verhalten erwartet wird in Erscheinung.

Da der Callback seinen eigenen Executor bereitstellt, müssen Repository-Klassen **innerhalb des Callbacks instanziiert werden**, wobei der vom Callback bereitgestellte Executor verwendet wird.

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
                // Konkrete Klassen hier instanziieren — der $tx-Executor ist an die
                // Verbindung dieser Transaktion gebunden. Injizierte Instanzen verwenden einen anderen Executor.
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

### Warum injizierte Repositories nicht wiederverwenden?

`PdoDatabaseTransactionManager::transactional()` öffnet eine **neue Verbindung** über `DatabaseConnectionFactoryInterface::create()` und beginnt eine Transaktion darauf. Der Executor des Callbacks ist an diese spezifische Verbindung gebunden.

Ein injiziertes `SqliteProductRepository` hält einen separaten `PdoDatabaseQueryExecutor`, der bei der ersten Verwendung träge seine eigene Verbindung öffnet. Abfragen über das injizierte Repository laufen auf dieser anderen Verbindung — außerhalb der Transaktion — sodass ein Rollback sie nicht rückgängig macht, und ein Insert über das injizierte Repository sieht möglicherweise keine uncommittierten Zeilen aus dem Callback.

---

## Im Front-Controller verdrahten

Es werden zwei separate Objekte benötigt:

| Objekt | Zweck |
|--------|-------|
| `PdoDatabaseQueryExecutor` | Nicht-transaktionale Lesevorgänge (z. B. `GET /products`) |
| `PdoDatabaseTransactionManager` | Umschließt mehrstufige Schreibvorgänge in einer Transaktion |

Beide teilen dieselbe `PdoConnectionFactory`:

```php
$connectionFactory = new PdoConnectionFactory($dbConfig);

$executor  = new PdoDatabaseQueryExecutor($connectionFactory);  // für Read-Repositories
$txManager = new PdoDatabaseTransactionManager($connectionFactory); // für Use Cases

$products = new SqliteProductRepository($executor);  // verwendet von GET /products
$createOrder = new CreateOrderUseCase($txManager);   // verwendet $tx intern
```

---

## Mit dateibasierter SQLite-Datenbank testen

In-Memory-SQLite (`sqlite::memory:`) erstellt eine **separate Datenbank pro Verbindung**, sodass `PdoDatabaseTransactionManager` (der pro `transactional()`-Aufruf eine neue Verbindung öffnet) keine vom `PdoDatabaseQueryExecutor` geschriebenen Zeilen sehen würde und umgekehrt.

Stattdessen eine **temporäre Datei** verwenden:

```php
protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo); // Init-Verbindung schließen, bevor die Factory ihre eigene öffnet

    $dbConfig = new DatabaseConfig(
        url:         null,
        environment: 'test',
        adapter:     'sqlite',
        host:        '',      // für SQLite nicht verwendet
        port:        1,       // für SQLite nicht verwendet
        name:        $this->dbFile,
        user:        '',      // für SQLite nicht verwendet
        password:    '',      // für SQLite nicht verwendet
        charset:     '',      // für SQLite nicht verwendet
    );

    $factory   = new PdoConnectionFactory($dbConfig);
    $executor  = new PdoDatabaseQueryExecutor($factory);
    $txManager = new PdoDatabaseTransactionManager($factory);
    // ... Repositories und Use Cases verdrahten
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

Jeder Test erhält eine frische Datei, sowohl `PdoDatabaseQueryExecutor` als auch `PdoDatabaseTransactionManager` verbinden sich mit derselben Datei, und `tearDown` löscht sie.

> **Hinweis zu SQLite `DatabaseConfig`-Feldern**: Für SQLite sind nur `adapter` und `name` erforderlich. Leere Strings für `host`, `user`, `password` und `charset` übergeben — sie werden nicht validiert, wenn `adapter` `'sqlite'` ist.

> **Hinweis**: `PdoDatabaseQueryExecutor` akzeptiert kein rohes `PDO` als Konstruktorargument — es erfordert eine `DatabaseConnectionFactoryInterface`. `PdoConnectionFactory` (oben gezeigt) verwenden, um ein rohes `PDO`-Setup mit dem Executor zu verbinden.

---

## Rollback-Verhalten verifizieren

Testen, dass ein Fehler mitten in einer Transaktion alle vorherigen Änderungen rückgängig macht:

```php
public function testTransactionRollsBackOnDomainException(): void
{
    // Zwei Produkte seeden
    // ...

    // Bestellung, die beim zweiten Produkt fehlschlägt (unzureichender Bestand)
    $response = $this->request('POST', '/orders', [
        'items' => [
            ['product_id' => $p1Id, 'qty' => 3],   // würde gelingen
            ['product_id' => $p2Id, 'qty' => 99],  // wird fehlschlagen
        ],
    ]);

    self::assertSame(409, $response->getStatusCode());

    // Bestand von Produkt 1 muss unverändert sein — Transaktion wurde zurückgerollt
    $products = $this->json($this->request('GET', '/products'))['items'];
    self::assertSame($originalStock1, $products[0]['stock']);
}
```

---

## Zukünftige Richtung

Das aktuelle Muster erfordert die Instanziierung konkreter Repository-Klassen innerhalb des Callbacks, was bedeutet, dass der Use Case die Repository-Implementierung (`SqliteProductRepository`) kennt und nicht nur das Interface. Das ist eine bekannte Einschränkung.

Eine `RepositoryFactory`-Abstraktion — ein von Use Cases akzeptiertes Interface, das ein Repository für einen gegebenen Executor produzieren kann — würde die vollständige Interface-only-Abhängigkeit wiederherstellen. Dies wird für eine zukünftige NENE2-Version in Betracht gezogen.
