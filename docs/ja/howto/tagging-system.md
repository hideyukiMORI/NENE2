# タグ付けシステム（M:N）

多対多の結合テーブルを使って投稿にタグを添付し、アトミックなタグ置換と N+1 のないタグ取得を実現します。

## 概要

タグ付けシステムには `posts`、`tags`、`post_tags`（結合テーブル）の 3 つのテーブルがあります。投稿とタグは M:N の関係です — 1 つの投稿が多くのタグを持ち、1 つのタグが多くの投稿に属します。

## データベーススキーマ

```sql
CREATE TABLE posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);

CREATE TABLE tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE post_tags (
    post_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (tag_id)  REFERENCES tags(id)
);
```

`(post_id, tag_id)` の複合主キーは DB レベルで一意性を強制します。

## アトミックなタグ設定

`PUT /posts/{id}/tags` エンドポイントは 1 回の操作で投稿のすべてのタグを置き換えます。最初に削除してから挿入します:

```php
public function setPostTags(int $postId, array $tagNames): array
{
    $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

    foreach ($tagNames as $name) {
        $tag = $this->findTagByName($name);
        if ($tag === null) {
            continue; // 不明なタグ名はサイレントにスキップ
        }

        $this->executor->execute(
            'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
            [$postId, $tag->id],
        );
    }

    return $this->findTagsByPostId($postId);
}
```

- 削除してから挿入することで操作が冪等になります: 同じペイロードで 2 回呼び出しても同じ結果になります。
- `INSERT OR IGNORE` はリクエストボディに同じタグ名が 2 回出現しても DB エラーを防ぎます。
- 不明なタグ名はサイレントにスキップされます — クライアントはタグを割り当てる前に作成する必要があります。
- すべてのタグをクリアするには `{"tags": []}` を送信してください。

## N+1 クエリの回避

投稿のリストを読み込む際（例: タグベース検索）、投稿ごとに 1 つのクエリを実行するのではなく、1 つの `IN` クエリですべてのタグを取得してください:

```php
private function findTagsByPostIds(array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $rows = $this->executor->fetchAll(
        "SELECT t.*, pt.post_id FROM tags t
         INNER JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id IN ({$placeholders})
         ORDER BY t.name ASC",
        $postIds,
    );

    $map = [];
    foreach ($rows as $row) {
        $postId         = (int) $row['post_id'];
        $map[$postId][] = $this->hydrateTag($row);
    }

    return $map;
}
```

これは投稿 ID をキーとした `array<int, Tag[]>` を返します。投稿数に関わらず合計 2 つのクエリで済みます。

## タグの一意性

タグは `name` に `UNIQUE` 制約があります。重複作成は 409 を返します:

```php
public function createTag(string $name, string $now): ?Tag
{
    try {
        $this->executor->execute(
            'INSERT INTO tags (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    } catch (\RuntimeException) {
        return null; // null → ハンドラーが 409 を返す
    }

    return $this->findTagByName($name);
}
```

## タグベース検索

JOIN を使ってタグで投稿をフィルタリングします:

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
INNER JOIN tags t ON t.id = pt.tag_id
WHERE t.name = ?
ORDER BY p.id DESC
```

その後、上記の `IN` クエリで結果セットのタグを一括読み込みします。

## ルートサマリー

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/posts`              | 投稿を作成する                  |
| `GET`  | `/posts/{id}`         | タグ付きの投稿を取得する        |
| `POST` | `/tags`               | タグを作成する（重複 → 409）    |
| `GET`  | `/tags`               | すべてのタグを一覧表示する（アルファベット順） |
| `PUT`  | `/posts/{id}/tags`    | 投稿のすべてのタグを置き換える  |
| `GET`  | `/tags/{name}/posts`  | タグが付いた投稿を一覧表示する  |

## 設計上の注意

- タグはアプリケーション管理のエンティティであり、フリーテキストではありません。クライアントはまずタグを作成してから割り当てます。
- `PUT /posts/{id}/tags` の不明なタグ名はサイレントに無視されます。これにより名前を事前に検証するための往復が不要になります。
- タグ名はレスポンスで決定論的な出力のためにアルファベット順にソートされます。
- `GET /tags/{name}/posts` はタグが存在しない場合に 404 を返し、「タグが不明」と「タグは存在するが投稿がない」（200 で空配列を返す）を区別します。
