# CSRF と JSON API

## CORS ≠ CSRF 対策

これは Web API 開発で最もよくあるセキュリティの誤解の 1 つです。

**CORS**（Cross-Origin Resource Sharing）は、あるオリジンの JavaScript が別のオリジンからの*レスポンスを読み取れるか*どうかをブラウザが制御するものです。サーバーが `Access-Control-Allow-Origin` ヘッダーを追加し、ブラウザがポリシーを強制します。

**CSRF**（Cross-Site Request Forgery）は、悪意のあるページが被害者のブラウザをだまして、被害者のセッションクッキーを使って信頼されたサイトへの状態変更リクエストを送信させる攻撃です。

NENE2 の `CorsMiddleware` は CORS を処理します。未知のオリジンからのリクエストを**ブロックしません**。`Origin: https://evil.example.com` を持つリクエストは通過してハンドラーに変更なく届きます — これは期待される動作です。CORS は *JavaScript が読み取れるもの*を制限するブラウザのセーフガードであり、*サーバーが受け入れるもの*ではありません。

```
# これらはすべてハンドラーに届きます — CorsMiddleware はブロックしません
curl -X POST https://api.example.com/orders \
  -H "Origin: https://evil.example.com" \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'

curl -X POST https://api.example.com/orders \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'
# （Origin ヘッダーなし — サーバー間呼び出しなど）
```

## JSON API がフォームベース API より CSRF に強い理由

古典的な CSRF は HTML フォーム（`<form method="POST">`）を悪用します。ブラウザはフォーム送信を `Content-Type: application/x-www-form-urlencoded` または `multipart/form-data` で送信します — ブラウザはセッションクッキーを自動的に含めます。

`Content-Type: application/json` を持つリクエストは CORS 仕様の**「シンプルリクエスト」ではありません**。ブラウザはまずプリフライトの `OPTIONS` を送信します。CORS 設定に攻撃者のオリジンがリストアップされていない場合、ブラウザはプリフライトをブロックします — 実際のリクエストは届きません。

ただし、**これはブラウザベースの攻撃のみを保護します**。サーバーや明示的なヘッダーを持つ `fetch()` 呼び出しは制限なしに `Content-Type: application/json` を API に送信できます。CORS プリフライトはサーバーではなくブラウザによって強制されます。

## 本当の保護: Bearer JWT

NENE2 の標準認証は `Authorization` ヘッダーの Bearer JWT を使用します:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

CSRF 攻撃はクッキーを悪用することで機能します — ブラウザはクロスサイトリクエストに自動的にクッキーを添付します。`Authorization` ヘッダーは**自動的に**送信されません。`https://evil.example.com` の悪意あるページは、`https://app.example.com` からトークンを JavaScript で読み取れないため、被害者の JWT を含めることができません。

Bearer JWT 認証を使用し、クッキーにトークンを保存しない限り、設計上 CSRF に脆弱ではありません。追加の CSRF トークンや `SameSite` 属性は不要です。

## クッキーベースのセッションを使用する場合

アプリケーションが（Bearer JWT の代わりに）セッション管理のために `Set-Cookie` を使用する場合、明示的な CSRF 対策が必要です:

### オプション 1: SameSite クッキー（最もシンプル）

```php
Set-Cookie: session=...; SameSite=Strict; Secure; HttpOnly
```

`SameSite=Strict` はブラウザがクロスサイトリクエストにクッキーを含めることを防ぎます。`SameSite=Lax` も、クロスサイトの `POST` をブロックする合理的なデフォルトです。

### オプション 2: Origin ヘッダーバリデーションミドルウェア

`Origin` が許可リストと一致しないリクエストを拒否します:

```php
final class OriginEnforcementMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // 非ブラウザ呼び出し（curl、サーバー間）には Origin がない — 許可する
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!in_array($origin, $this->allowedOrigins, strict: true)) {
            // 403 Problem Details レスポンスを返す
            // ...
        }

        return $handler->handle($request);
    }
}
```

ミドルウェアスタックの CORS の後に登録してください（順序については CLAUDE.md セクション 5 を参照）。

### オプション 3: CSRF トークン

セッションごとにトークンを生成し、サーバー側に保存し、フォームに隠しフィールドとして含め、すべての状態変更リクエストで検証します。これは従来のアプローチですが複雑さが増します。

## まとめ

| シナリオ | CSRF リスク | 推奨軽減策 |
|---|---|---|
| `Authorization` ヘッダーの Bearer JWT | なし — ヘッダーは自動送信されない | 対応不要 |
| クッキーセッション、SameSite=Strict | 非常に低い | `SameSite=Strict` を維持する |
| クッキーセッション、SameSite なし | 高い | `SameSite` または Origin 強制を追加する |
| カスタムヘッダーの API キー | なし — カスタムヘッダーは自動送信されない | 対応不要 |

最もシンプルな方法: NENE2 の組み込み Bearer JWT 認証を使用し、API エンドポイントではクッキーベースのセッションを避けてください。
