# ハウツー: API バージョニング

> **FT リファレンス**: FT346 (`NENE2-FT/versionlog`) — /v1/ と /v2/ 名前空間を使用した URL パスバージョニング、Deprecation/Sunset/Link ヘッダー付きの非推奨 V1、リッチなレスポンス形状の V2、共有ストレージ、16 テスト PASS。

このガイドでは URL パスバージョニングの実装方法を説明します: 異なるレスポンス形状を持つ 2 つの API バージョンを並行して実行し、HTTP ヘッダーで古いバージョンを非推奨としてマークし、単一のデータベースをバージョン間で共有します。

## バージョン戦略

| バージョン | 状態 | プレフィックス | リストラッパー |
|---------|-------------|---------|---------------------------|
| V1 | 非推奨 | `/v1/` | `{"notes": [...]}` |
| V2 | 現行 | `/v2/` | `{"data": [...], "meta": {...}}` |

両バージョンが同じデータベーステーブルを共有します。V1 クライアントは既存の統合を継続使用でき、非推奨ヘッダーが移行期限を通知します。

## エンドポイント

| メソッド | V1 パス | V2 パス | 説明 |
|----------|-----------------|-----------------|-----------------|
| `POST` | `/v1/notes` | `/v2/notes` | ノートを作成する |
| `GET` | `/v1/notes` | `/v2/notes` | ノートを一覧表示する |
| `GET` | `/v1/notes/{id}` | `/v2/notes/{id}` | 単一ノートを取得する |

## V1 レスポンス形状

```php
// POST /v1/notes
{"title": "Hello", "content": "World"}
→ 201
{
  "id": 1,
  "title": "Hello",
  "content": "World",    // ← フィールド名: "content"
  "created_at": "..."
  // "body"、"tags"、"updated_at" はなし
}

// GET /v1/notes
→ 200
{
  "notes": [              // ← ラッパーキー: "notes"
    {"id": 1, "title": "Hello", "content": "World", ...}
  ]
}
```

## V2 レスポンス形状

```php
// POST /v2/notes
{"title": "Hello", "body": "World", "tags": ["php", "api"]}
→ 201
{
  "data": {               // ← エンベロープキー: "data"
    "id": 2,
    "title": "Hello",
    "body": "World",      // ← フィールド名: "body"
    "tags": ["php", "api"],  // ← tags が追加された
    "updated_at": "...",     // ← updated_at が追加された
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← リストラッパー: "data"
  "meta": {               // ← meta セクション
    "limit": 20,
    "offset": 0
  }
}
```

## V1 非推奨ヘッダー

すべての V1 レスポンスには、クライアントに移行を促す 3 つのヘッダーが付きます:

```
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"
```

```php
// すべての V1 エンドポイントに追加:
return $response
    ->withHeader('Deprecation', 'true')
    ->withHeader('Sunset', 'Sat, 01 Jan 2027 00:00:00 GMT')
    ->withHeader('Link', '</v2/notes>; rel="successor-version"');
```

V2 レスポンスにはこれらのヘッダーは**一切**付きません。

```php
// V1 GET /v1/notes ヘッダー:
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// V2 GET /v2/notes ヘッダー:
// （Deprecation、Sunset、Link はなし）
```

## 共有ストレージ — バージョン横断アクセス

両バージョンが同じ `notes` テーブルを共有します。V1 で作成されたノートは V2 からも読み取れます（逆も同様）:

```php
// V1 で作成
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// V2 で読み取り — 同じレコード、V2 形状
GET /v2/notes/5
→ 200
{
  "data": {
    "id": 5,
    "title": "Cross-version",
    "body": "Shared body",    // V2 は "content" ではなく "body" と呼ぶ
    "tags": [],
    "updated_at": "...",
    "created_at": "..."
  }
}
```

V1 クライアントは `tags` を見ることはありません（V1 レスポンス形状にない）、たとえ V2 の書き込みでノートにタグが付いていても。

## スキーマ

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- JSON 配列
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

基礎カラムは `body` です。V1 はレスポンストランスフォーマーでこれを `content` にマップします。

## 実装 — レスポンストランスフォーマー

```php
// V1 トランスフォーマー — "body" カラム → "content" フィールドにマップ、tags/updated_at を隠す
final class V1NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['body'],   // フィールド名の変更
            'created_at' => $row['created_at'],
            // "body"、"tags"、"updated_at" はなし
        ];
    }
}

// V2 トランスフォーマー — 完全な行、"data" でラップ
final class V2NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'tags'       => json_decode($row['tags'], true),
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
```

## ルート登録

```php
// V1Registrar::register()
$router->get('/v1/notes',       [V1ListHandler::class, 'handle']);
$router->post('/v1/notes',      [V1CreateHandler::class, 'handle']);
$router->get('/v1/notes/{id}',  [V1GetHandler::class, 'handle']);

// V2Registrar::register()
$router->get('/v2/notes',       [V2ListHandler::class, 'handle']);
$router->post('/v2/notes',      [V2CreateHandler::class, 'handle']);
$router->get('/v2/notes/{id}',  [V2GetHandler::class, 'handle']);
```

両方のレジストラが `RuntimeApplicationFactory` に渡されます — 両方のルートが同じルーターに登録されます。

## 未知のバージョン → 404

```php
GET /v3/notes
→ 404
```

V3 ルートは存在せず、ルーターは 404 を返します。「バージョンがサポートされていない」エラー型は不要 — 404 で十分です。

## バリデーション

```php
POST /v1/notes  {"content": "no title"}
→ 422  // タイトルは必須

POST /v2/notes  {"body": "no title"}
→ 422  // タイトルは必須
```

両バージョンとも `title` を必須とします。V1 はボディフィールドとして `content` を受け入れ、V2 は `body` を受け入れます。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| バージョンごとに異なる DB テーブルを使う | バージョン間の読み取りが壊れる；バージョン間で状態を共有しない場合データ移行が困難 |
| V2 に `Deprecation: true` を返す | クライアントがどちらのバージョンが現行かを区別できない |
| 後継版への `Link` ヘッダーがない | 非推奨クライアントがどこに移行すればよいかわからない |
| V1 のために DB カラム `body` を `content` に変更する | V2 のすべてのコードを変更する必要がある；スキーマではなくレスポンストランスフォーマーで名前変更する |
| Sunset 日付をテストにハードコードする | Sunset 日付以降テストが失敗する；未来の定数または設定値を使用する |
| V1 レスポンスに `tags` を公開する | V1 クライアントが理解できないフィールドを受け取る；形状コントラクトが暗黙的に壊れる |
