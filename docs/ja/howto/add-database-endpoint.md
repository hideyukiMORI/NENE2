# DB 付きエンドポイントを追加する

このガイドでは、NENE2 のドメインレイヤーパターンに従って、データベースを読み書きするエンドポイントを追加する方法を説明します。

**前提条件**: ルートが登録された動作する NENE2 アプリケーションがあること。まだの場合は [カスタムルートを追加する](./add-custom-route.md) から始めてください。

---

## パターン

HTTP ハンドラーとデータベースの間には 3 層のパターンがあります:

```
HTTP Handler
  ↓ 呼び出し
UseCase          ← ビジネスロジック。HTTP も DB も知らない
  ↓ 呼び出し
RepositoryInterface ← データベース操作。インターフェースとして定義
  ↓ 実装
PdoRepository    ← 実際の SQL クエリ
```

FastAPI のサービス層や Node.js のリポジトリパターンと同じ分離です。HTTP ハンドラーは薄く保ち、ユースケースにロジックを置き、リポジトリが永続化を担当します。

---

## 例: `Product` リソース

`GET /products/{id}` を具体的な例として構築します。

### 1 — ドメインエンティティを定義する

`src/Product/Product.php` を作成します:

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

`readonly` はコンストラクターでプロパティを一度だけセットし、変更できないことを意味します。JavaScript のフリーズされたオブジェクトや Python の `frozen=True` の dataclass に相当します。

### 2 — リポジトリインターフェースを定義する

`src/Product/ProductRepositoryInterface.php` を作成します:

```php
<?php

declare(strict_types=1);

namespace MyApp\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
}
```

インターフェースは *何ができるか* を宣言し、*どうやるか* は宣言しません。これにより、テストで実際のデータベースをインメモリのフェイクに差し替えられます。

### 3 — ユースケースを定義する

`src/Product/GetProductByIdUseCase.php` を作成します:

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

ユースケースは HTTP も SQL も知りません。リポジトリを受け取り、それを呼び出すだけです。データベースなしでテストするのが簡単です。

### 4 — PDO でリポジトリを実装する

`src/Product/PdoProductRepository.php` を作成します:

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

すべての SQL はここに集まります。このクラスの外では、どのデータベースやクエリ構文が使われているかを知る必要はありません。

### 5 — フロントコントローラーで接続する

`public/index.php` で各部品を接続してルートを登録します:

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

// DB とユースケースを接続する。
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

// ... リクエスト処理（チュートリアルと同じ）
```

> **本番向け注記**: 大きなアプリケーションでは、ワイヤリングをサービスプロバイダーに移し、
> 生の PDO 接続文字列の代わりに型付き設定オブジェクトを注入してください。
> フルパターンは `src/DependencyInjection/` と `docs/development/domain-layer.md` を参照。

---

## データベースなしでユースケースをテストする

`GetProductByIdUseCase` は `PdoProductRepository` ではなく `ProductRepositoryInterface` に依存しているため、シンプルなインメモリフェイクでテストできます:

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

// テストで:
$repo    = new InMemoryProductRepository([1 => new Product(1, 'Widget', 999)]);
$useCase = new GetProductByIdUseCase($repo);
$result  = $useCase->execute(1);

assert($result->name === 'Widget');
```

Jest でサービスをモックしたり、pytest でテストダブルを使うのと同じパターンです。

---

## ディレクトリ構成

このパターンに従うと、プロジェクトは次のように成長します:

```
src/
  Product/
    Product.php                    ← ドメインエンティティ
    ProductRepositoryInterface.php ← できることの定義
    GetProductByIdUseCase.php      ← ビジネスロジック
    PdoProductRepository.php       ← SQL 実装
public/
  index.php                        ← ワイヤリング + ルート
```

各リソースには専用のディレクトリを作ります。ハンドラーは薄く、ユースケースは 1 つの操作に集中させます。

---

## 次のステップ

- エンドポイントの OpenAPI ドキュメントを追加する: `docs/development/endpoint-scaffold.md` を参照
- データベースマイグレーションを追加する: `docs/development/test-database-strategy.md` を参照
- NENE2 の組み込み Note サンプルを参照する: `src/Example/Note/`
