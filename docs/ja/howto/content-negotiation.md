# コンテンツネゴシエーション

NENE2 は JSON ファーストのフレームワークです。コンテンツネゴシエーションは実装していません — クライアントが `Accept` ヘッダーで何を送ろうと、すべてのレスポンスは `application/json`（エラー時は `application/problem+json`）を使います。

## NENE2 が行うこと

| クライアントが送るもの | サーバーが返すもの |
|---|---|
| `Accept` ヘッダーなし | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**NENE2 は決して `406 Not Acceptable` を返しません。** RFC 7231 §6.5.6 では、受け入れ可能な型がない場合にサーバーは 406 を返すべきと書かれていますが、これは SHOULD であって MUST ではありません。JSON 専用 API サーバーにとっては、常に JSON を返すのが最もシンプルでよくある選択です。

エラーレスポンスは `Accept` に関わらず `application/problem+json` (RFC 9457) を使います。

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## リクエストボディの Content-Type

`JsonRequestBodyParser::parse()` は受信リクエストの `Content-Type` ヘッダーをチェックしません。無条件にボディを JSON デコードしようとします。

```php
// 3 つすべてが同じ JsonRequestBodyParser::parse() に到達:
// Content-Type: application/json → 動作する
// Content-Type: application/x-www-form-urlencoded → 400 (フォームボディの JSON パース失敗)
// (Content-Type なし) + JSON ボディ → 動作する
```

つまり:
- `Content-Type` なしの有効な JSON ボディは受け入れられる — 寛容な入力ポリシー。
- フォームエンコードされたボディ（`name=Alice&age=30`）は 400 Bad Request になる（JSON パース失敗）。415 Unsupported Media Type ではない。

## 406 や 415 のレスポンスが必要な場合

ルートハンドラーの前で `Accept` と `Content-Type` ヘッダーを検査するミドルウェアを追加します。

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
        // JSON のみの Accept を強制（オプション — ほとんどのクライアントは */* か application/json を送る）
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // ステート変更リクエストに JSON Content-Type を強制
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

`RuntimeApplicationFactory` 経由でワイヤリングします。

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **注**: `authMiddleware` はルーティングの前に評価されます。グローバルに適用したい場合は、コンテンツタイプ強制をここに置いてください。
