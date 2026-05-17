# Problem Details 型

NENE2 はすべてのエラーレスポンスを `application/problem+json` で返します（[RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) 準拠）。
すべてのエラーレスポンスには `type` URI、`title`、`status`、`detail`、`instance` フィールドが含まれます。

## 型カタログ

| `type` | HTTP ステータス | `title` | 発生元 |
|---|---|---|---|
| `…/not-found` | 404 | Not Found | ルートが見つからない、または Note・Tag が存在しない |
| `…/method-not-allowed` | 405 | Method Not Allowed | 既知ルートへの不正な HTTP メソッド（`Allow` ヘッダーに有効なメソッドを列挙） |
| `…/validation-failed` | 422 | Validation Failed | リクエストボディが無効、または必須フィールドが欠落 |
| `…/unauthorized` | 401 | Unauthorized | 保護されたエンドポイントでトークンが欠落または無効 |
| `…/payload-too-large` | 413 | Payload Too Large | リクエストボディが設定サイズ制限を超えている |
| `…/internal-server-error` | 500 | Internal Server Error | 未処理例外（詳細はクライアントに公開されない） |

ベース URI プレフィックス: `https://nene2.dev/problems/`

## レスポンス例

**404 Not Found:**

```json
{
  "type": "https://nene2.dev/problems/not-found",
  "title": "Not Found",
  "status": 404,
  "detail": "The requested resource was not found.",
  "instance": "/examples/notes/99"
}
```

**422 Validation Failed:**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request contains invalid values.",
  "instance": "/examples/notes",
  "errors": [
    { "field": "title", "message": "Title must not be empty.", "code": "required" }
  ]
}
```

## カスタム型の追加

1. ドメイン例外クラスを作成します（例: `ProductNotFoundException`）。
2. `DomainExceptionHandlerInterface` を実装し、`ProblemDetailsResponseFactory::create()` に型スラグを渡します。
3. `RuntimeServiceProvider` でハンドラーを登録します。

具体的な実装例は `NoteNotFoundExceptionHandler` および `TagNotFoundExceptionHandler` を参照してください。
