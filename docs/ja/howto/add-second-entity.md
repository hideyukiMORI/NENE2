# 2 つ目のドメインエンティティを追加する

このガイドでは、組み込みの `Note`・`Tag` 例に倣い、2 つ目のドメインエンティティを追加する方法を説明します。

**前提**: [DB 付きエンドポイントを追加する](./add-database-endpoint.md) を完了し、UseCase → Repository → Handler の三層パターンを理解していること。

---

## 全体像

2 つ目のエンティティも 1 つ目と同じ構造に従います。重要なのはアプリケーションへの接続方法です：

```
src/Example/YourEntity/
  YourEntity.php               ← readonly ドメインオブジェクト
  YourEntityRepositoryInterface.php
  PdoYourEntityRepository.php
  GetYourEntityByIdUseCase.php (+ interface)
  GetYourEntityByIdHandler.php
  YourEntityRouteRegistrar.php ← __invoke(Router) でルートを登録
  YourEntityServiceProvider.php ← DI + ルート登録を配線
```

`RuntimeServiceProvider` に新しいルート登録クラスと例外ハンドラーを追加するだけです — **`RuntimeApplicationFactory` は変更不要**です。

---

## ステップ: `Product` リソースの例

`GET /examples/products/{id}` と `GET /examples/products` を構築します。

### 1 — ドメインエンティティ

```php
// src/Example/Product/Product.php
final readonly class Product
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $price,
    ) {}
}
```

### 2 — リポジトリインターフェースと PDO アダプター

```php
interface ProductRepositoryInterface
{
    public function findById(int $id): Product;
    /** @return list<Product> */
    public function findAll(int $limit, int $offset): array;
}
```

### 3 — ユースケースとハンドラー

`GetNoteByIdUseCase` / `GetNoteByIdHandler` と同じパターン。

### 4 — ルート登録クラス

`RuntimeApplicationFactory` にパラメータを追加する代わりに、`__invoke` 可能なクラスを作成します：

```php
// src/Example/Product/ProductRouteRegistrar.php
final readonly class ProductRouteRegistrar
{
    public function __construct(
        private GetProductByIdHandler $getHandler,
        private ListProductsHandler $listHandler,
    ) {}

    public function __invoke(Router $router): void
    {
        $getHandler  = $this->getHandler;
        $listHandler = $this->listHandler;

        $router->get('/examples/products', static fn ($r) => $listHandler->handle($r));
        $router->get('/examples/products/{id}', static fn ($r) => $getHandler->handle($r));
    }
}
```

### 5 — サービスプロバイダー

```php
// src/Example/Product/ProductServiceProvider.php
final readonly class ProductServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(ProductRepositoryInterface::class, /* ... */)
            ->set(GetProductByIdHandler::class, /* ... */)
            ->set(ListProductsHandler::class, /* ... */)
            ->set(ProductNotFoundExceptionHandler::class, /* ... */)
            ->set('nene2.route_registrar.product', static function ($c): ProductRouteRegistrar {
                return new ProductRouteRegistrar($get, $list);
            });
    }
}
```

### 6 — RuntimeServiceProvider に配線する

`src/Http/RuntimeServiceProvider.php` に 2 箇所追加するだけです：

```php
// 1. プロバイダーを登録（Note・Tag と並べて）:
$builder->addProvider(new ProductServiceProvider());

// 2. registrar と例外ハンドラーを RuntimeApplicationFactory の配線に追加:
return new RuntimeApplicationFactory(
    $responseFactory, $streamFactory, $logger, $config->machineApiKey,
    [$noteNotFoundHandler, $tagNotFoundHandler, $productNotFoundHandler],  // ← 追加
    $requestIdHolder,
    [$noteRegistrar, $tagRegistrar, $productRegistrar],                    // ← 追加
    $bearerMiddleware,
);
```

`RuntimeApplicationFactory` 自体は変更不要です。

---

## 既存の参照例

| エンティティ | ソース | エンドポイント |
|---|---|---|
| `Note` | `src/Example/Note/` | GET/POST `/examples/notes`、GET/PUT/DELETE `/examples/notes/{id}` |
| `Tag` | `src/Example/Tag/` | GET/POST `/examples/tags`、GET/PUT/DELETE `/examples/tags/{id}` |

---

## テスト

```php
$registrar = new ProductRouteRegistrar($getHandler, $listHandler);

$application = (new RuntimeApplicationFactory(
    $factory, $factory,
    domainExceptionHandlers: [new ProductNotFoundExceptionHandler($problemDetails)],
    routeRegistrars: [$registrar],
))->create();
```

必要な registrar だけを渡します — 他のエンティティのルートはロードされません。

---

## MCP ツールの追加

エンドポイントが完成したら `docs/mcp/tools.json` にエントリを追加し、`composer mcp` で検証してください。write ツールは `NENE2_LOCAL_JWT_SECRET` の設定が必要です。
