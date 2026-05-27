# スレッド化コメント

深さ制限とソフトデリートを持つ自己参照コメントスレッドを実装します。

## 概要

スレッド化コメントシステムは自分自身を参照する 1 つのテーブルを持ちます。各コメントは `parent_id`（トップレベルの場合は null）、`depth`（0 ベース）、`status` を知っています。返信はレスポンスツリーで親の中にネストされます。

## データベーススキーマ

```sql
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
```

`depth` は各挿入時の再帰的な祖先クエリを避けるために行に非正規化されます。

## 最大深さ

書き込み時に深さ制限を強制してください:

```php
public const int MAX_DEPTH = 3;

public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

ルートハンドラーで挿入前にチェックしてください:

```php
if (!$parent->canHaveReplies()) {
    return $this->problems->create($request, 'unprocessable-entity', 'Maximum comment depth reached.', 422, '');
}

$comment = $this->repo->addComment(
    $parent->postId, $parentId, $authorName, $body, $parent->depth + 1, $now,
);
```

## ソフトデリート

ソフトデリートはボディを `[deleted]` に置換し、`status = 'deleted'` を設定します。子コメントは保持されます:

```php
public function softDelete(int $id): void
{
    $this->executor->execute(
        "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
        [$id],
    );
}
```

ツリーの取得は `[deleted]` ボディを持つ削除済みコメントを返すため、スレッド構造は一貫性を保ちます — 読者は削除されたコメントがあった場所にプレースホルダーを見て、その子コメントは引き続き表示されます。

削除済みコメントへの返信を試みると 409 が返されます:

```php
if ($parent->isDeleted()) {
    return $this->problems->create($request, 'conflict', 'Cannot reply to a deleted comment.', 409, '');
}
```

## N+1 なしでコメントツリーを構築する

単一クエリで投稿のすべてのコメントを ID でソートして読み込みます（親は常に子より低い ID を持ちます）。その後、2 回のパスで PHP でツリーを組み立てます:

```php
// パス 1: 生の行マップと子 ID 隣接リストを構築
foreach ($rows as $row) {
    $rowMap[(int) $row['id']]   = $row;
    $childIds[(int) $row['id']] = [];
}

foreach ($rowMap as $id => $row) {
    $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $roots[] = $id;
    } elseif (isset($childIds[$parentId])) {
        $childIds[$parentId][] = $id;
    }
}

// パス 2: ルートから再帰的に Comment 値オブジェクトを構築
return $this->buildTree($roots, $rowMap, $childIds);
```

生の行と `int[]` 子 ID リストを `Comment` 値オブジェクトとは分離して保持することで、`readonly` クラスを扱う際の PHPStan 型の混乱を避けられます。

## 行データと値オブジェクトの分離

再帰的に readonly 値オブジェクトのツリーを組み立てる際、PHPStan はクリーンな型境界を必要とします。機能するパターン:

1. **パス 1** — 生の行から `array<int, array<string, mixed>> $rowMap` と `array<int, int[]> $childIds` を構築します。まだ値オブジェクトはありません。
2. **パス 2** — `buildTree()` は `int[]` ID と 2 つのマップのみを受け取り、再帰して完全に組み立てられた子配列を持つ `Comment` オブジェクトをハイドレートします。

これにより、同じ配列に `Comment` オブジェクトと `int` ID が混在するのを避けられ、PHPStan が絞り込めないユニオン型を防ぎます。

## ルートサマリー

| メソッド | パス | 説明 |
|--------|------|------|
| `POST`   | `/posts`                | 投稿を作成する                 |
| `GET`    | `/posts/{id}`           | 投稿を取得する                 |
| `POST`   | `/posts/{id}/comments`  | トップレベルコメントを追加する |
| `GET`    | `/posts/{id}/comments`  | コメントツリーを取得する       |
| `POST`   | `/comments/{id}/replies`| コメントに返信する             |
| `DELETE` | `/comments/{id}`        | コメントをソフトデリートする   |

## 設計上の注意

- `depth` は各挿入時の再帰的な祖先クエリを避けるために行に保存されます（非正規化）。
- `ORDER BY id ASC` はフラットリストを読み込む際に親が子より前に現れることを保証します。
- ソフトデリートはスレッド構造を保持します — ハードデリートは子コメントを孤立させます。
- 削除済みコメントへの返信はブロックされます（409）、ゴーストスレッドを防ぐためです。
