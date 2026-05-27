# ブックマークシステム

ユーザーが名前付きコレクションにアイテムを保存できるようにします。ブックマークは冪等です — 同じアイテムを 2 回ブックマークするとエラーなしに既存のブックマークを返します。

## 概要

ブックマークシステムは以下を含みます:
- **ブックマーク追加** — ユーザーのコレクションにアイテムを保存する（冪等）
- **ブックマーク削除** — 保存済みブックマークを削除する（見つからない場合は 404）
- **ブックマーク一覧** — ユーザーのすべてのブックマーク（コレクションでオプションフィルタリング）
- **ブックマーク数** — 軽量なバッジカウンター
- **ブックマーク取得** — 特定のアイテムがブックマーク済みかどうかを確認する

## データベーススキーマ

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

`UNIQUE (user_id, item_id)` によりユーザーごと・アイテムごとに 1 つのブックマークを強制します。`collection` フィールドはブックマークを `'default'` をフォールバックとした名前付きカテゴリにグループ化します。

## 冪等な追加

挿入前に既存のブックマークを確認します。競合（レース条件）の場合は `DatabaseConstraintException` をキャッチして既存のレコードを返します:

```php
public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
{
    $existing = $this->find($userId, $itemId);

    if ($existing !== null) {
        return $existing;  // すでにブックマーク済み — エラーではない
    }

    try {
        $this->executor->execute(
            'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $collection, $now],
        );
    } catch (DatabaseConstraintException) {
        // レース条件 — 別のリクエストが先に完了した；既存のブックマークを返す
        $found = $this->find($userId, $itemId);
        if ($found !== null) {
            return $found;
        }
    }

    $id = (int) $this->executor->lastInsertId();
    return new Bookmark($id, $userId, $itemId, $collection, $now);
}
```

チェック後挿入パターンが一般的なケースを効率的に処理します。`DatabaseConstraintException` のキャッチが同時リクエスト下のレース条件を処理します。

## コレクションフィルタリング

オプションの `collection` クエリパラメーターを使用してブックマークをフィルタリングします:

```php
// GET /users/{userId}/bookmarks?collection=reading
$collection = isset($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

`null` のコレクションはすべてのブックマークを返し；空でない文字列はそのコレクションにフィルタリングします。

## 削除で 204 vs 404 を返す

- `204 No Content` — ブックマークが存在して削除された
- `404 Not Found` — ブックマークが存在しなかった

```php
$removed = $this->repo->remove($userId, $itemId);

if (!$removed) {
    return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
}

return $this->responseFactory->createEmpty(204);
```

`execute()` は影響を受けた行数を返します — ゼロはブックマークが見つからなかったことを意味します。

## MySQL スキーマ

MySQL では明示的な `ENGINE=InnoDB` と `AUTO_INCREMENT` 構文が必要です:

```sql
CREATE TABLE bookmarks (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    item_id    INT          NOT NULL,
    collection VARCHAR(100) NOT NULL DEFAULT 'default',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

MySQL 統合テストでは、FK 依存関係の順序問題を避けるためにテーブルを削除する前に `SET FOREIGN_KEY_CHECKS = 0` を実行します。

## MySQL 統合テストパターン

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    ...
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
    $this->pdo->exec('DROP TABLE IF EXISTS items');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schema = (string) file_get_contents('.../database/schema.mysql.sql');
    $this->pdo->exec($schema);
}

protected function tearDown(): void
{
    if ($this->mysqlEnabled && $this->pdo !== null) {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

## セキュリティ特性

| 特性 | 実装 |
|---|---|
| ユーザーごと・アイテムごとに 1 つのブックマーク | `UNIQUE (user_id, item_id)` DB 制約 |
| 追加時のレース条件 | `DatabaseConstraintException` のキャッチ → 既存を返す |
| ユーザー分離 | すべてのクエリが `user_id` でフィルタリング |
| 存在しないものの削除 | 404 を返す（サイレントではない） |

## ルートまとめ

| メソッド | パス | 説明 |
|---|---|---|
| `POST` | `/users` | ユーザーを作成する |
| `POST` | `/items` | アイテムを作成する |
| `POST` | `/users/{userId}/bookmarks` | ブックマークを追加する（冪等） |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | ブックマークを削除する（204 または 404） |
| `GET` | `/users/{userId}/bookmarks` | ブックマークを一覧表示する（`?collection=` フィルター） |
| `GET` | `/users/{userId}/bookmarks/count` | ブックマーク総数 |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | 単一ブックマーク状態を取得する |
