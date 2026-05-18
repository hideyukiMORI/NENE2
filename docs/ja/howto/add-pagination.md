# ページネーションを追加する

このガイドでは、`Nene2\Http` の `PaginationQueryParser` ヘルパーを使って、コレクションエンドポイントに `?limit=` / `?offset=` ページネーションを追加する方法を説明します。

## 前提条件

- 動作するコレクションハンドラーがある（例: `ListNotesHandler`）。
- ハンドラーが `items`、`limit`、`offset` を含む JSON エンベロープを返す。

## ステップ 1 — `PaginationQueryParser::parse()` を呼ぶ

クエリパラメーターの手動抽出をパーサーに置き換えます。値のバリデーションを行い、範囲外の場合は `ValidationException`（→ 422）をスローします。

```php
use Nene2\Http\PaginationQueryParser;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request); // デフォルト: limit=20, max=100

    $output = $this->useCase->execute(
        new ListWidgetsInput($pagination->limit, $pagination->offset),
    );

    return $this->response->create([
        'items'  => /* $output->items をマッピング */,
        'limit'  => $output->limit,
        'offset' => $output->offset,
    ]);
}
```

`PaginationQuery` は `limit: int` と `offset: int` の 2 プロパティを持つ readonly DTO です。

## ステップ 2 — 上限をカスタマイズする（オプション）

`$defaultLimit` と `$maxLimit` を渡してデフォルト（20 と 100）を上書きできます。

```php
$pagination = PaginationQueryParser::parse($request, defaultLimit: 10, maxLimit: 50);
```

| パラメーター | デフォルト | 意味 |
|---|---|---|
| `$defaultLimit` | `20` | `?limit=` がない場合に使用される値 |
| `$maxLimit` | `100` | 許可される最大値。超えると 422 を返す |

## ステップ 3 — 422 エラーへの対応

`PaginationQueryParser::parse()` は次の場合に `ValidationException` をスローします。

- `limit < 1` または `limit > $maxLimit`
- `offset < 0`

`ErrorHandlerMiddleware` が `ValidationException` → `422 validation-failed` に自動的にマッピングするため、ハンドラー内で追加のエラーハンドリングは不要です。

**422 レスポンスの例:**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    { "field": "limit", "message": "limit must be between 1 and 100.", "code": "out_of_range" }
  ]
}
```

## 仕組み

`PaginationQueryParser::parse()` は PSR-7 リクエストの `getQueryParams()` を読み取り、値を `int` にキャストしてバリデーションを行い、`PaginationQuery` DTO を返します。数値以外の値は `0` にキャストされ（PHP の `(int)` キャスト動作）、`limit < 1` チェックで捕捉されます。

## 参考

- `src/Example/Note/ListNotesHandler.php` — パーサーを使ったリファレンス実装
- `src/Example/Tag/ListTagsHandler.php` — 2 つ目の例
- `Nene2\Http\PaginationQuery` — readonly DTO
- `Nene2\Http\PaginationQueryParser` — パーサークラス
