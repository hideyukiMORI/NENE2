# エンドポイントスキャフォールドワークフロー

このワークフローは、新しい NENE2 JSON エンドポイントをランタイムコード・OpenAPI・テスト・オプションの MCP メタデータ全体で整合させます。

現時点では意図的に手動です。コード生成を追加する前に手順を明確にすることが目的です。

## ポジション

エンドポイントは、関連するすべての場所でその動作が可視化されて初めて完成です:

- ランタイムルートとハンドラー
- OpenAPI パス・レスポンススキーマ・例
- ランタイムまたはハンドラーのテスト
- `docs/openapi/openapi.yaml` を通じたコントラクトテスト
- エンドポイントがツールになる場合の MCP カタログ更新
- エンドポイントがプロジェクトポリシーやワークフローを変更する場合のドキュメント更新

## 標準手順

1. フォーカスされた GitHub Issue を作成または再利用する。
2. 最も小さい適切なハンドラー境界でランタイムルートを追加または更新する。
3. `operationId`・例・成功スキーマ・Problem Details レスポンスを含む OpenAPI パスを追加する。
4. 動作に近いテストを追加または更新する。
5. フォーカスされたテストを最初に実行し、次に `docker compose run --rm app composer check` を実行する。
6. エンドポイントが Docker 経由で到達可能な場合は、ローカル HTTP スモークチェックを実行する。
7. エンドポイントが現在の作業に影響する場合にのみ、`docs/todo/current.md`・マイルストーンドキュメント・MCP カタログを更新する。

## ランタイムルート

現在の小さなランタイムでは、サンプルルートは `RuntimeApplicationFactory` に記述されています。

スキャフォールドの例:

```text
GET /examples/ping
```

返すレスポンス:

```json
{
  "message": "pong",
  "status": "ok"
}
```

このエンドポイントはワークフローを練習するために存在します。動作が増加するにつれて、アプリケーションエンドポイントはユースケースに委譲する薄いハンドラーへと移行するべきです。UseCase → Repository → Handler パターンについては `docs/development/domain-layer.md` を参照してください。

### パスパラメーター

ルーターはマッチしたパスパラメーターを `Router::PARAMETERS_ATTRIBUTE` の名前付き配列として格納します — 個別の PSR-7 リクエスト属性としては設定されません。

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $request) use ($json): ResponseInterface {
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id = (int) ($params['id'] ?? 0);

    // $id は URL セグメントからマッチした値
    return $json->create(['id' => $id]);
});
```

`$request->getAttribute('id')` と書くと `null` が返されます。常に `Router::PARAMETERS_ATTRIBUTE` から読み取ってください。

完全な使用例は `src/Example/Note/GetNoteByIdHandler.php` を参照してください。

### ユースケースを導入するタイミング

ハンドラーにビジネスロジックが含まれる場合にユースケースを追加します:

- 所有者やアカウントによるフィルタリング
- 一意性や前提条件のチェック
- 状態遷移ルールの適用
- 複数のリポジトリの協調

スモークエンドポイントとヘルスエンドポイントはユースケースなしのシンプルなハンドラーのままにします。

## OpenAPI 要件

出荷された各 JSON エンドポイントには以下を含める必要があります:

- 安定した `operationId`
- 短い summary と安全な description
- `200` レスポンススキーマと `ok` の例
- `401`・`413`・`500` などの適切な Problem Details レスポンス
- 対応するミドルウェアが存在する場合のみのセキュリティ要件

ランタイムコントラクトテストは `docs/openapi/openapi.yaml` を自動的に読み取り、JSON エンドポイントの文書化された `200` の例を検証します。

## テスト要件

まず最も狭い有用なチェックを使用します:

```bash
docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
```

次に完全なバックエンドチェックを実行します:

```bash
docker compose run --rm app composer check
```

Docker 経由で提供されるエンドポイントには、ローカルスモークチェックを実行します:

```bash
curl -i http://localhost:8080/examples/ping
```

## MCP との関係

新しいエンドポイントが MCP ツールになる場合:

1. まず OpenAPI オペレーションを追加する。
2. ツールがパブリック API 境界を安全に呼び出せる場合にのみ、`docs/mcp/tools.json` に read-only エントリを追加する。
3. `docker compose run --rm app composer mcp` を実行する。
4. 認証・認可・監査動作が文書化・実装されるまで、mutation・admin・destructive ツールはスコープ外のままにする。

## 非目標

- 手動ワークフローが有用であることが証明される前にエンドポイントファイルを自動生成する。
- マジックなコントローラー検出でルートの動作を隠す。
- デフォルトですべてのエンドポイントの MCP カタログを更新する。
- ルートとスキーマのパターンが安定する前にランタイム OpenAPI バリデーションを要求する。

## 関連ドキュメント

- ランタイム: `src/Http/RuntimeApplicationFactory.php`
- OpenAPI: `docs/openapi/openapi.yaml`
- ランタイムコントラクトテスト: `tests/OpenApi/RuntimeContractTest.php`
- リクエストバリデーションポリシー: `docs/development/request-validation.md`
- ドメインレイヤーポリシー: `docs/development/domain-layer.md`
- MCP ツールポリシー: `docs/integrations/mcp-tools.md`
