# コンテンツコレクション

記事のキュレーションコレクション（公開/非公開）システムの実装ガイド。
公開設定・IDOR 防止（存在非公開）・冪等追加・位置詰め整合を解説する。

## 概要

- ユーザーが名前付きコレクション（リスト）を作成
- 各コレクションに記事を追加（最大 50 件）
- `is_public` フラグ: 公開コレクションは誰でも閲覧可能
- 非公開コレクションは非オーナーに 404（存在非公開パターン）
- 記事追加は冪等（既存なら 200、新規なら 201）
- アイテム削除後に position が自動整合

## エンドポイント

| Method | Path | 説明 |
|---|---|---|
| `POST` | `/collections` | コレクション作成 |
| `GET` | `/collections/{id}` | コレクション取得（公開 or 自分） |
| `PUT` | `/collections/{id}` | コレクション名・公開設定変更 |
| `DELETE` | `/collections/{id}` | コレクション削除 |
| `POST` | `/collections/{id}/items` | 記事を追加（冪等） |
| `DELETE` | `/collections/{id}/items/{articleId}` | 記事を削除 |

## データベース設計

```sql
CREATE TABLE collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,  -- 0=private, 1=public
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    added_at TEXT NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## 存在非公開パターン（IDOR 防止）

非公開コレクションへのアクセスは 403 でなく **404** を返す。
403 を返すと「存在するが権限がない」という情報が漏れるため。

```php
if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

## 冪等アイテム追加

```php
$existing = $this->repository->findItem($id, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create(['message' => 'already in collection', 'article_id' => $articleId], 200);
}

$count = $this->repository->countItems($id);
if ($count >= CollectionRepository::maxItems()) {
    return $this->responseFactory->create(['error' => 'collection is full', 'max' => 50], 422);
}

$this->repository->addItem($id, $articleId, date('c'));
return $this->responseFactory->create(['message' => 'article added', 'article_id' => $articleId], 201);
```

上限チェックは「まだ追加されていない記事」の場合のみ実行する。
既に追加済みの記事は上限に影響しない（冪等呼び出し）。

## アイテム削除後の位置整合

```php
public function removeItem(int $collectionId, int $articleId): void
{
    $item = $this->findItem($collectionId, $articleId);
    $removedPosition = (int) $item['position'];
    $this->executor->execute('DELETE FROM collection_items WHERE collection_id = ? AND article_id = ?', ...);
    $this->executor->execute(
        'UPDATE collection_items SET position = position - 1 WHERE collection_id = ? AND position > ?',
        [$collectionId, $removedPosition]
    );
}
```

削除された位置より後ろのアイテムを 1 つ前にシフトしてギャップを埋める。

## GET /collections/{id} レスポンス例

```json
{
  "id": 1,
  "user_id": 1,
  "name": "My Reading List",
  "is_public": false,
  "item_count": 2,
  "items": [
    {"article_id": 3, "title": "Article 3", "position": 1, "added_at": "2026-05-21T..."},
    {"article_id": 1, "title": "Article 1", "position": 2, "added_at": "2026-05-21T..."}
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## 所有権チェックパターン

全ての変更エンドポイント（PUT/DELETE/POST items）でオーナーチェックを行う:

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

GET の場合は公開/非公開と所有権で 404/200 を分岐させ、403 を使わない。
