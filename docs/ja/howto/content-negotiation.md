# コンテンツネゴシエーション

NENE2 は JSON ファーストのフレームワークです。コンテンツネゴシエーションは実装していません — クライアントが `Accept` ヘッダーに何を送信しても、すべてのレスポンスは `application/json`（またはエラーには `application/problem+json`）を使用します。

## NENE2 の動作

| クライアントの送信 | サーバーの返却 |
|---|---|
| `Accept` ヘッダーなし | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**NENE2 は `406 Not Acceptable` を返しません。** RFC 7231 §6.5.6 は許容可能な型が利用できない場合にサーバーが 406 を返すべき（SHOULD）と述べていますが、これは SHOULD（MUST ではない）です。JSON のみの API サーバーでは、常に JSON を返すのが最もシンプルで一般的な選択です。

エラーレスポンスは `Accept` に関係なく `application/problem+json`（RFC 9457）を使用します:

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## リクエストボディの Content-Type

`JsonRequestBodyParser::parse()` は受信リクエストの `Content-Type` ヘッダーをチェックしません。無条件にボディを JSON デコードしようとします:

```php
// 3 つすべてが JsonRequestBodyParser::parse() に同様に到達する:
// Content-Type: application/json → 動作する
// Content-Type: application/x-www-form-urlencoded → 400（フォームボディの JSON パース失敗）
// （Content-Type なし）+ JSON ボディ → 動作する
```

つまり:
- `Content-Type` なしの有効な JSON ボディは受け入れられます — リベラルな入力ポリシー。
- フォームエンコードされたボディ（`name=Alice&age=30`）は 415 Unsupported Media Type ではなく 400 Bad Request になります（JSON パース失敗）。

## 406 または 415 レスポンスが必要な場合

ルートハンドラーの前に `Accept` と `Content-Type` ヘッダーを検査するミドルウェアを追加します:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class JsonOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // JSON のみの Accept を強制する（オプション — ほとんどのクライアントは */* または application/json を送信する）
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // 状態変更リクエストに JSON Content-Type を強制する
        $method      = strtoupper($request->getMethod());
        $contentType = $request->getHeaderLine('Content-Type');
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && $contentType !== ''
            && !str_contains($contentType, 'application/json')
        ) {
            return $this->problems->create($request, 'unsupported-media-type', 'Unsupported Media Type', 415,
                'This API only accepts application/json request bodies.');
        }

        return $handler->handle($request);
    }
}
```

`RuntimeApplicationFactory` 経由でワイヤリングします:

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **注意:** `authMiddleware` はルーティングの前に評価されます。グローバルに適用したい場合はコンテンツタイプの強制をここに配置してください。
