# ハウツー: データベーストランザクションの使用方法

このガイドでは、NENE2 の `DatabaseTransactionManagerInterface` を使用してアトミックなマルチステップ操作を実行する方法を説明します。

**前提条件**: `DatabaseQueryExecutorInterface` にバックされたリポジトリがあること。
ない場合は [データベースバックエンドエンドポイントの追加](./add-database-endpoint.md) から始めてください。

> **🚫 SQLite の `:memory:` は `transactional()` と併用できません。**
>
> `PdoDatabaseTransactionManager` は呼び出しごとに**新しい**接続を開きます。`:memory:` の接続は
> それぞれ**別の空のインメモリデータベース**を指すため、エグゼキューターとトランザクションが
> 別々のデータを見ることになり、ロールバックがエグゼキューター側の見え方に何の影響も与えません。
> 症状は静かです — コールバックの途中でクエリが `null` を返す、あるいは別のエグゼキューター経由で
> テストが書き込んだ内容をロールバックが取り消せない、といった形で現れます。
>
> テストでは**ファイルベースの SQLite** を使ってください（下の
> 「[ファイルベースの SQLite データベースでのテスト](#ファイルベースの-sqlite-データベースでのテスト)」参照）。
> `Nene2\Testing\DatabaseTestKit::sqlite(':memory:')` は `:memory:` を
> `InvalidArgumentException` で弾き、この問題を fail-fast にします。

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

代わりに**テンポラリファイル**を使用してください。`Nene2\Testing\DatabaseTestKit` は
エグゼキューターとトランザクションマネージャーを同じファイルに 1 行で配線します:

```php
use Nene2\Testing\DatabaseTestKit;

protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/' . uniqid('test-', true) . '.sqlite';

    // 使い捨て接続でスキーマを投入し、kit が独自の接続を開く前に閉じる。
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo);

    $this->kit = DatabaseTestKit::sqlite($this->dbFile);
    // $this->kit->queryExecutor       — 読み取りリポジトリ用
    // $this->kit->transactionManager  — ユースケース用
    // $this->kit->connectionFactory   — 追加のエグゼキューターを組む必要がある場合
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

この kit は `Nene2\Testing\DatabaseTestKit`（ADR 0012・公開 API）にあります。内部で
`PdoConnectionFactory` + `PdoDatabaseQueryExecutor` + `PdoDatabaseTransactionManager` を
同じファイルを共有する形で配線するため、テストが `@internal` なクラスを名指しする必要がありません。
`DatabaseTestKit::sqlite(':memory:')` と、その裏にある設定の組み合わせは、いずれもファクトリーの
段階で弾かれます。

> **`DatabaseConfig::sqlite(string $path)`** は、配線を明示的に保ちたい場合（例えば
> `PdoConnectionFactory` の独自サブクラスを注入したい場合）の同等のショートカットです。
> 古いガイドに出てくる 9 引数の `new DatabaseConfig(...)` 形式を置き換えます。

---

## ロールバック動作の検証

複数の書き込みをまとめるユースケースは、ロールバック経路が**実際に**それ以前の書き込みを
取り消して初めて正しいと言えます。成功パスしか通らないテストは、ユースケースが `$tx` を
使い忘れていても通ってしまいます — `$this->products` はトランザクションの外で実行され、
黙ってコミットされるからです。バグを捕まえるのはロールバックのテストです。

### ユニットレベルのロールバック

`DatabaseTestKit` でユースケースを直接駆動し、例外の後にデータベースの状態を検証します:

```php
public function testRollbackUndoesStockDecrementWhenOrderInsertFails(): void
{
    $kit = DatabaseTestKit::sqlite($this->dbFile);
    $kit->queryExecutor->execute(/* シード: 在庫 10 の製品 1 */);

    $useCase = new CreateOrderUseCase($kit->transactionManager);

    try {
        // decrementStock は成功するが orders.save で一意制約違反になる数量を渡す
        // （例: 冪等性キーの重複）。
        $useCase->execute(productId: 1, qty: 3, idempotencyKey: $existingKey);
        self::fail('Expected order creation to fail.');
    } catch (DatabaseConstraintException) {
        // 想定どおり
    }

    // ステップ 1 の decrementStock はロールバックされていなければならない。
    $row = $kit->queryExecutor->fetchOne('SELECT stock FROM products WHERE id = ?', [1]);
    self::assertSame(10, $row['stock']);
}
```

ユースケースが `$tx` からリポジトリを組み立てず `$this->products->decrementStock()` を
呼んでいると、このテストは即座に落ちます — 在庫の減算がロールバックをすり抜け、
アサーションがそれを捕まえます。

### HTTP レベルのロールバック

同じ性質を統合レベルで確認します:

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
