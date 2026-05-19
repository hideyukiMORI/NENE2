# HTML ビューを追加する

このガイドでは、`NativePhpViewRenderer` と `HtmlResponseFactory` を使って NENE2 アプリケーションにサーバーレンダリングの HTML レスポンスを追加する方法を説明します。

**前提条件**: ルートが 1 つ以上ある動作する NENE2 アプリケーションがあること。まだの場合は [カスタムルートを追加する](./add-custom-route.md) から始めてください。

---

## 概要

NENE2 はゼロ依存のミニマルな HTML レンダリング層を提供します:

| クラス | 役割 |
|---|---|
| `NativePhpViewRenderer` | 独立したスコープで `.php` テンプレートをレンダリング |
| `HtmlEscaper` | 安全な HTML 出力のための値のエスケープ（`htmlspecialchars` / UTF-8 / フルクォート） |
| `HtmlResponseFactory` | レンダリングした HTML を `text/html; charset=utf-8` PSR-7 レスポンスにラップ |
| `TemplateNotFoundException` | テンプレートファイルが存在しない、またはパスが無効な場合にスロー |

HTML レスポンスは JSON エンドポイントと共存します。既存のルートを削除せずに同じアプリケーションに追加できます。

---

## 1. テンプレートを作成する

NENE2 は単一のルートディレクトリ以下にネイティブ PHP テンプレートファイルを配置することを期待します。標準的な場所はプロジェクトルートの `templates/` です。

```
my-app/
├── templates/
│   ├── layout.php       (オプションの共有レイアウト)
│   ├── home.php
│   └── notes/
│       ├── index.php
│       └── show.php
```

各テンプレートが受け取るもの:

- `$e` — エスケープヘルパー（`HtmlEscaper::escape()`）。ユーザー入力値には必ず使うこと。
- `render()` に渡した `$data` 配列の各キーが個別の変数として展開される。

**`templates/home.php`**

```php
<!doctype html>
<html lang="ja">
<head><meta charset="utf-8"><title><?= $e($title) ?></title></head>
<body>
  <h1><?= $e($title) ?></h1>
  <p><?= $e($description) ?></p>
</body>
</html>
```

**`templates/notes/index.php`**

```php
<!doctype html>
<html lang="ja">
<head><meta charset="utf-8"><title>ノート一覧</title></head>
<body>
  <h1>ノート</h1>
  <ul>
    <?php foreach ($notes as $note): ?>
      <li><a href="/notes/<?= $e($note['id']) ?>"><?= $e($note['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</body>
</html>
```

> **セキュリティ**: ユーザー入力・データベース・外部システム由来の値には必ず `$e(...)` を使うこと。省略すると XSS 脆弱性が生まれます。

---

## 2. ServiceProvider でレンダラーをワイヤリングする

アプリケーションの `ServiceProviderInterface` に `NativePhpViewRenderer` と `HtmlResponseFactory` を登録します:

```php
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class AppServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                NativePhpViewRenderer::class,
                static function (ContainerInterface $container): NativePhpViewRenderer {
                    return new NativePhpViewRenderer(dirname(__DIR__) . '/templates');
                },
            )
            ->set(
                HtmlResponseFactory::class,
                static function (ContainerInterface $container): HtmlResponseFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory   = $container->get(StreamFactoryInterface::class);
                    $renderer        = $container->get(NativePhpViewRenderer::class);

                    return new HtmlResponseFactory($responseFactory, $streamFactory, $renderer);
                },
            );
    }
}
```

---

## 3. ハンドラで HtmlResponseFactory を使う

`HtmlResponseFactory` をハンドラにインジェクトして `create()` を呼び出します:

```php
use Nene2\View\HtmlResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HomeHandler
{
    public function __construct(private HtmlResponseFactory $html) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('home.php', [
            'title'       => 'ようこそ',
            'description' => 'NENE2 製アプリケーションです。',
        ]);
    }
}
```

`create()` シグネチャ:

```php
public function create(
    string $template,           // templateRoot からの相対パス
    array  $data    = [],       // テンプレートに渡す変数
    int    $status  = 200,      // HTTP ステータスコード
    array  $headers = [],       // 追加レスポンスヘッダー
): ResponseInterface
```

---

## 4. ルートを登録する

```php
$router->get('/', new HomeHandler($container->get(HtmlResponseFactory::class)));
$router->get('/notes', new NoteListHandler($container->get(HtmlResponseFactory::class)));
```

---

## 5. TemplateNotFoundException を処理する

`NativePhpViewRenderer::render()` は以下の場合に `TemplateNotFoundException` をスローします:

- テンプレートファイルが存在しない
- テンプレートパスが空
- テンプレートパスに `..` が含まれる（ディレクトリトラバーサルはブロックされる）

ドメイン例外ハンドラーを登録して適切な HTTP レスポンスを返します:

```php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\View\TemplateNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TemplateNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(private ProblemDetailsResponseFactory $problemDetails) {}

    public function handles(\Throwable $exception): bool
    {
        return $exception instanceof TemplateNotFoundException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create($request, 'not-found', 'Not Found', 404);
    }
}
```

---

## 6. HTML と JSON エンドポイントを混在させる

```php
$registerRoutes = static function (Router $router) use ($container): void {
    // JSON API
    $router->get('/api/notes',     $container->get(NoteListJsonHandler::class));
    $router->post('/api/notes',    $container->get(NoteCreateHandler::class));

    // HTML ビュー
    $router->get('/',              $container->get(HomeHandler::class));
    $router->get('/notes',         $container->get(NoteListHtmlHandler::class));
    $router->get('/notes/{id}',    $container->get(NoteShowHtmlHandler::class));
};
```

---

## 7. HTML ハンドラをテストする

```php
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

public function testHomeReturnsHtml(): void
{
    $templateRoot = sys_get_temp_dir() . '/test-templates-' . bin2hex(random_bytes(4));
    mkdir($templateRoot);
    file_put_contents($templateRoot . '/home.php', '<h1><?= $e($title) ?></h1>');

    $psr17   = new Psr17Factory();
    $factory = new HtmlResponseFactory($psr17, $psr17, new NativePhpViewRenderer($templateRoot));
    $handler = new HomeHandler($factory);

    $response = $handler(new ServerRequest('GET', '/'));

    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    self::assertStringContainsString('<h1>ようこそ</h1>', (string) $response->getBody());

    unlink($templateRoot . '/home.php');
    rmdir($templateRoot);
}
```

---

## 設計上の注意

- テンプレートはクロージャスコープ内で実行されるため、`$this` やクラスの内部にはアクセスできません。変数は `extract()` + `EXTR_SKIP` で展開されます（既存の変数は上書きされません）。
- `HtmlEscaper` は `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5` を使用します。`"` と `'` の両方がエスケープされ、無効な UTF-8 シーケンスはドロップではなく置換されます。
- ディレクトリトラバーサル（`../`）はパス解決ステップでブロックされます。チェック前にファイルシステムアクセスは発生しません。

`Nene2\View\*` の安定 API 保証については [ADR 0009](../adr/0009-v1.0-public-api-scope.md) を参照してください。

---

## 次のステップ

- [カスタムルートを追加する](./add-custom-route.md)
- [レートリミットを追加する](./add-rate-limiting.md)
- [JWT 認証を追加する](./add-jwt-authentication.md)
