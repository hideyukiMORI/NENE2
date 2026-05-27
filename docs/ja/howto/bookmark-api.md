# ハウツー: ブックマーク API

> **FT リファレンス**: FT295 (`NENE2-FT/bookmarklog`) — ブックマーク管理: `UNIQUE(user_id, item_id)` による重複ブックマーク防止、オプションフィルター付きのコレクショングループ化、ユーザースコープアクセス（IDOR 防止）、重複時は 409、22 テスト / 64 アサーション PASS。

このガイドでは、ユーザーが重複排除とユーザースコープアクセス制御付きで名前付きコレクションにアイテムを保存できるブックマーク API の構築方法を説明します。

## スキーマ

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` により、各ユーザーはアイテムを一度だけブックマークできます。`collection` フィールドはブックマークを名前付きリストにグループ化します（デフォルト: `'default'`）。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/users/{userId}/bookmarks` | ブックマークを追加する |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | ブックマークを削除する |
| `GET` | `/users/{userId}/bookmarks` | ブックマークを一覧表示する（コレクションでオプションフィルタリング） |
| `GET` | `/users/{userId}/bookmarks/count` | ブックマーク数を取得する |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | 特定のブックマークを取得する |

## ルート登録順序

`/users/{userId}/bookmarks/count` は `/users/{userId}/bookmarks/{itemId}` の**前に**登録して、`count` が `{itemId}` としてキャプチャされるのを防ぐ必要があります:

```php
$router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
$router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));  // 動的より先に静的
$router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
```

## ブックマークの追加

```php
private function addBookmark(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
    $collection = isset($body['collection']) && is_string($body['collection'])
        ? trim($body['collection']) : 'default';

    if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
        return $this->responseFactory->create(['error' => 'item not found'], 404);
    }

    if ($collection === '') {
        $collection = 'default';  // 空のコレクション文字列 → 'default' にフォールバック
    }

    $now      = date('Y-m-d H:i:s');
    $bookmark = $this->repo->add($userId, $itemId, $collection, $now);
    return $this->responseFactory->create($bookmark->toArray(), 201);
}
```

`item_id` は `is_int()` を要求します — JSON 文字列 `"5"` は拒否されます。DB の `UNIQUE` 制約はレース条件をキャッチします；リポジトリは制約違反をキャッチして 409 を返す必要があります。

## 一覧でのコレクションフィルター

```php
$query      = $request->getQueryParams();
$collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

`?collection=` なしではすべてのブックマークが返されます。`?collection=favorites` ではそのコレクションのみが返されます。空のコレクションクエリパラメーターは「フィルターなし」として扱われます。

## ユーザースコープ — IDOR 防止

すべてのエンドポイントはデータを返す前に `userId` を DB に対してバリデーションします:

```php
if ($userId <= 0 || !$this->repo->findUserById($userId)) {
    return $this->responseFactory->create(['error' => 'user not found'], 404);
}
```

別のユーザーとして `/users/999/bookmarks` をリクエストすると 404 を返します（他のユーザーのブックマークは返しません）。すべてのクエリはパスの `userId` にスコープされます。

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| `UNIQUE(user_id, item_id)` なし | ユーザーが同じアイテムを複数回ブックマークする；混乱する重複 |
| 重複ブックマークに 200 を返す | クライアントが「追加済み」と「すでに存在する」を区別できない；409 を使用する |
| ボディから `item_id` を文字列として受け入れる | JSON 型の混乱: `"5"` ≠ `5`；`is_int()` を使用する |
| `/{itemId}` を `/count` より前に登録する | `GET /users/1/bookmarks/count` が `itemId = "count"` に解決される（誤ったハンドラー） |
| ユーザー存在チェックなし | 存在しない userId が 404 ではなく空リストを返す |
| クエリでユーザースコープなし | ユーザー A がユーザー B のブックマークを見る（IDOR） |
| コレクションデフォルトなし | `collection` フィールドの欠落でクラッシュするか DB に `NULL` が残る |
