# カスタムルートを追加する

このガイドでは、NENE2 アプリケーションにパスパラメーター付きの GET・POST ルートを追加する方法を説明します。

**前提条件**: 動作する NENE2 アプリケーションがあること。まだの場合は [チュートリアル](../tutorial/first-api.md) から始めてください。

---

## シンプルな GET ルートを追加する

ルートは `routeRegistrars` で登録します。各関数がルーターを受け取り、ルートを登録します。

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
            $router->get('/items', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['items' => [], 'count' => 0]);
            });
        },
    ],
))->create();
```

Express では `app.get('/items', (req, res) => res.json(...))` になります。パターンは同じです — ルート、ハンドラー、レスポンス。

---

## パスパラメーターを追加する

ルートパスに `{name}` 構文を使います。ハンドラー内では `Router::PARAMETERS_ATTRIBUTE` リクエスト属性からすべてのパスパラメーターを取得します。個別の属性ではなく名前付き配列として格納されています。

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
    // パスパラメーターは単一の配列属性に入っています — 個別の属性ではありません。
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

> **よくある間違い**: `$req->getAttribute('id')` は常に `null` を返します。
> 必ず `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id']` を使ってください。

Express では `req.params.id`、FastAPI では型付き関数引数です。NENE2 では明示的な配列読み取りです — より冗長ですが、クエリ文字列パラメーターと混同することはありません。

### 複数のパラメーター

```php
$router->get('/users/{userId}/posts/{postId}', static function (ServerRequestInterface $req) use ($json) {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $userId = (int) ($params['userId'] ?? 0);
    $postId = (int) ($params['postId'] ?? 0);

    return $json->create(['userId' => $userId, 'postId' => $postId]);
});
```

---

## クエリ文字列パラメーターを追加する

クエリ文字列パラメーターはルートパターンではなく、パース済みのクエリ配列から読み取ります。

```php
$router->get('/items', static function (ServerRequestInterface $req) use ($json) {
    $query  = $req->getQueryParams();          // ['limit' => '20', 'offset' => '0']
    $limit  = (int) ($query['limit']  ?? 20);
    $offset = (int) ($query['offset'] ?? 0);

    return $json->create(['limit' => $limit, 'offset' => $offset]);
});
```

Express の `req.query.limit`、FastAPI の `request.query_params['limit']` に相当します。

---

## POST ルートを追加する

```php
$router->post('/items', static function (ServerRequestInterface $req) use ($json, $psr17) {
    $body  = json_decode((string) $req->getBody(), true) ?? [];
    $name  = (string) ($body['name'] ?? '');

    if ($name === '') {
        // 422 Validation Failed を返す — 完全なバリデーションパターンは
        // docs/development/endpoint-scaffold.md を参照。
        return $json->create(['error' => 'name is required'], 422);
    }

    // 実際のエンドポイントではここでデータベースに保存します。
    return $json->create(['name' => $name], 201);
});
```

> 本番エンドポイントでは、インラインバリデーションの代わりに
> `ValidationException` とドメインレイヤーパターンを使ってください。
> [DB 付きエンドポイントを追加する](./add-database-endpoint.md) を参照。

---

## 1 つの registrar に複数のルートを登録する

1 つの registrar 関数内に好きなだけルートを登録できます:

```php
routeRegistrars: [
    static function (Router $router) use ($json): void {
        $router->get('/items',         /* handler */);
        $router->get('/items/{id}',    /* handler */);
        $router->post('/items',        /* handler */);
        $router->put('/items/{id}',    /* handler */);
        $router->delete('/items/{id}', /* handler */);
    },
],
```

ルートリストが長くなったら、複数の registrar 関数に分割してください。

> **ルート登録順**: ルーターは**登録順**にマッチします。同じプレフィックスを共有する場合は
> **必ず静的ルートをパラメーター付きルートより先に登録してください**。`GET /items/{id}` を先に、
> `GET /items/summary` を後に登録すると、`/items/summary` へのリクエストは `id = "summary"` として
> `{id}` ルートにマッチします — ルーティングエラーではなく、分かりにくいドメイン固有の 404 が返ります。
>
> ```php
> // 誤り — /items/summary が id="summary" として {id} にマッチする
> $router->get('/items/{id}',    $this->show(...));
> $router->get('/items/summary', $this->summary(...)); // 到達しない
>
> // 正しい — 静的セグメントを先にマッチさせる
> $router->get('/items/summary', $this->summary(...));
> $router->get('/items/{id}',    $this->show(...));
> ```

---

## アクションエンドポイント（CRUD 以外の操作）

アーカイブ・公開・承認・復元など、標準的な CRUD の形に収まらない操作があります。
これらには `POST /resource/{id}/action` を使います。レスポンスは更新後のリソースを本文に持つ `200 OK` です。

```php
$router->post('/items/{id}/archive', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});

$router->post('/items/{id}/restore', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});
```

> **登録順について**: アクションルート（`/items/{id}/archive`）は show/update ルートと `{id}`
> セグメントを共有しますが、`{id}` の後に静的セグメントが追加されているため一意に定まります。
> したがって登録順によらず正しく動作します。

### 任意のボディを取るアクションエンドポイント

アクションによっては任意の JSON ボディを受け取ります（例: 任意の `reason` を取る `reject`）。
`JsonRequestBodyParser::parse()` はボディが空だと 400 を投げるため、パーサーを呼ぶ前に
空ボディかどうかを確認してください:

```php
$router->post('/items/{id}/reject', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $raw    = (string) $req->getBody();
    $reason = null;

    if ($raw !== '') {
        $body   = JsonRequestBodyParser::parse($req);
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null;
    }

    $item = $repo->reject($id, $reason, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item));
});
```

---

## 使用可能な HTTP メソッド

| メソッド | Router メソッド | 典型的な用途 |
|---|---|---|
| GET | `$router->get()` | リソースを読み取る |
| POST | `$router->post()` | リソースを作成する、またはアクションを実行する |
| PUT | `$router->put()` | リソースを置き換える（完全更新） |
| PATCH | `$router->patch()` | 部分更新 |
| DELETE | `$router->delete()` | リソースを削除する |

---

## フレームワークの予約パス

以下のパスは、あなたの route registrar が動く**前**に `RuntimeApplicationFactory` が登録します。
フレームワークのルートが先に評価されるため、これらのパスにユーザーが登録したルートは決してマッチしません。

| パス | メソッド | 説明 |
|---|---|---|
| `/` | GET | フレームワークのスモークエンドポイント（name / description / status） |
| `/health` | GET | ヘルスチェック |
| `/machine/health` | GET | マシンクライアント向けヘルスチェック（API キー必須） |
| `/examples/ping` | GET | サンプルの ping |
| `/examples/protected` | GET | サンプルの保護エンドポイント（JWT 設定時） |

アプリケーションにホームページが必要な場合は別のパス（`/welcome`・`/home` など）を使うか、
ヘルスチェックを登録してフレームワークのスモークレスポンス経由で HTML を返してください。

---

## 204 No Content を返す（DELETE エンドポイント）

本文なしの 204 レスポンスを返すには `JsonResponseFactory::createEmpty()` を使います:

```php
private function delete(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $id     = (int) ($params['id'] ?? 0);
    $this->repository->delete($id); // 見つからなければ NotFoundException を投げる
    return $this->json->createEmpty(204);
}
```

> **なぜ `create([], 204)` ではないのか**: 空配列を渡すと `{}` という JSON 本文が生成されます。
> `createEmpty()` は本当に本文を持たないレスポンスを返すため、204 No Content として正しい形です。

---

## 次のステップ

ルートがデータベースの読み書きを必要とする場合は
[DB 付きエンドポイントを追加する](./add-database-endpoint.md) を参照してください。
