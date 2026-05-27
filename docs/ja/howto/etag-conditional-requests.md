# ETag と条件付きリクエスト

> **FT リファレンス**: FT307 (`NENE2-FT/etaglog`) — ETag 条件付きリクエスト: `If-None-Match`→304、`If-Modified-Since`→304、`If-Match`→412 stale / 428 absent、ワイルドカード `If-Match: *` は通過、ETag は更新ごとに変更、15 テスト PASS。

ETag はクライアントが変更のないコンテンツの再ダウンロードを避け、書き込み前に古い状態を検知できるようにします。NENE2 は最も一般的なパターンのために 2 つのヘルパーを提供します。

| シナリオ | ヘッダー | ヘルパー | 一致時 |
|---|---|---|---|
| 条件付き GET | `If-None-Match` | `ConditionalGetHelper` | 304 Not Modified |
| 条件付き書き込み | `If-Match` | `ConditionalWriteHelper` | 書き込みを実行 |
| ヘッダーなしの書き込み | — | `ConditionalWriteHelper` | 428 Precondition Required |
| 古い ETag での書き込み | `If-Match` | `ConditionalWriteHelper` | 412 Precondition Failed |

## ETag 生成

リソースコンテンツから強い ETag をダブルクォートで囲んだ MD5 として生成します:

```php
final readonly class Article
{
    public function etag(): string
    {
        // ダブルクォートは RFC 9110 で必須 — これなしでは If-None-Match の比較が常に失敗する
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

アルゴリズムの変更（例: SHA-256 へ）を 1 箇所の編集で済むように、ETag 生成を 1 箇所（エンティティのメソッド）にまとめてください。

## 条件付き GET — 304 Not Modified

```php
private function get(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    $etag = $article->etag();

    // If-None-Match が現在の ETag に一致する場合は 304 レスポンスを返す。
    // 完全な 200 レスポンスを送信する必要がある場合は null を返す。
    $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
    if ($notModified !== null) {
        return $notModified;
    }

    return $this->json->create($this->serialize($article))
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

`ConditionalGetHelper::check()` は 2 つのヘッダーを評価します:
- `If-None-Match`: 完全な ETag 一致 → 304
- `If-Modified-Since`: 文字列比較 `$ifModifiedSince >= $lastModified` → 304

`check()` の呼び出しと `withHeader('ETag', $etag)` の呼び出しに常に同じ `$etag` 値を渡してください。別々に生成するとずれが生じるリスクがあります。

### Last-Modified フォーマット

`If-Modified-Since` チェックは**文字列比較**であり、解析された日付比較ではありません。辞書的にソートできるフォーマットを使用してください — ISO 8601 を推奨します:

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'); // ✅ 2026-05-21T12:00:00Z
```

HTTP 標準の `Sat, 21 May 2026 12:00:00 GMT` フォーマットは正しくソートされません — このヘルパーには使用しないでください。

### 304 にはボディがない

RFC 9110 は 304 レスポンスのボディを禁止しています。`ConditionalGetHelper` は空の `createResponse(304)` を返します。ヘルパーのレスポンスを直接返す限り、これは正しく処理されます。

## 条件付き書き込み — If-Match

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    // 書き込みの前に呼び出す必要がある — 後でチェックしても意味がない。
    // If-Match が不在の場合は 428 を返す。If-Match が存在するが間違っている場合は 412 を返す。
    // 事前条件が通過した場合は null を返す。
    $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
    if ($preconditionFailed !== null) {
        return $preconditionFailed;
    }

    $updated = $this->repo->update($id, $title, $body);

    return $this->json->create($this->serialize($updated))
        ->withHeader('ETag', $updated->etag())
        ->withHeader('Last-Modified', $updated->updatedAt);
}
```

### If-Match: * ワイルドカード

クライアントは `If-Match: *` を送信して「リソースが存在する限り続行する」と意味できます。`ConditionalWriteHelper` はこれを無条件に通過させます。**リソースが存在しない場合に 404 を返すのは呼び出し元の責任です** — まずレコードを取得して 404 でガードしてください。

### If-Match をオプションにする

デフォルト（`$require = true`）では、`If-Match` が不在の場合に 428 を返します。事前条件ヘッダーなしの書き込みを許可するには:

```php
ConditionalWriteHelper::check($request, $this->problems, $article->etag(), require: false);
```

これを緩和するのは、リソースに対してオプティミスティックロッキングが本当にオプションの場合のみにしてください。

## クライアントフロー

```
POST /articles            → 201 { id: 1, ... }  ETag: "abc123"
GET  /articles/1          → 200 { id: 1, ... }  ETag: "abc123"

GET  /articles/1          → 304 (ボディなし)
  If-None-Match: "abc123"

PATCH /articles/1         → 200 { ... }  ETag: "def456"
  If-Match: "abc123"
  { title: "Updated" }

PATCH /articles/1         → 412 Precondition Failed
  If-Match: "abc123"       (古い — コンテンツが変更され、ETag は現在 "def456")

PATCH /articles/1         → 428 Precondition Required
  (If-Match ヘッダーなし)

PATCH /articles/1         → 200 { ... }
  If-Match: *              (ワイルドカード — 任意の既存バージョン)
```

## すべてのレスポンスに ETag を含める

POST、GET、PATCH レスポンスに `ETag`（と `Last-Modified`）を返すことで、クライアントは追加のラウンドトリップなしに常に新しい値を持てます:

```php
return $this->json->create($this->serialize($article), 201)
    ->withHeader('ETag', $article->etag())
    ->withHeader('Last-Modified', $article->updatedAt);
```

## ETag vs バージョンフィールド

| | ETag（HTTP ヘッダー） | バージョンフィールド（ボディ） |
|---|---|---|
| チェック場所 | HTTP ヘッダー | リクエストボディ |
| 粒度 | コンテンツハッシュ | 整数カウンター |
| クライアントが追跡する必要があるもの | ETag 値 | バージョン番号 |
| 最適な用途 | HTTP キャッシング + オプティミスティックロッキング | API レベルの競合検知 |

両方を併用できます: HTTP キャッシング用の ETag と DB レベルの競合検知用のバージョン（[optimistic-locking.md](optimistic-locking.md) 参照）。

## コードレビューチェックリスト

- [ ] ETag 文字列にダブルクォートが含まれている（`'"' . md5(...) . '"'`）
- [ ] ETag 生成が 1 箇所（エンティティメソッド）にあり、ハンドラー間で重複していない
- [ ] `ConditionalGetHelper::check()` が 200 レスポンスを構築する前に呼び出されている
- [ ] 同じ `$etag` 値が `check()` と `withHeader('ETag', $etag)` の両方に渡されている
- [ ] `ConditionalWriteHelper::check()` が書き込みの前に呼び出されている
- [ ] 304 レスポンスのボディが空（ヘルパーのレスポンスを直接使用）
- [ ] `Last-Modified` 値が ISO 8601 フォーマットを使用（辞書的順序付けが必要）
- [ ] すべてのレスポンス（201、200）に `ETag` が含まれており、クライアントが常に新しい値を持てる
- [ ] テストがカバーしている: `If-None-Match` なしの 200、一致時の 304、古い ETag での 200、`If-Match` なしの 428、古い `If-Match` での 412、正しい `If-Match` での 200、`If-Match: *`
