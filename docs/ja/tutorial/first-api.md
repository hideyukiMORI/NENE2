# 最初の API を 10 分で動かす

このチュートリアルでは、NENE2 を使って JSON API をゼロから動かすまでの手順を解説します。

最終的に次のことができるようになります:
- ローカルで HTTP リクエストに応答する API を起動する
- JSON を返す `/hello` エンドポイントを作成する
- リクエストがフレームワーク内をどう流れるかを理解する

**対象読者**: JavaScript や Python を知っているが PHP を使ったことのない開発者。Express や FastAPI を使ったことがあれば、概念はそのまま対応しています。

**所要時間**: 約 10 分。

---

## 必要なもの

| ツール | 用途 | 確認コマンド |
|---|---|---|
| PHP 8.4 | アプリを実行する | `php --version` |
| Composer | PHP パッケージマネージャー（npm 相当） | `composer --version` |
| ターミナル | すべてのコマンドはここで実行する | — |

> **Docker を使う場合**: PHP をローカルにインストールしたくない場合は Docker でも構いません。
> このページ下部の [Docker を使ったセットアップ](#docker-を使ったセットアップ) を参照してください。

---

## ステップ 1 — プロジェクトディレクトリを作成する

```bash
mkdir my-api && cd my-api
```

Node.js プロジェクトでの `mkdir my-app && cd my-app` と同じです。

---

## ステップ 2 — NENE2 をインストールする

```bash
composer init --name="yourname/my-api" --no-interaction
composer require hideyukimori/nene2:^0.4
```

`composer require` は `npm install` の PHP 版です。NENE2 とその依存パッケージを `vendor/` にダウンロードします。

実行後、ディレクトリ構成は次のようになります:

```
my-api/
  vendor/        ← インストール済みパッケージ（node_modules/ 相当）
  composer.json  ← パッケージメタデータ（package.json 相当）
  composer.lock  ← バージョン固定（package-lock.json 相当）
```

---

## ステップ 3 — `.env` ファイルを作成する

```bash
cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
APP_NAME="My API"
DB_ADAPTER=sqlite
EOF
```

`.env` は Node.js と同じように動作します。フレームワークが起動時に自動的に読み込みます。

---

## ステップ 4 — フロントコントローラーを作成する

`public/index.php` を作成します:

```php
<?php
declare(strict_types=1);

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/hello', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['message' => 'Hello, world!', 'status' => 'ok']);
            });
        },
    ],
))->create();

$request  = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response = $app->handle($request);

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
http_response_code($response->getStatusCode());
echo $response->getBody();
```

**各行の説明**:

- `require .../vendor/autoload.php` — インストール済みパッケージを読み込む（JS の `import` 相当）
- `$psr17 = new Psr17Factory()` — HTTP オブジェクトファクトリを生成する（リクエスト・レスポンスビルダー）
- `RuntimeApplicationFactory` — ミドルウェアパイプライン全体を組み立てる
- `routeRegistrars` — 独自のルートを追加する場所（詳しくは HOWTO ドキュメントを参照）
- `$router->get('/hello', ...)` — GET ルートを登録する（Express の `app.get('/hello', ...)` 相当）
- `$json->create([...])` — PHP 配列から JSON レスポンスを生成する

---

## ステップ 5 — サーバーを起動する

```bash
php -S localhost:8080 -t public
```

PHP 組み込みの開発サーバーです。`npm run dev` 相当です。本番環境向けではありませんが、ローカル開発には十分です。

次のような出力が表示されるはずです:

```
PHP 8.4.x Development Server (http://localhost:8080) started
```

---

## ステップ 6 — API を呼び出す

別のターミナルを開いて実行します:

```bash
curl http://localhost:8080/hello
```

次のレスポンスが返るはずです:

```json
{
    "message": "Hello, world!",
    "status": "ok"
}
```

組み込みのヘルスエンドポイントも試してみましょう:

```bash
curl http://localhost:8080/health
```

```json
{
    "status": "ok",
    "service": "My API"
}
```

最初の API が動いています。他に何が含まれているか見てみましょう。

---

## ステップ 7 — エラーハンドリングを確認する

NENE2 はすべてのエラーに対して [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457) を返します。存在しないルートを呼び出してみましょう:

```bash
curl http://localhost:8080/missing
```

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "instance": "/missing"
}
```

すべてのエラーレスポンスには `type` URI・`title`・HTTP `status` が含まれます。これが NENE2 全体で使われる標準フォーマットです。

---

## 何が起きていたか

`GET /hello` のリクエストフローは次の通りです:

```
HTTP リクエスト
  → RequestIdMiddleware      X-Request-Id ヘッダーを付与
  → SecurityHeadersMiddleware X-Content-Type-Options 等を付与
  → CorsMiddleware           CORS プリフライトを処理
  → ErrorHandlerMiddleware   未処理例外をキャッチ
  → RequestSizeLimitMiddleware 過大なペイロードを拒否
  → Router                   /hello → ハンドラーにマッチ
  → your handler             {"message": "Hello, world!"} を返す
HTTP レスポンス
```

これらはすべて自動的に行われます。ハンドラーはレスポンスを返すだけで、フレームワークがヘッダー・エラーフォーマット・リクエスト相関を処理します。

---

## 次のステップ

- **パスパラメーターを追加する**（`/hello/{name}` など）: [カスタムルートを追加する](../howto/add-custom-route.md) を参照
- **データベースに接続する**: [DB 付きエンドポイントを追加する](../howto/add-database-endpoint.md) を参照
- **完全な API ドキュメントを見る**: サーバーを起動して `http://localhost:8080/openapi.php` を開く

---

## Docker を使ったセットアップ

PHP をローカルにインストールせず Docker を使いたい場合:

```bash
mkdir my-api && cd my-api
```

最小限の `compose.yaml` を作成します:

```yaml
services:
  app:
    image: php:8.4-apache
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
    working_dir: /var/www/html
```

コンテナ内で Composer をインストールします:

```bash
docker compose run --rm app bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar require hideyukimori/nene2:^0.4"
```

上記のステップ 3〜4 に従って `.env` と `public/index.php` を作成してから:

```bash
docker compose up -d
curl http://localhost:8080/hello
```

MySQL サポートを含むより完全な Docker セットアップについては、[NENE2 リポジトリのセットアップガイド](../development/setup.md) を参照してください。
