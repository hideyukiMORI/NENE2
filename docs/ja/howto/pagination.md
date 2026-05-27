# ページネーション

リストエンドポイントのページネーションには 2 つのパターンがあります: **OFFSET** と**カーソル**（キーセット）。データ量と UI 要件に基づいて選択してください。

## クイック比較

| | OFFSET | カーソル |
|---|---|---|
| 実装 | シンプル | 中程度（fetch+1 パターン） |
| 合計件数 | `COUNT(*)` が必要 | 不要 |
| 深いページの速度 | 線形に低下 | 一定（インデックスシーク） |
| ページ番号 UI | 簡単 | 困難 |
| 無限スクロール / フィード | 不安定（行のドリフト） | 安定 |
| 閲覧中のデータ変更 | 行のドリフトが発生 | 安定 |

**目安**: 管理テーブルとページ番号付き小規模データセットには OFFSET を使ってください。フィード、無限スクロール、10,000 行以上のテーブルにはカーソルを使ってください。

## OFFSET ページネーション

```php
private function listByOffset(ServerRequestInterface $request): ResponseInterface
{
    $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

    $items = $repo->fetchAll(
        'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset],
    );
    $total = $repo->fetchOne('SELECT COUNT(*) AS cnt FROM articles', [])['cnt'] ?? 0;

    return $json->create([
        'items'       => $items,
        'limit'       => $limit,
        'offset'      => $offset,
        'total'       => (int) $total,
        'has_more'    => ($offset + $limit) < (int) $total,
        'next_offset' => ($offset + $limit) < (int) $total ? $offset + $limit : null,
    ]);
}
```

**OFFSET が遅くなる理由**: データベースはオフセット前のすべての行をスキャンして破棄する必要があります。`OFFSET 5000` の場合、エンジンは 5001 行を読み取り、最初の 5000 行を捨てます。SQLite で確認できます:

```sql
EXPLAIN QUERY PLAN
SELECT * FROM articles ORDER BY id DESC LIMIT 20 OFFSET 5000;
-- SCAN articles USING INDEX idx_articles_id_desc
-- スキャンは依然として 5020 行に触れます。
```

## カーソルページネーション

カーソルは最後に見た行の `id` です。各ページは（降順の場合）`WHERE id < cursor` を使ってカーソル「前」の行を取得します。これによりインデックスがシーク付きでサービスします — カーソル前の行は一切触れません。

```php
private function listByCursor(ServerRequestInterface $request): ResponseInterface
{
    $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $afterId = QueryStringParser::int($request, 'after'); // null = 最初のページ

    // fetch+1 パターン: COUNT クエリなしで has_more を検出
    $fetch = $limit + 1;

    if ($afterId === null) {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    } else {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // 余分なセンチネル行を破棄する
    }

    $nextCursor = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;

    return $json->create([
        'items'       => $rows,
        'limit'       => $limit,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
}
```

### fetch+1 パターン

`COUNT(*)` を発行せずに次のページがあるかを知るには:

1. `limit + 1` 行をリクエストします。
2. 結果が `limit` より多い行を持つ場合、次のページがあります。
3. 返す前に最後の行を破棄します（`array_pop`）。
4. 残った最後の行の `id` を `next_cursor` として使います。

これは常に 1 行余分に取得するコストで追加クエリを回避します。

### クライアントの使用方法

```
GET /articles/cursor?limit=20
→ { items: [...20 件], has_more: true, next_cursor: 42 }

GET /articles/cursor?limit=20&after=42
→ { items: [...20 件], has_more: true, next_cursor: 22 }

GET /articles/cursor?limit=20&after=22
→ { items: [...2 件], has_more: false, next_cursor: null }
```

## リミットのクランプ

無制限のクエリを防ぐために常に limit を適切な範囲にクランプしてください:

```php
$limit = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
```

これは `1〜100` を受け付け、パラメーターが不在の場合は `20` をデフォルトとします。

## OFFSET からカーソルへの切り替え時期

テーブルサイズと典型的なページ深度に基づいた大まかなガイドライン:

| 行数 | 典型的な深度 | 推奨 |
|---|---|---|
| < 10,000 | 任意 | どちらでも可; OFFSET がシンプル |
| 10,000〜100,000 | 浅い（1〜5 ページ） | どちらでも; ソートカラムにインデックスを追加 |
| 10,000〜100,000 | 深い（10 ページ以上） | カーソル推奨 |
| > 100,000 | 任意 | カーソルを強く推奨 |

どちらのアプローチを使う場合でも、ソートカラムにインデックスを追加してください:

```sql
CREATE INDEX idx_articles_id_desc ON articles (id DESC);
```

## 同じ位置での結果の比較

OFFSET からカーソルに移行する場合、両方の方法で同じ「ウィンドウ」の行を取得して正確性を検証してください:

```php
// OFFSET: 行 11〜20 (0 インデックスの offset=10)
$offsetPage = $get('/articles/offset?limit=10&offset=10');

// カーソル: 位置 10 の id を取得し（offset=9）、アンカーとして使う
$anchor     = $get('/articles/offset?limit=1&offset=9');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $get("/articles/cursor?limit=10&after={$anchorId}");

// これらは同一であるべき
assert(array_column($offsetPage['items'], 'id') === array_column($cursorPage['items'], 'id'));
```
