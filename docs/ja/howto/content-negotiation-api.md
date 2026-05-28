# How-to: コンテンツネゴシエーション — JSON API

> **FT リファレンス**: FT301 (`NENE2-FT/contentlog`) — JSON API のコンテンツネゴシエーション: `Accept` ヘッダーに関わらず常に `application/json` を返し、エラー (404/422/405) では `application/problem+json` を返し、POST で JSON 以外の `Content-Type` には 415 を返す。16 tests / 28 assertions PASS。

このガイドでは、NENE2 のランタイムが JSON API の HTTP コンテンツネゴシエーションをどう扱うかを説明します。受け入れる `Accept` ヘッダーの値、`Content-Type` がいつ重要になるか、エラーレスポンスが `application/problem+json` をどう使うかをカバーします。

## 常に JSON — Accept ヘッダーを無視

NENE2 の JSON API は、クライアントが送る `Accept` ヘッダーに関わらず、成功レスポンスでは `application/json` を返します。

| 送信された Accept ヘッダー | レスポンスの Content-Type |
|---|---|
| _(なし)_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

これは純粋な API サービスに対する意図的な設計です。サーバーは API 専用エンドポイントであり、複数フォーマットをネゴシエートするサーバーではありません。`Accept: text/html` を送るクライアントも JSON を受け取ります。

## エラーレスポンス — application/problem+json

エラーレスポンスは、`Accept` ヘッダーに関わらず `application/problem+json` (RFC 9457) を使います。

| シナリオ | ステータス | Content-Type |
|---|---|---|
| ルートが見つからない | 404 | `application/problem+json` |
| メソッドが許可されていない | 405 | `application/problem+json` |
| バリデーション失敗 | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory は常に application/problem+json を生成する
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

クライアントは HTTP ステータスコードか `Content-Type: application/problem+json` のチェックでエラーを検出できます。

## リクエストの Content-Type — POST ボディ

JSON ボディを持つ `POST` リクエストには、NENE2 は `JsonRequestBodyParser::parse()` を使います。

```php
$body = JsonRequestBodyParser::parse($request);
```

リクエストに `Content-Type: text/plain` や類似の JSON ではない型が明示されている場合、パーサーは空配列を返すことがあります。一方、`Content-Type` ヘッダーがまったく付いていない有効な JSON ボディは、パーサーが受け入れます。

```
POST /articles (Content-Type なし、JSON ボディ) → 201 Created ✅
POST /articles (Content-Type: text/plain) → 415 Unsupported Media Type ✅
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

`trim()` 後、空文字列はフィールド未指定と同じ扱いになります。バリデーションエラーは `field` / `code` / `message` キーを持つ構造化された `errors` 配列を返します — 標準的な RFC 9457 拡張です。

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

`GET /articles`（リスト）は `GET /articles/{id}`（単一）より先に登録されます — もっともこの場合は両方とも GET でパスが異なるため、順序による捕捉の衝突は起きません。リストルートは静的パスを使い、単一ルートは `{id}` の動的キャプチャを使います。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| サポートされない `Accept` ヘッダーに対して 406 を返す | API 専用サービスはすべてのクライアントに JSON を提供すべきで、拒否すべきではない |
| `application/json` の代わりに `text/json` を使う | 非標準の MIME タイプで、一部のクライアントが認識しない |
| エラーレスポンスに普通の `application/json` を返す | クライアントが Content-Type でエラーと成功を区別できない。`application/problem+json` を使う |
| バリデーションエラーの `errors` 配列を省略する | クライアントがユーザーにフィールド単位のエラーメッセージを表示できない |
| JSON ボディに `Content-Type: text/plain` を受け入れる | 入力が曖昧。受け入れるコンテンツタイプは明示すべき |
| バリデーション後に trim する | `trim()` は空文字チェックの前に行う必要がある。先にチェックすると `" "` が通ってしまう |
