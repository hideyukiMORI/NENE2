# ハウツー: マルチ値タグフィルター API

> **FT リファレンス**: FT250 (`NENE2-FT/tagfilterlog`) — マルチ値クエリパラメーターによるタグフィルタリング

正規化された M:N 結合テーブルを使った投稿 API でのマルチタグフィルタリングを実証します。AND セマンティクス（**すべての**指定タグを持つ投稿）と OR セマンティクス（**いずれかの**指定タグを持つ投稿）をサポートし、2 つのクライアントサイドクエリ形式に対応します: カンマ区切り（`?tags=php,api`）と PHP スタイル配列（`?tags[]=php&tags[]=api`）。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/posts` | オプションのタグ配列付きで投稿を作成する |
| `GET` | `/posts` | 投稿を一覧表示する（タグ、AND または OR でフィルタ可能） |
| `GET` | `/posts/{id}` | タグ付きで単一投稿を取得する |

---

## スキーマ: M:N 結合テーブル

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS post_tags (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag     TEXT    NOT NULL,
    PRIMARY KEY (post_id, tag)
);

CREATE INDEX IF NOT EXISTS idx_post_tags_tag ON post_tags(tag);
```

`PRIMARY KEY(post_id, tag)` は複合主キーです — 一意性を強制しつつ `(post_id, tag)` のインデックスとしても機能します。`tag` 単独の個別インデックスにより `post_id` に関係なく `WHERE tag IN (...)` ルックアップを効率化します。

**代替: JSON カラムアプローチ**

タグは `posts` テーブルの TEXT カラムに JSON 配列として保存することもできます: `tags TEXT NOT NULL DEFAULT '[]'`。これはシンプルです（JOIN なし）が、インデックス付きタグルックアップをサポートせず、フィルタリングには `json_each()` または `json_extract()` が必要です。タグ検索パフォーマンスが重要な場合は M:N 結合テーブルが推奨されます。

---

## 作成: タグの重複排除とアルファベット順ソート

```php
$rawTags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags    = array_values(array_filter(array_map(
    static fn (mixed $t): string => is_string($t) ? trim($t) : '',
    $rawTags,
), static fn (string $s): bool => $s !== ''));
```

タグはリクエストから抽出され、トリムされ、空でない文字列にフィルタリングされます。非文字列値（数値、null）は空文字列に強制変換されて削除されます。

トランザクション内で:

```php
$this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($title, $body, $tags, $now): Post {
    $id = $tx->insert(
        'INSERT INTO posts (title, body, created_at) VALUES (?, ?, ?)',
        [$title, $body, $now],
    );

    // タグを重複排除してソート
    $uniqueTags = array_values(array_unique($tags));
    sort($uniqueTags);

    foreach ($uniqueTags as $tag) {
        $tx->insert('INSERT OR IGNORE INTO post_tags (post_id, tag) VALUES (?, ?)', [$id, $tag]);
    }

    return new Post($id, $title, $body, $uniqueTags, $now);
});
```

`array_unique()` + `sort()` は書き込み前に PHP で重複排除とアルファベット順化を行います。`INSERT OR IGNORE` は第 2 層の防御です — 複合 PK 制約が発火した場合（例: 並行書き込み）、例外をスローせずに挿入をスキップします。

レスポンスはタグをソート済みの順序で返すため、呼び出し元は常に安定したリストを見ます。

---

## AND フィルター: `HAVING COUNT(DISTINCT tag) = N`

```php
public function findByAllTags(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         GROUP BY p.id
         HAVING COUNT(DISTINCT pt.tag) = CAST(? AS INTEGER)
         ORDER BY p.created_at DESC",
        [...$tags, count($tags)],
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`WHERE pt.tag IN (...)` は少なくとも 1 つの一致するタグを持つ行に絞り込みます。`GROUP BY p.id HAVING COUNT(DISTINCT pt.tag) = N` は **すべての** N タグに一致した投稿のみを選択します。

**`CAST(? AS INTEGER)` が必要**: PDO はデフォルトですべてのパラメーターを文字列としてバインドします。SQLite では `COUNT(...)` (整数) を `'2'` (文字列) と比較することは単純なケースでは機能しますが、明示的なキャストがより安全で意図をドキュメント化します。

---

## OR フィルター: `SELECT DISTINCT`

```php
public function findByAnyTag(array $tags): array
{
    if ($tags === []) {
        return $this->findAll();
    }

    $placeholders = implode(',', array_fill(0, count($tags), '?'));
    $rows         = $this->executor->fetchAll(
        "SELECT DISTINCT p.* FROM posts p
         INNER JOIN post_tags pt ON pt.post_id = p.id
         WHERE pt.tag IN ({$placeholders})
         ORDER BY p.created_at DESC",
        $tags,
    );

    return array_map($this->hydrateWithTags(...), $rows);
}
```

`SELECT DISTINCT` は投稿が IN リストの複数タグにマッチした場合の重複行を防止します。`HAVING` 句は不要です — 単一のマッチするタグで投稿が条件を満たします。

---

## デュアルクエリパラメーター形式

一覧エンドポイントは異なるクライアントに対応するために 2 つの形式でタグを受け付けます:

| 形式 | 例 | ソース |
|------|------|--------|
| カンマ区切り | `?tags=php,api` | `QueryStringParser::commaSeparated()` |
| PHP 配列スタイル | `?tags[]=php&tags[]=api` | PSR-7 `getQueryParams()` |

```php
private function extractTags(ServerRequestInterface $request): array
{
    // 戦略 1: カンマ区切り（NENE2 ネイティブ）
    $csv = QueryStringParser::commaSeparated($request, 'tags');
    if ($csv !== null) {
        return $csv;
    }

    // 戦略 2: PHP スタイル配列パラメーター（?tags[]=php&tags[]=api）
    // PSR-7 の getQueryParams() は PHP 配列構文をネイティブにパースする。
    // NENE2 の QueryStringParser にはこれ用のヘルパーがない — 生のアクセスを使う。
    $params   = $request->getQueryParams();
    $arrayVal = $params['tags'] ?? null;

    if (is_array($arrayVal)) {
        /** @var list<string> $filtered */
        $filtered = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $arrayVal),
            static fn (string $s): bool => $s !== '',
        ));

        return $filtered;
    }

    return [];
}
```

`QueryStringParser::commaSeparated()` は `?tags=php,api` を処理し、パラメーターが不在の場合は `null` を返します。`null` の場合、フォールバックは `getQueryParams()['tags']` をチェックします。PSR-7 実装は `?tags[]=php&tags[]=api` を PHP 配列としてパースします。

mode パラメーターで AND vs OR を選択します:

```php
$mode  = QueryStringParser::string($request, 'mode') ?? 'all'; // 'all' = AND、'any' = OR
$posts = $mode === 'any'
    ? $this->repository->findByAnyTag($tags)
    : $this->repository->findByAllTags($tags);
```

不明な `mode` の値は AND にフォールスルーします（より安全なデフォルト — より少ない結果）。

---

## ハイドレーション: 投稿ごとに N+1 クエリ

```php
private function hydrateWithTags(array $row): Post
{
    $tagRows = $this->executor->fetchAll(
        'SELECT tag FROM post_tags WHERE post_id = ? ORDER BY tag ASC',
        [(int) $row['id']],
    );

    $tags = array_map(static fn (array $r): string => (string) $r['tag'], $tagRows);

    return new Post(
        id:        (int) $row['id'],
        title:     (string) $row['title'],
        body:      (string) $row['body'],
        tags:      $tags,
        createdAt: (string) $row['created_at'],
    );
}
```

これはタグをロードするために投稿ごとに 1 つの追加クエリを実行します。小さなデータセットではこれで十分です。大きな結果セットには、単一の `GROUP_CONCAT` または `json_group_array` クエリに置き換えてください:

```sql
SELECT p.*, GROUP_CONCAT(pt.tag ORDER BY pt.tag) AS tags_csv
FROM posts p
LEFT JOIN post_tags pt ON pt.post_id = p.id
GROUP BY p.id
ORDER BY p.created_at DESC
```

次に PHP の `explode(',', ...)` で `tags_csv` を分割してください。SQLite の `GROUP_CONCAT` は集計内に `ORDER BY` がないと順序を保証しません（SQLite 3.39+ は `GROUP_CONCAT` 内の `ORDER BY` をサポートします）。

---

## AND vs OR の比較

| モード | SQL パターン | `[php, api]` vs `[php]` vs `[js]` の投稿 |
|------|-------------|------------------------------------------|
| AND (`mode=all`) | `HAVING COUNT(DISTINCT tag) = N` | `[php, api]` のみが `?tags=php,api` にマッチ |
| OR (`mode=any`) | `SELECT DISTINCT` | `[php, api]` と `[php]` の両方が `?tags=php,api` にマッチ |
| タグなし | フィルターなし | すべての投稿を返す |

空のタグリスト（`tags=[]` または `tags` が不在）は両方のモードで常にすべての投稿を返します。

---

## 関連 howto

- [`tagging-system.md`](tagging-system.md) — エンティティスコープの M:N 関係によるタグ/ラベル管理
- [`tag-label-api.md`](tag-label-api.md) — タグエンティティ CRUD とリストフィルタリングを持つタグ分類
- [`note-management-with-tags.md`](note-management-with-tags.md) — オーナースコープを持つノートタグ
- [`cursor-pagination.md`](cursor-pagination.md) — タグフィルターとのカーソルページネーションの組み合わせ
