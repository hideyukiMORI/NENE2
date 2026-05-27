# ハウツー: コレクション API（ユーザーキュレーテッドリスト）

> **FT リファレンス**: FT299 (`NENE2-FT/collectionlog`) — ユーザーキュレーテッドの記事コレクション: is_public/プライベートの可視性（非オーナーにはプライベートを 404 で返す）、UNIQUE(collection_id, article_id) による重複排除、ポジション順序付け、オーナーのみの書き込みアクセス、20 テスト / 34 アサーション PASS。

このガイドでは、ユーザーが名前付きリストを作成し、記事を追加し、パブリック/プライベートの可視性を制御できるユーザーキュレーテッドコレクション API の構築方法を説明します。

## スキーマ

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=プライベート, 1=パブリック
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id    INTEGER NOT NULL,
    position      INTEGER NOT NULL,
    added_at      TEXT    NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id)    REFERENCES articles(id)
);
```

`UNIQUE(collection_id, article_id)` は同じ記事がコレクションに 2 回現れることを防止します。`position` で順序付けされた表示が可能になります。

## エンドポイント

| メソッド | パス | 認証 | 説明 |
|--------|------|------|-------------|
| `POST` | `/collections` | `X-User-Id` | コレクションを作成する |
| `GET` | `/collections/{id}` | `X-User-Id` | コレクションを取得する（可視性チェック） |
| `PUT` | `/collections/{id}` | `X-User-Id`（オーナー） | 名前/可視性を更新する |
| `DELETE` | `/collections/{id}` | `X-User-Id`（オーナー） | コレクションを削除する |
| `POST` | `/collections/{id}/items` | `X-User-Id`（オーナー） | 記事を追加する |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id`（オーナー） | 記事を削除する |

## 可視性 — プライベートコレクションには 404

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

プライベートコレクションにアクセスしようとする非オーナーは 404 を受け取ります — 403 ではありません。これによりプライベートコレクションの存在が開示されるのを防止します。

## オーナーのみの書き込みアクセス

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

追加、削除、更新、削除の操作にはアクターがコレクションオーナーである必要があります。可視性とは異なり、書き込みアクセスの失敗は 403 を返します（この時点でコレクションの存在はすでに知られています）。

## UNIQUE(collection_id, article_id) — 重複排除

DB 制約により同じ記事がコレクションに 2 回現れることを防止します。アプリケーションは挿入前に重複をチェックします:

```php
// リポジトリが addItem() の前に findItem() をチェックする
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## ブール整数としての is_public

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` は SQLite に INTEGER（0/1）として保存されます。読み取り時: `(bool) $collection['is_public']`。書き込み時: 厳密な `=== true` チェックで文字列 `"true"` がパブリックアクセスを有効にするのを防止します。

## レスポンス形状

```php
private function formatCollection(array $collection, array $items): array
{
    return [
        'id'         => (int)    $collection['id'],
        'user_id'    => (int)    $collection['user_id'],
        'name'       => (string) $collection['name'],
        'is_public'  => (bool)   $collection['is_public'],
        'item_count' => count($items),
        'items'      => array_map(fn($item) => [
            'article_id' => (int)    $item['article_id'],
            'title'      => (string) $item['article_title'],
            'position'   => (int)    $item['position'],
            'added_at'   => (string) $item['added_at'],
        ], $items),
        'created_at' => (string) $collection['created_at'],
        'updated_at' => (string) $collection['updated_at'],
    ];
}
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| プライベートコレクションアクセスに 403 を返す | 非オーナーにコレクションの存在が明かされる（情報開示） |
| どのユーザーでも任意のコレクションにアイテムを追加できる | 非オーナーが他のユーザーのコレクションにコンテンツをインジェクトする |
| `UNIQUE(collection_id, article_id)` なし | 同じ記事が 2 回追加される。紛らわしい重複エントリ |
| `is_public` に文字列 `"true"` を受け入れる | 型の混乱: 緩い比較では任意の文字列が真値になる |
| position フィールドなし | アイテムは常に挿入順で表示される。並べ替えが不可能 |
| 所有権チェックなしでコレクションを DELETE | 任意のユーザーが任意のコレクションを削除できる |
| アイテムを含めずに `item_count` を公開する | プライベートコレクションの非オーナーにもコレクションサイズが明かされる |
