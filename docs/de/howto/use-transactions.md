# Datenbanktransaktionen verwenden

Diese Anleitung erklärt, wie atomare mehrstufige Operationen mit `DatabaseTransactionManagerInterface` in NENE2 durchgeführt werden.

**Voraussetzung**: Ein Repository, das auf `DatabaseQueryExecutorInterface` basiert.
Falls nicht, mit [Datenbankgestützten Endpunkt hinzufügen](./add-database-endpoint.md) beginnen.

> **🚫 SQLite `:memory:` ist mit `transactional()` nicht kompatibel.**
>
> `PdoDatabaseTransactionManager` öffnet pro Aufruf eine *neue* Verbindung. Jede
> `:memory:`-Verbindung zeigt auf eine *andere* leere In-Memory-Datenbank, sodass Executor und
> Transaktion unterschiedliche Daten sehen und Rollbacks keinerlei Wirkung auf die Sicht des
> Executors haben. Das Symptom ist still: Abfragen liefern mitten im Callback `null`, oder
> Rollbacks machen Schreibvorgänge nicht rückgängig, die der Test über einen separaten Executor
> ausgeführt hat.
>
> Verwenden Sie in Tests eine **dateibasierte SQLite**-Datenbank (siehe „[Mit dateibasierter
> SQLite-Datenbank testen](#mit-dateibasierter-sqlite-datenbank-testen)" unten).
> `Nene2\Testing\DatabaseTestKit::sqlite(':memory:')` weist `:memory:` mit einer
> `InvalidArgumentException` ab, damit dies fail-fast auffällt.

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

Stattdessen eine **temporäre Datei** verwenden. `Nene2\Testing\DatabaseTestKit` verdrahtet den
Executor und den Transaction Manager in einer Zeile auf dieselbe Datei:

```php
use Nene2\Testing\DatabaseTestKit;

protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/' . uniqid('test-', true) . '.sqlite';

    // Schema über eine Wegwerf-Verbindung einspielen und diese schließen, bevor das Kit seine eigene öffnet.
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo);

    $this->kit = DatabaseTestKit::sqlite($this->dbFile);
    // $this->kit->queryExecutor       — für lesende Repositories
    // $this->kit->transactionManager  — für Use Cases
    // $this->kit->connectionFactory   — falls Sie weitere Executors bauen müssen
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

Das Kit liegt unter `Nene2\Testing\DatabaseTestKit` (ADR 0012, öffentliche API). Es verdrahtet
intern `PdoConnectionFactory` + `PdoDatabaseQueryExecutor` + `PdoDatabaseTransactionManager`,
die sich alle dieselbe Datei teilen, sodass Tests keine `@internal`-Klasse namentlich referenzieren
müssen. Sowohl `DatabaseTestKit::sqlite(':memory:')` als auch die zugrunde liegenden
Konfigurationskombinationen werden bereits in der Factory blockiert.

> **`DatabaseConfig::sqlite(string $path)`** ist die entsprechende Abkürzung, wenn Sie die
> Verdrahtung explizit halten möchten (z. B. um eine eigene Unterklasse von
> `PdoConnectionFactory` zu injizieren). Sie ersetzt die 9-Argument-Form
> `new DatabaseConfig(...)` aus älteren Anleitungen.

---

## Rollback-Verhalten verifizieren

Ein Use Case, der mehrere Schreibvorgänge umschließt, ist nur dann korrekt, wenn der Rollback-Pfad
vorherige Schreibvorgänge **tatsächlich rückgängig macht**. Ein Test, der nur den Erfolgsfall
abdeckt, besteht auch dann, wenn der Use Case vergisst, `$tx` zu verwenden — `$this->products`
läuft dann außerhalb der Transaktion und committet stillschweigend. Der Rollback-Test ist der,
der den Fehler findet.

### Rollback auf Unit-Ebene

Den Use Case direkt mit `DatabaseTestKit` ansteuern und den Datenbankzustand nach der Exception
prüfen:

```php
public function testRollbackUndoesStockDecrementWhenOrderInsertFails(): void
{
    $kit = DatabaseTestKit::sqlite($this->dbFile);
    $kit->queryExecutor->execute(/* Seed: Produkt 1 mit stock=10 */);

    $useCase = new CreateOrderUseCase($kit->transactionManager);

    try {
        // Eine Menge übergeben, die bei decrementStock erfolgreich ist, aber in orders.save
        // eine Unique-Constraint-Verletzung auslöst (z. B. doppelter Idempotenzschlüssel).
        $useCase->execute(productId: 1, qty: 3, idempotencyKey: $existingKey);
        self::fail('Expected order creation to fail.');
    } catch (DatabaseConstraintException) {
        // erwartet
    }

    // Das decrementStock aus Schritt 1 muss zurückgerollt worden sein.
    $row = $kit->queryExecutor->fetchOne('SELECT stock FROM products WHERE id = ?', [1]);
    self::assertSame(10, $row['stock']);
}
```

Dieser Test schlägt sofort fehl, wenn der Use Case `$this->products->decrementStock()` aufruft,
statt ein Repository aus `$tx` zu bauen — die Bestandsminderung überlebt dann den Rollback und
die Assertion fängt sie ab.

### Rollback auf HTTP-Ebene

Dieselbe Eigenschaft auf Integrationsebene:

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
