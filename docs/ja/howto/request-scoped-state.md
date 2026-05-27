# ミドルウェアとハンドラー間でリクエストスコープの状態を渡す方法

一部のミドルウェアは受信リクエストから値を抽出します — テナント ID、デコードされた JWT クレーム、トレースコンテキスト — そしてルートハンドラーがその値を下流で必要とします。このガイドでは `RequestScopedHolder` を使った推奨パターンを解説します。

## ホルダーパターン

`RequestScopedHolder<T>` は小さなミュータブルコンテナです。それを書き込むミドルウェアとそれを読み取るハンドラー（またはリポジトリ）の両方に**1 つの共有インスタンス**を注入します:

```php
use Nene2\Http\RequestScopedHolder;

// 共有インスタンス — コンポジションルートで 1 回だけワイヤリングされる。
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// ミドルウェアが書き込む。
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// ルートハンドラーが読み取る。
$routeRegistrar = new TaskRouteRegistrar($repository, $teamId, $json);
```

ミドルウェア内:

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $raw = $request->getHeaderLine('X-Team-Id');
    // ... バリデーション ...
    $this->teamId->set((int) $raw);   // 書き込み
    return $handler->handle($request);
}
```

ルートハンドラー内:

```php
$id = $this->teamId->get();  // 読み取り — ミドルウェアが実行されていない場合は LogicException をスロー
```

## PSR-7 リクエスト属性ではない理由

PSR-7 リクエスト属性はイミュータブルです — 各 `withAttribute()` 呼び出しが新しいインスタンスを返します。ミドルウェアからハンドラーに属性を渡すには、NENE2 のディスパッチャーが既に行っているコールチェーン全体で新しいリクエストオブジェクトをスレッドする必要があります。下流のコードが `$request` を直接受け取る場合は `withAttribute()` を使っても問題ありません。

`RequestScopedHolder` は下流のコンシューマーが `$request` を受け取ら**ない**場合に適したツールです — 例えば、ドメイン型のみを知っており PSR-7 リクエストを依存関係として受け付けられないリポジトリなど。

## 複数のミドルウェアのスタック

`RuntimeApplicationFactory::$authMiddleware` にリストを渡して複数のミドルウェアを順番に実行します:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // 最初に実行
        new BearerTokenMiddleware($probs, $verifier), // 次に実行
    ],
))->create();
```

両方のミドルウェアは同じパイプライン位置（リクエストサイズ制限の後、レート制限の前）を共有します。リストの最初の項目が 2 番目の項目より前にリクエストを処理します。

## 安全性: PHP の shared-nothing モデル

PHP-FPM と CLI では、すべてのリクエストが新しいプロセスで実行されます。リクエスト A 中に設定された `RequestScopedHolder` の値はリクエスト B には決して見えません。各プロセスが 1 つのリクエストを処理した後に終了するためです。ホルダーはこのモデルではそのまま安全に使用できます。

### 非同期ランタイム（Swoole、ReactPHP、FrankenPHP ワーカーモード）

複数のリクエストが同じ PHP プロセスを共有する場合、リクエスト A 中に書き込まれたホルダーは明示的にクリアしない限りリクエスト B に値を保持します。各リクエストサイクルの開始（または終了）時に `reset()` を呼び出してください:

```php
// Swoole リクエストハンドラーの例
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // 前のリクエストの値をクリア
    $psrRequest = /* 変換 */;
    $psrResponse = $app->handle($psrRequest);
    // $psrResponse を送出 ...
});
```

NENE2 は現在 PHP-FPM / CLI を対象としており、組み込みの非同期サポートは提供していません。非同期ランタイムを実行する場合は、リクエスト間の共有ホルダーのリセットを自分で処理する責任があります。
