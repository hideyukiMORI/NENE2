# ハウツー: データベーストランザクションの使用方法

このガイドでは、NENE2 の `DatabaseTransactionManagerInterface` を使用してアトミックなマルチステップ操作を実行する方法を説明します。

**前提条件**: `DatabaseQueryExecutorInterface` にバックされたリポジトリがあること。
ない場合は [データベースバックエンドエンドポイントの追加](./add-database-endpoint.md) から始めてください。

---

## NENE2 でトランザクションを使う理由

`DatabaseTransactionManagerInterface` は複数の SQL 文を単一のトランザクションにラップします: すべて成功すればコミット、`Throwable` が発生するとすべてロールバックされます。

インターフェースには 1 つのメソッドがあります:

```php
public function transactional(callable $callback): mixed;
```

コールバックは開いているトランザクションにバインドされた**新鮮な** `DatabaseQueryExecutorInterface` を受け取ります。**このエグゼキューターはコンストラクション時に注入したものとは異なります。**

---

## トランザクショナルリポジトリパターン

> **警告 — コールバック内で注入済みリポジトリを再利用しないでください。**
>
> コンストラクション時に注入されたリポジトリは、トランザクションが実行する接続とは**異なる接続**を持っています。コールバック内でそれらを使用すると、クエリがトランザクションの外で実行されます: ロールバックはその変更を元に戻さず、コールバック内で書き込まれた未コミットの行がそれらから見えない可能性があります。
>
> このミスはコンパイルされ、テストが通過する場合があります — バグは並行書き込みやロールバック動作に依存するときにのみ現れます。

コールバックが独自のエグゼキューターを提供するため、コールバックが提供するエグゼキューターを使用して**コールバック内でリポジトリクラスをインスタンス化する必要があります**。

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
                // ここで具体的なクラスをインスタンス化する必要があります — $tx エグゼキューターは
                // このトランザクションの接続にバインドされています。注入済みインスタンスは異なるエグゼキューターを使用します。
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

### なぜ注入済みリポジトリを再利用しないのか?

`PdoDatabaseTransactionManager::transactional()` は `DatabaseConnectionFactoryInterface::create()` を通じて**新しい接続**を開き、その上でトランザクションを開始します。コールバックのエグゼキューターはその特定の接続にバインドされています。

注入された `SqliteProductRepository` は、最初の使用時に独自の接続を遅延オープンする別の `PdoDatabaseQueryExecutor` を持っています。注入されたリポジトリを通じたクエリはその別の接続で実行されます — トランザクション外で — つまりロールバックはそれらを元に戻さず、注入されたリポジトリを通じた挿入はコールバックからの未コミット行を見えない可能性があります。

---

## フロントコントローラーでの配線

2 つの別々のオブジェクトが必要です:

| オブジェクト | 目的 |
|---|---|
| `PdoDatabaseQueryExecutor` | 非トランザクションな読み取り（例: `GET /products`） |
| `PdoDatabaseTransactionManager` | マルチステップ書き込みをトランザクションにラップする |

両方が同じ `PdoConnectionFactory` を共有します:

```php
$connectionFactory = new PdoConnectionFactory($dbConfig);

$executor  = new PdoDatabaseQueryExecutor($connectionFactory);  // 読み取りリポジトリ用
$txManager = new PdoDatabaseTransactionManager($connectionFactory); // ユースケース用

$products = new SqliteProductRepository($executor);  // GET /products で使用
$createOrder = new CreateOrderUseCase($txManager);   // 内部で $tx を使用
```

---

## ファイルベースの SQLite データベースでのテスト

インメモリ SQLite（`sqlite::memory:`）は**接続ごとに別のデータベース**を作成するため、`PdoDatabaseTransactionManager`（`transactional()` 呼び出しごとに新しい接続を開く）は `PdoDatabaseQueryExecutor` が書き込んだ行を見えず、その逆も同様です。

代わりに**テンポラリファイル**を使用してください:

```php
protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo); // ファクトリーが独自の接続を開く前に初期化接続を閉じる

    $dbConfig = new DatabaseConfig(
        url:         null,
        environment: 'test',
        adapter:     'sqlite',
        host:        '',      // SQLite では未使用
        port:        1,       // SQLite では未使用
        name:        $this->dbFile,
        user:        '',      // SQLite では未使用
        password:    '',      // SQLite では未使用
        charset:     '',      // SQLite では未使用
    );

    $factory   = new PdoConnectionFactory($dbConfig);
    $executor  = new PdoDatabaseQueryExecutor($factory);
    $txManager = new PdoDatabaseTransactionManager($factory);
    // ... リポジトリとユースケースを配線する
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

各テストは新鮮なファイルを取得し、`PdoDatabaseQueryExecutor` と `PdoDatabaseTransactionManager` の両方が同じファイルに接続し、`tearDown` がそれを削除します。

> **SQLite `DatabaseConfig` フィールドに関する注意**: SQLite の場合、`adapter` と `name` のみが必要です。`host`、`user`、`password`、`charset` には空文字列を渡してください — `adapter` が `'sqlite'` の場合、これらは検証されません。

> **注意**: `PdoDatabaseQueryExecutor` はコンストラクター引数として生の `PDO` を受け入れません — `DatabaseConnectionFactoryInterface` が必要です。生の `PDO` セットアップをエグゼキューターにブリッジするには `PdoConnectionFactory`（上記参照）を使用してください。

---

## ロールバック動作の検証

トランザクション中に失敗が発生すると、それ以前のすべての変更が元に戻ることをテストしてください:

```php
public function testTransactionRollsBackOnDomainException(): void
{
    // 2 つの製品をシードする
    // ...

    // 2 番目の製品で失敗するオーダー（在庫不足）
    $response = $this->request('POST', '/orders', [
        'items' => [
            ['product_id' => $p1Id, 'qty' => 3],   // 成功するはず
            ['product_id' => $p2Id, 'qty' => 99],  // 失敗する
        ],
    ]);

    self::assertSame(409, $response->getStatusCode());

    // 製品 1 の在庫は変わらないはず — トランザクションがロールバックされた
    $products = $this->json($this->request('GET', '/products'))['items'];
    self::assertSame($originalStock1, $products[0]['stock']);
}
```

---

## 今後の方向性

現在のパターンでは、コールバック内で具体的なリポジトリクラスをインスタンス化する必要があります。つまり、ユースケースがリポジトリのインターフェースではなく実装（`SqliteProductRepository`）を知っています。これは既知の制限です。

`RepositoryFactory` 抽象 — ユースケースが受け入れ、指定されたエグゼキューターのリポジトリを生成できるインターフェース — によって、完全なインターフェースのみの依存関係が復元されます。これは将来の NENE2 バージョンでの検討事項として追跡されています。
