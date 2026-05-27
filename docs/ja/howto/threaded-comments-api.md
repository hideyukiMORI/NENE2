# ハウツー: スレッド化コメント API

> **FT リファレンス**: FT343 (`NENE2-FT/threadlog`) — トゥームストーン削除（コンテンツを `[deleted]` に置換）、返信の深さ制限、投稿スコープの分離、削除済みへの返信防止を持つ 2 レベルスレッドコメントシステム、14 テスト / 40+ アサーション PASS。

このガイドでは、1 レベルの返信を持つコメントシステムの構築方法を説明します: ルートコメントは返信を受け取れますが、返信には返信できません（最大深さ = 1）。削除されたコメントはトゥームストーン化され、スレッド構造が保持されます。

## スキーマ

```sql
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT    NOT NULL,           -- 不透明な投稿識別子
    parent_id  INTEGER REFERENCES comments(id),  -- NULL = ルートコメント
    author     TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL
);
```

`parent_id IS NULL` = ルートコメント; `parent_id IS NOT NULL` = 返信。

## エンドポイント

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/posts/{postId}/comments`             | ルートコメントを作成する |
| `GET`    | `/posts/{postId}/comments`             | 返信付きコメントを一覧表示する |
| `GET`    | `/posts/{postId}/comments/{id}`        | 単一コメントを取得する |
| `POST`   | `/posts/{postId}/comments/{id}/replies`| 返信を追加する |
| `DELETE` | `/posts/{postId}/comments/{id}`        | ソフトデリート（トゥームストーン） |

## ルートコメントの作成

```php
POST /posts/post-1/comments
{"author": "alice", "content": "Great post!"}
→ 201
{
  "id": 1,
  "author": "alice",
  "content": "Great post!",
  "parent_id": null,
  "replies": [],
  "deleted": false,
  "created_at": "..."
}

// フィールドが欠けている
POST /posts/post-1/comments  {"author": "alice"}
→ 422  // content 必須
```

## コメントの一覧表示

```php
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "alice",
      "content": "Root comment",
      "replies": [
        {"id": 2, "author": "bob", "content": "My reply", "parent_id": 1}
      ]
    }
  ]
}
```

コメントは `post_id` にスコープされます。`post-1` のコメントは `post-2` の一覧に表示されません。

## 単一コメントの取得

```php
GET /posts/post-1/comments/1
→ 200
{
  "id": 1,
  "author": "alice",
  "content": "Root comment",
  "reply_count": 2,
  "replies": [...]
}

GET /posts/post-1/comments/999
→ 404
```

## 返信の追加（最大深さ = 1）

```php
POST /posts/post-1/comments/1/replies
{"author": "bob", "content": "My reply"}
→ 201
{
  "id": 2,
  "parent_id": 1,
  "author": "bob",
  "content": "My reply"
}

// 返信への返信は拒否される（深さが 2 になる）
POST /posts/post-1/comments/2/replies  {"author": "charlie", "content": "Deep reply"}
→ 409  // 深さ制限超過

// 存在しないコメントへの返信
POST /posts/post-1/comments/999/replies  {"author": "bob", "content": "X"}
→ 404

// 削除済みコメントへの返信
// （コメント 1 はすでに削除済み）
POST /posts/post-1/comments/1/replies  {"author": "bob", "content": "X"}
→ 409  // 削除済みコメントには返信できない

// フィールドが欠けている → 422
```

### 深さチェックの実装

```php
public function canReceiveReply(int $commentId): bool
{
    $row = $this->findById($commentId);
    if ($row === null) {
        throw new CommentNotFoundException($commentId);
    }
    if ($row['deleted']) {
        throw new CommentDeletedException($commentId);
    }
    // ルートコメント（parent_id = null）のみ返信を受け取れる
    return $row['parent_id'] === null;
}
```

`canReceiveReply()` が false を返した場合は 409 を返してください。

## トゥームストーン削除

```php
DELETE /posts/post-1/comments/1
→ 200
{
  "deleted": true,
  "author": "[deleted]",
  "content": "[deleted]"
}

// 削除済みコメントは一覧に引き続き表示される（トゥームストーン）
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "[deleted]",
      "content": "[deleted]",
      "deleted": true,
      "replies": [{"id": 2, "author": "bob", ...}]  // 返信は引き続き表示
    }
  ]
}
```

トゥームストーン化はスレッド構造を保持します。親が削除された後も返信は表示されます。

```php
// すでに削除済みのコメントを削除する → 404
DELETE /posts/post-1/comments/1  （すでに削除済み）
→ 404

// 不明なコメント → 404
DELETE /posts/post-1/comments/999
→ 404
```

### トゥームストーン SQL

```sql
UPDATE comments
SET deleted = 1, author = '[deleted]', content = '[deleted]', deleted_at = ?
WHERE id = ? AND deleted = 0
-- 削除されていない行のみにマッチ
-- 0 行更新 → 404
```

---

## やってはいけないこと

| アンチパターン | リスク |
|---|---|
| 親コメントをハードデリートする | 返信が孤立する; スレッド構造が壊れる |
| 無制限のネスト深さを許可する | 深いチェーンが再帰的 SQL クエリやスタックオーバーフローを引き起こす |
| 削除済みへの返信に 404 を返す | 親の状態を隠すとクライアントが混乱する; 明確な `detail` を持つ 409 がより良い |
| クエリに `post_id` スコープがない | 他の投稿のコメントが一覧に表示される |
| 深さをクライアントサイドのみでチェックする | 攻撃者が API に直接リクエストを送ることでチェックをバイパスする |
| 削除済みコメントの author/content を表示する | 削除の目的を無効にする; 常にトゥームストーンすること |
