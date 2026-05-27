# ドメイン例外ハンドラーを追加する方法

ルートハンドラーがドメイン例外（例: `OrderNotFoundException`、`InsufficientStockException`）をスローした場合、NENE2 の `ErrorHandlerMiddleware` は、その例外を処理できると宣言した最初の `DomainExceptionHandlerInterface` に委譲します。これにより、ルートハンドラーに try/catch ブロックを書かずに済み、エラーのシリアライズを一箇所にまとめられます。

## 1. ドメイン例外を定義する

```php
// src/Order/OrderNotFoundException.php
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Order #{$id} not found.");
    }
}
```

## 2. DomainExceptionHandlerInterface を実装する

```php
// src/Order/OrderNotFoundExceptionHandler.php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class OrderNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $probs,
    ) {}

    public function supports(Throwable $exception): bool
    {
        return $exception instanceof OrderNotFoundException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->probs->create(
            request: $request,        // ← 必須: レスポンスの 'instance' を設定するために使用
            type: 'not-found',        // ← スラッグのみ — ファクトリーがベース URL を先頭に付加する
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### よくある間違い

**`$request` の欠落** — `ProblemDetailsResponseFactory::create()` には PSR-7 リクエストが最初の引数として必要です。省略すると実行時に `ArgumentCountError` が発生します。

**`type` に完全な URL を使用** — `type` にはスラッグ（例: `'not-found'`）を指定し、完全な URI は不要です。ファクトリーが自動的に `https://nene2.dev/problems/`（または設定されたベース URL）を先頭に付加します。完全な URL を渡すと `https://nene2.dev/problems/https://nene2.dev/problems/not-found` のように二重のパスが生成されます。

**正しいシグネチャ:**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // スラッグ
    title: 'Not Found',  // 人が読めるタイトル
    status: 404,         // HTTP ステータスコード
    detail: '...',       // オプションの詳細文字列
);
```

## 3. RuntimeApplicationFactory にハンドラーを登録する

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // ハンドラーは順番に試行される — 最初にマッチしたものが勝つ
    ],
))->create();
```

## 4. ルートハンドラーからスローする

```php
$router->get('/orders/{id}', static function (ServerRequestInterface $request) use ($orders): ResponseInterface {
    /** @var array<string, string> $params */
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    $order = $orders->findById($id) ?? throw new OrderNotFoundException($id);

    return $json->create(['id' => $order->id]);
});
```

`ErrorHandlerMiddleware` が例外をキャッチし、`$domainExceptionHandlers` リストを走査し、各ハンドラーの `supports()` を呼び出し、最初にマッチしたハンドラーに委譲します。マッチするハンドラーがない場合、例外は予期しないサーバーエラー（500）として扱われます。

## レスポンス形式

上記の例で生成される 404 レスポンス:

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "detail": "Order #42 not found.",
    "instance": "/orders/42"
}
```

`instance` は `$request->getUri()->getPath()` から自動的に設定されます。

## トラブルシューティング: 期待したエラーコードではなく 500 が返される

ドメイン例外が期待した 4xx レスポンスではなく **500 Internal Server Error** を生成する場合、最も一般的な原因はハンドラーの欠落または誤った登録です:

1. **`domainExceptionHandlers` にハンドラーが追加されていない** — `RuntimeApplicationFactory` に渡す配列にハンドラークラスが含まれているか再確認してください。
2. **`supports()` メソッドの不一致** — `supports()` が実際にスローされる例外クラスを正しくチェックしているか確認してください。スローされた例外がサブクラスで `supports()` が `instanceof ExactClass` を使用している場合、子クラスの例外もマッチします。ただし、クラス階層が逆（ハンドラーが親をチェックし、例外が別のブランチ）の場合、ハンドラーはマッチしません。
3. **ハンドラーは登録されているが順序が誤っている** — ハンドラーは順番に試行されます。キャッチオールハンドラーが最初に現れ、その `supports()` が広すぎる場合、後のハンドラーが処理すべき例外を飲み込む可能性があります。

簡易診断: `supports()` チェックの前に一時的に `error_log(get_class($exception))` を追加して実際の例外クラス名を出力してください。
