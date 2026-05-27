# ハウツー: コンテンツネゴシエーション — JSON API

> **FT リファレンス**: FT301 (`NENE2-FT/contentlog`) — JSON API コンテンツネゴシエーション: `Accept` ヘッダーに関係なく常に `application/json` を返す、エラー（404/422/405）には `application/problem+json`、POST の非 JSON `Content-Type` には 415、16 テスト / 28 アサーション PASS。

このガイドでは、NENE2 のランタイムが JSON API の HTTP コンテンツネゴシエーションをどのように処理するかをカバーします — どの `Accept` ヘッダー値が受け入れられるか、`Content-Type` が重要な場合、エラーレスポンスが `application/problem+json` を使用する方法。

## 常に JSON — Accept ヘッダーを無視する

NENE2 JSON API は、クライアントが送信する `Accept` ヘッダーに関係なく成功レスポンスに `application/json` を返します:

| 送信された Accept ヘッダー | レスポンス Content-Type |
|---|---|
| _（なし）_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

これは純粋な API サービスでは意図的です: サーバーは API のみのエンドポイントであり、コンテンツネゴシエーションするマルチフォーマットサーバーではありません。`Accept: text/html` を送信するクライアントでも JSON を受け取ります。

## エラーレスポンス — application/problem+json

エラーレスポンスは `Accept` ヘッダーに関係なく `application/problem+json`（RFC 9457）を使用します:

| シナリオ | ステータス | Content-Type |
|---|---|---|
| ルートが見つからない | 404 | `application/problem+json` |
| メソッドが許可されていない | 405 | `application/problem+json` |
| バリデーション失敗 | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory は常に application/problem+json を生成する
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

クライアントは HTTP ステータスコードまたは `Content-Type: application/problem+json` を確認することでエラーを検出できます。

## リクエスト Content-Type — POST ボディ

JSON ボディを持つ `POST` リクエストに対して、NENE2 は `JsonRequestBodyParser::parse()` を使用します:

```php
$body = JsonRequestBodyParser::parse($request);
```

リクエストに明示的な `Content-Type: text/plain` または類似の非 JSON 型がある場合、パーサーは空の配列を返す可能性があります。ただし、`Content-Type` ヘッダーが全くないが有効な JSON ボディがある場合、パーサーはそれを受け入れます:

```
POST /articles （Content-Type なし、JSON ボディ） → 201 Created ✅
POST /articles （Content-Type: text/plain） → 415 Unsupported Media Type ✅
```

## バリデーション — 必須フィールド

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

if ($title === '') {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
    ]);
}
```

`trim()` 後、空の文字列は欠落フィールドと同様に扱われます。バリデーションエラーは `field`、`code`、`message` キーを持つ構造化された `errors` 配列を返します — 標準 RFC 9457 拡張。

## レスポンス形状

```json
// GET /articles
{
    "items": [
        { "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }
    ],
    "total": 1
}

// POST /articles → 201
{ "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }

// GET /articles/999 → 404 (application/problem+json)
{ "type": "https://nene2.dev/problems/not-found", "title": "Article Not Found", "status": 404 }
```

## ルート登録

```php
$router->post('/articles', $this->createArticle(...));
$router->get('/articles', $this->listArticles(...));
$router->get('/articles/{id}', $this->getArticle(...));
```

`GET /articles`（一覧）は `GET /articles/{id}`（単一）の前に登録されます — この場合は両方が異なるパスを持つ GET なのでキャプチャの競合は生じませんが。一覧ルートは静的パスを使用し、単一ルートは `{id}` 動的キャプチャを使用します。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| サポートされていない `Accept` ヘッダーに 406 を返す | API のみのサービスはすべてのクライアントに JSON を提供すべき。拒否しない |
| `application/json` の代わりに `text/json` を使用する | 非標準 MIME タイプ。一部のクライアントが認識しない |
| エラーレスポンスに普通の `application/json` を返す | クライアントが Content-Type でエラーと成功を区別できない。`application/problem+json` を使用する |
| バリデーションエラーの `errors` 配列を省略する | クライアントがフィールドレベルのエラーメッセージをユーザーに表示できない |
| JSON ボディに `Content-Type: text/plain` を受け入れる | 曖昧な入力。受け入れるコンテンツタイプを明示する |
| バリデーション後に trim する | `trim()` は空文字列チェックの前に来なければならない。トリム前にチェックすると `" "` が通過してしまう |
