# ハウツー: API バージョニング

> **FT リファレンス**: FT346 (`NENE2-FT/versionlog`) — `/v1/` と `/v2/` 名前空間による URL パスバージョニング、Deprecation/Sunset/Link ヘッダーを持つ非推奨 V1、強化されたレスポンス形状の V2、共有された基盤ストレージ、16 テスト PASS。

このガイドでは URL パスバージョニングの実装方法を説明します: 異なるレスポンス形状で 2 つの API バージョンを並行して実行し、HTTP ヘッダーで古いバージョンを非推奨としてマークし、単一のデータベースをバージョン間で共有します。

## バージョン戦略

| バージョン | ステータス | プレフィックス | リストラッパー |
|---------|-------------|---------|---------------------------|
| V1 | 非推奨 | `/v1/` | `{"notes": [...]}` |
| V2 | 現行 | `/v2/` | `{"data": [...], "meta": {...}}` |

両バージョンは同じデータベーステーブルを共有します。V1 クライアントは既存の統合を使い続けながら、非推奨ヘッダーが移行期限を通知します。

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
  // "body"、"tags"、"updated_at" なし
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
    "tags": ["php", "api"],  // ← tags 追加
    "updated_at": "...",     // ← updated_at 追加
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← リストラッパー: "data"
  "meta": {               // ← メタセクション
    "limit": 20,
    "offset": 0
  }
}
```

## V1 非推奨ヘッダー

すべての V1 レスポンスには移行を促す 3 つのヘッダーが付きます:

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

V2 レスポンスにはこれらのヘッダーは**ありません**。

```php
// V1 GET /v1/notes のヘッダー:
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// V2 GET /v2/notes のヘッダー:
// （Deprecation、Sunset、Link なし）
```

## 共有ストレージ — バージョン間アクセス

両バージョンは同じ `notes` テーブルを共有します。V1 経由で作成されたノートは V2 から読み取れます（逆も同様）:

```php
// V1 経由で作成
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// V2 経由で読み取り — 同じレコード、V2 形状
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

ノートに V2 の書き込みからタグがある場合でも、V1 クライアントは `tags` を見ません（V1 レスポンス形状にない）。

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

基盤カラムは `body` です。V1 はレスポンストランスフォーマーでそれを `content` にマップします。

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
            'content'    => $row['body'],   // フィールド名変更
            'created_at' => $row['created_at'],
            // "body"、"tags"、"updated_at" なし
        ];
    }
}

// V2 トランスフォーマー — フル行、"data" でラップ
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

両方のレジストラーが `RuntimeApplicationFactory` に渡されます — 両方のルートが同じルーターに登録されます。

## 未知のバージョン → 404

```php
GET /v3/notes
→ 404
```

V3 ルートがないため、ルーターは 404 を返します。「バージョン未サポート」エラー型は不要です — 404 で十分です。

## バリデーション

```php
POST /v1/notes  {"content": "no title"}
→ 422  // title は必須

POST /v2/notes  {"body": "no title"}
→ 422  // title は必須
```

両バージョンとも `title` が必要です。V1 はボディフィールドとして `content` を受け入れ、V2 は `body` を受け入れます。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| バージョンごとに異なる DB テーブル | バージョン間の読み取りが壊れる。バージョン間で状態を共有しない場合データ移行が困難 |
| V2 で `Deprecation: true` を返す | クライアントがどのバージョンが現行かを区別できない |
| `Link` ヘッダーに後継バージョンなし | 非推奨クライアントがどこに移行するか知らない |
| V1 のために DB カラム `body` → `content` に名前変更する | すべての V2 コードを変更しなければならない。スキーマではなくレスポンストランスフォーマーで名前変更する |
| テストに Sunset 日付をハードコードする | Sunset 日付後にテストが失敗する。将来の定数または設定値を使用する |
| V1 レスポンスで `tags` を公開する | V1 クライアントが理解しないフィールドを受け取る。形状コントラクトがサイレントに壊れる |
