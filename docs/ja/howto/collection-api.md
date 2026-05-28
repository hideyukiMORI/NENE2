# How-to: コレクション API（ユーザーキュレートリスト）

> **FT 参照**: FT299 (`NENE2-FT/collectionlog`) — ユーザーキュレートの記事コレクション: is_public/private による可視性（プライベートには非所有者に 404）、UNIQUE(collection_id, article_id) による重複排除、position による順序、所有者のみの書き込みアクセス、20 テスト / 34 アサーション PASS。

このガイドでは、ユーザーが名前付きリストを作成し、記事を追加し、公開/非公開の可視性を制御するユーザーキュレート型コレクション API の構築方法を示します。

## スキーマ

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=private, 1=public
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

`UNIQUE(collection_id, article_id)` は同じ記事がコレクションに 2 回現れることを防ぎます。`position` は順序付き表示を可能にします。

## エンドポイント

| Method | Path | Auth | 説明 |
|--------|------|------|-------------|
| `POST` | `/collections` | `X-User-Id` | コレクションを作成 |
| `GET` | `/collections/{id}` | `X-User-Id` | コレクションを取得（可視性チェック） |
| `PUT` | `/collections/{id}` | `X-User-Id`（所有者） | 名前/可視性を更新 |
| `DELETE` | `/collections/{id}` | `X-User-Id`（所有者） | コレクションを削除 |
| `POST` | `/collections/{id}/items` | `X-User-Id`（所有者） | 記事を追加 |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id`（所有者） | 記事を削除 |

## 可視性 — プライベートコレクションには 404

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

非所有者がプライベートコレクションにアクセスしようとした場合、403 ではなく 404 を返します。これによりプライベートコレクションの存在を露呈することを防ぎます。

## 所有者のみの書き込みアクセス

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

追加・削除・更新・削除操作にはアクター（操作者）がコレクション所有者であることが必要です。可視性とは異なり、書き込みアクセス失敗は 403 を返します（この時点でコレクションの存在は既知のため）。

## UNIQUE(collection_id, article_id) — 重複排除

DB 制約により、同じ記事がコレクションに 2 回現れることを防ぎます。アプリケーションは挿入前に重複をチェックします:

```php
// リポジトリは addItem() の前に findItem() でチェック
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## is_public を真偽整数として扱う

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` は SQLite では INTEGER（0/1）として保存されます。読み込み時: `(bool) $collection['is_public']`。書き込み時: 厳密な `=== true` チェックで文字列 `"true"` による公開有効化を防ぎます。

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
| プライベートコレクションアクセスに 403 を返す | 非所有者にコレクションの存在を露呈する（情報開示） |
| 任意のユーザーが任意のコレクションにアイテム追加を許可する | 非所有者が他人のコレクションにコンテンツを注入する |
| `UNIQUE(collection_id, article_id)` がない | 同じ記事が 2 回追加され、紛らわしい重複エントリができる |
| `is_public` に `"true"` 文字列を受け付ける | 型混同: 緩い比較では任意の文字列が truthy になる |
| position フィールドがない | アイテムが常に挿入順に表示され、並べ替え不可 |
| 所有権チェックなしで DELETE collection | 任意のユーザーが任意のコレクションを削除 |
| items を含めず `item_count` を露出する | プライベートコレクションの非所有者にもコレクションサイズが露呈する |
