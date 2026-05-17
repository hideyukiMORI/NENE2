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

---

## 使用可能な HTTP メソッド

| メソッド | Router メソッド | 典型的な用途 |
|---|---|---|
| GET | `$router->get()` | リソースを読み取る |
| POST | `$router->post()` | リソースを作成する |
| PUT | `$router->put()` | リソースを置き換える（完全更新） |
| DELETE | `$router->delete()` | リソースを削除する |

---

## 次のステップ

ルートがデータベースの読み書きを必要とする場合は
[DB 付きエンドポイントを追加する](./add-database-endpoint.md) を参照してください。
