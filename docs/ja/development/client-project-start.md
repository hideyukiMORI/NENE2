# クライアントプロジェクト開始ガイド

このガイドでは、NENE2 を小規模なクライアントスタイルの API プロジェクトに適応する方法を説明します。

意図的に実践的で手動です。目的は、ジェネレーターや広範なフレームワーク便利レイヤーを追加する前に、最初のプロジェクト引き継ぎを信頼できるものにすることです。

## 開始点

このガイドは以下が必要なプロジェクトに使用します:

- 動作するローカル JSON API
- 早期に共有できる OpenAPI ドキュメント
- テスト済みの小さなエンドポイントセット
- オプションの React スターター統合
- 文書化された API 境界を通じた安全なローカル MCP 検査
- 基本的なマシンクライアント認証
- Docker ベースのデータベース検証パス

NENE2 はまだ `0.x` の基盤です。公開契約は有用ですが、まだ形成中として扱ってください。

## 公開フィールドトライアル参照サンドボックス（オプション）

最初のローカルマイルストーン後、文書化されたスキャフォールドパスに沿った**完成した公開デモ**を検査するのに役立つ場合があります:

- リポジトリ: [`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial)（NENE2 **`v0.1.1`** ベース）。
- 内容: read-only の展覧会シェイプ JSON API・OpenAPI・PHPUnit・ローカル MCP ツール・Markdown フィールドトライアルノート。

これは**公式プロダクトリポジトリではなく**、実際の展覧会の**推薦を意味しません**。**架空のサンドボックスデータ** — 名前や年を事実として扱う前に、そのプロジェクトの `README.md` と `SECURITY.md` を読んでください。

## `composer require` から始める

NENE2 リポジトリをフォークするのではなく、新しいプロジェクトをゼロから始める場合は、NENE2 を Composer 依存関係としてインストールします:

```bash
mkdir my-project && cd my-project
composer init --name="vendor/my-project" --no-interaction
composer require hideyukimori/nene2:^0.3
```

次に最小限のファイルを手動で作成します:

**`.env`**
```dotenv
APP_ENV=local
APP_DEBUG=true
APP_NAME="My Project"
DB_ADAPTER=sqlite
```

**`public/index.php`** — 組み込みコンテナーを使用するフロントコントローラー:
```php
<?php
declare(strict_types=1);

use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeContainerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = (new RuntimeContainerFactory(dirname(__DIR__)))->create();
$psr17     = $container->get(Psr17Factory::class);
$request   = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response  = $container->get(RequestHandlerInterface::class)->handle($request);
$container->get(ResponseEmitter::class)->emit($response);
```

> **注意:** `RuntimeContainerFactory` は Note CRUD サンプルルート（`/examples/notes/*`）を含む完全な NENE2 ランタイムをワイヤリングします。これらは無害ですが表示されます。独自のルートのみを使用するには、スタックを手動でワイヤリングしてください（以下を参照）。

PHP 組み込みサーバーでローカルに提供:
```bash
php -S localhost:8080 -t public
```

### カスタムルートの追加

`RuntimeApplicationFactory` で受け付けるオプションの `list<callable(Router): void>` である `$routeRegistrars` でカスタムルートを渡します:

```php
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
                // パスパラメーターは個別属性としてではなく Router::PARAMETERS_ATTRIBUTE の下に格納されます。
                $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
                return $json->create(['id' => (int) ($params['id'] ?? 0)]);
            });
        },
    ],
))->create();
```

ルートレジストラーは組み込みフレームワークルートが登録された後に実行されます。パスパラメーターアクセスには、常に `Router::PARAMETERS_ATTRIBUTE` から読み取ってください。詳細は `docs/development/endpoint-scaffold.md` を参照してください。

## 最初のローカルセットアップ

クリーンなチェックアウトから始めます:

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

ローカル API とドキュメントを確認します:

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
```

有用なブラウザ URL:

- OpenAPI: `http://localhost:8080/openapi.php`
- Swagger UI: `http://localhost:8080/docs/`

## プロジェクト境界の名前変更

アプリケーション動作を追加する前に、プロジェクト固有のものとフレームワーク基盤として残すものを決定します。

まずプロジェクト向けのメタデータを更新します:

- `README.md` のプロジェクト説明
- プロジェクトが NENE2 フレームワークパッケージのままでない場合の `composer.json` のパッケージ名と説明
- OpenAPI の `info.title`・`info.description`・`info.version`
- NENE2 自体を記述するデフォルトの例

アプリケーションが真に所有しない限り、これらは変更しないでください:

- `src/` の低レベルフレームワーク名前空間
- Problem Details の形
- request id の動作
- エンドポイントスキャフォールドワークフロー
- テストと静的解析コマンド

## 最初のアプリケーションエンドポイントの追加

出荷するすべての JSON エンドポイントに `docs/development/endpoint-scaffold.md` を使用します。

最初のクライアントエンドポイントについて:

1. フォーカスされた GitHub Issue を作成または再利用する。
2. 最も小さく明確なランタイム境界でルートを追加する。
3. OpenAPI パス・`operationId`・スキーマ・例を追加する。
4. エンドポイント動作に近いランタイムテストを追加する。
5. `tests/OpenApi/RuntimeContractTest.php` で文書化された成功例を検証させる。
6. Docker を通じてローカル HTTP スモークチェックを実行する。

早期のエンドポイントを薄く保つ。ルートが単純なランタイムデモ以上に成長したら、ユースケースや小さなサービスに動作を移す。UseCase → RepositoryInterface → PDO アダプター規約とコンテナーワイヤリングパターンについては `docs/development/domain-layer.md` を参照してください。

## 引き継ぎ契約として OpenAPI を保つ

OpenAPI はエンドポイント動作と同じ PR で更新されるべきです。

各エンドポイントに含める:

- 安定した `operationId`
- 簡潔な summary と description
- 成功レスポンススキーマと例
- 関連する Problem Details レスポンス
- 一致するミドルウェアが存在する場合のみのセキュリティ要件

引き継ぎ前に実行します:

```bash
docker compose run --rm app composer openapi
docker compose run --rm app composer check
```

## API 境界のみを通じた MCP の追加

まずパブリックまたは read-only API 動作から始めます。エンドポイントが OpenAPI に存在した後のみ MCP メタデータを追加します。

エンドポイントがローカル MCP ツールになる場合:

1. OpenAPI オペレーションを追加または確認する。
2. `docs/mcp/tools.json` に read-only エントリを追加する。
3. `docker compose run --rm app composer mcp` を実行する。
4. ローカル MCP サーバーをローカル API のみに対してスモークテストする。

ローカル MCP ガイダンスは `docs/integrations/local-mcp-server.md` にあります。

直接データベース・ファイルシステム・本番のみの動作を MCP ツールを通じて公開しないでください。

## マシンクライアントパスの保護

マシンクライアントパスのみに既存の `X-NENE2-API-Key` 方向を使用します。

ローカルテスト用:

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

実際の API キー・生成されたシークレット・ローカルの `.env` ファイルをコミットしないでください。

認証ポリシーは `docs/development/authentication-boundary.md` にあります。
ローカル保護ルートスモークワークフローは `docs/development/machine-client-smoke.md` にあります。

## データベース動作の検証

デフォルトのデータベースアダプターテストは SQLite を使用し、標準チェックパスで実行されます。

サービスデータベースに対して動作を確認する必要がある場合のみ MySQL 検証を使用します:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

データベース戦略は `docs/development/test-database-strategy.md` にあります。

## 引き継ぎチェックリスト

クライアントスタイルのプロジェクトを引き渡す前に確認します:

- `README.md` がスターターだけでなくプロジェクトを説明している。
- `docs/openapi/openapi.yaml` が出荷された JSON 動作と一致している。
- Swagger UI がローカルで読み込まれる。
- 新しいエンドポイントにランタイムテストと OpenAPI 例がある。
- 保護されたルートがシークレット値を公開せずに必要な認証情報を文書化している。
- MCP ツールがある場合、文書化された API 境界のみを呼び出している。
- `docker compose run --rm app composer check` が通る。
- React スターターが引き継ぎの一部の場合、フロントエンドチェックが通る。
- 延期された作業が `docs/todo/current.md` またはプロジェクト固有のトラッカーに表示されている。

## 次のドキュメント

- ドメインレイヤーポリシー: `docs/development/domain-layer.md`
- エンドポイントスキャフォールドワークフロー: `docs/development/endpoint-scaffold.md`
- ローカル MCP サーバーガイダンス: `docs/integrations/local-mcp-server.md`
- ローカル MCP クライアント設定: `docs/integrations/local-mcp-client-configuration.md`
- MCP ツールポリシー: `docs/integrations/mcp-tools.md`
- 認証境界: `docs/development/authentication-boundary.md`
- マシンクライアントスモークワークフロー: `docs/development/machine-client-smoke.md`
- データベーステスト戦略: `docs/development/test-database-strategy.md`
- リリースポリシー: `docs/development/release-v0.1.x-policy.md`
- 現在のマイルストーン: `docs/milestones/2026-05-client-delivery-hardening.md`
- GitHub Issue: `#150`
