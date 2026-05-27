# ハウツー: ドメイン例外ハンドラーを追加する

ルートハンドラーがドメイン例外（例: `OrderNotFoundException`、`InsufficientStockException`）をスローすると、NENE2 の `ErrorHandlerMiddleware` はその例外型を処理できると宣言している最初に登録された `DomainExceptionHandlerInterface` に委譲します。これによりルートハンドラーが try/catch ブロックから解放され、エラーのシリアライズが一か所に集まります。

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
            request: $request,        // ← 必須: レスポンスの 'instance' を埋めるために使用
            type: 'not-found',        // ← スラッグのみ — ファクトリがベース URL を先頭に追加
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### よくある間違い

**`$request` の欠落** — `ProblemDetailsResponseFactory::create()` は最初の引数として PSR-7 リクエストを必要とします。省略すると実行時に `ArgumentCountError` が発生します。

**`type` にフル URL を指定** — `type` はスラッグ（例: `'not-found'`）を受け取ります。フル URI ではありません。ファクトリは `https://nene2.dev/problems/`（または設定済みベース URL）を自動的に先頭に追加します。フル URL を渡すと `https://nene2.dev/problems/https://nene2.dev/problems/not-found` のような二重パスが生成されます。

**正しいシグネチャ:**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // スラッグ
    title: 'Not Found',  // 人間が読めるタイトル
    status: 404,         // HTTP ステータスコード
    detail: '...',       // オプションの詳細文字列
);
```

## 3. RuntimeApplicationFactory でハンドラーを登録する

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // ハンドラーは順番に試される — 最初に一致したものが勝つ
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

`ErrorHandlerMiddleware` は例外をキャッチし、`$domainExceptionHandlers` リストを走査して、それぞれの `supports()` を呼び出し、最初に一致したものに委譲します。一致するハンドラーがない場合、例外は予期しないサーバーエラー（500）として扱われます。

## レスポンス形状

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

## トラブルシューティング: 期待するエラーコードの代わりに 500 が返される

ドメイン例外が期待する 4xx レスポンスではなく **500 Internal Server Error** を返す場合、最も一般的な原因はハンドラーの欠落または誤った登録です:

1. **ハンドラーが `domainExceptionHandlers` に追加されていない** — ハンドラークラスが `RuntimeApplicationFactory` に渡される配列に含まれているか再確認してください。
2. **`supports()` メソッドの不一致** — `supports()` が実際にスローされる例外クラスを正確にチェックしているか確認してください。スローされた例外がサブクラスで `supports()` が `instanceof ExactClass` を使用している場合、子クラスの例外も一致します。ただしクラス階層が逆（ハンドラーが親をチェック、例外が別のブランチ）の場合、一致するハンドラーがありません。
3. **ハンドラーは登録されているが順序が間違っている** — ハンドラーは順番に試されます。キャッチオールハンドラーが最初に現れてその `supports()` が広すぎる場合、後のハンドラーが処理すべき例外を飲み込んでしまう可能性があります。

クイック診断: `supports()` チェックの前に一時的に `error_log(get_class($exception))` を追加して実際の例外クラス名を出力します。
