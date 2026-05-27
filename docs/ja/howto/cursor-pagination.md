# ハウツー: カーソルベースページネーション

> **FT リファレンス**: FT242 (`NENE2-FT/cursorlog`) — カーソルベースページネーション API

オフセットページネーションの代替として、カーソルベース（キーセット）ページネーションを実演します。ID ベースのカーソル（`WHERE id < ?`）を使ってアイテムを取得し、`limit+1` のトリックで COUNT クエリなしに `has_more` を検出し、レスポンスに次のリクエストで呼び出し元が渡す `next_cursor` 値を含めます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------------|----------------------------------------------|
| `POST` | `/posts`   | 投稿を作成する |
| `GET`  | `/posts`   | カーソルページネーションで投稿を一覧表示する |
| `GET`  | `/posts/{id}` | 単一の投稿を取得する |

---

## オフセット vs カーソルページネーション

| 懸念事項 | オフセット（`LIMIT ? OFFSET ?`） | カーソル（`WHERE id < ? ORDER BY id`） |
|---------------------------|------------------------------------------|--------------------------------------------|
| 大規模データでのパフォーマンス | 劣化 — DB が N 行をスキップする必要がある | 一定 — インデックスシークでカーソル位置へ |
| 安定した結果 | 新しい行が後続ページをシフトする | 安定 — 特定の行に固定される |
| ランダムアクセス | サポートあり（`?page=5`） | サポートなし（前方のみ） |
| 総件数 | 別途 `COUNT(*)` クエリが必要 | 総件数不要（`has_more` フラグを使用） |
| カーソル型 | 整数オフセット（位置ベース） | 行識別値（ID ベース） |

カーソルページネーションは、オフセットのずれ（ページ間に新しいアイテムが挿入されると行が重複したり欠落したりする）が問題になる高ボリュームのリアルタイムフィードに推奨されます。

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_id ON posts(id DESC);
```

`id` の降順インデックスが `ORDER BY id DESC` を効率的にサポートします。SQLite の `INTEGER PRIMARY KEY` はすでに `rowid` のエイリアスですが、明示的なインデックスにより主キー単独より範囲クエリが高速化されます。

---

## カーソルロジック: `WHERE id < ? ORDER BY id DESC LIMIT ?`

リポジトリは 1 つ余分な行（`limit + 1`）を取得して、さらにページが存在するかを検出します:

```php
/**
 * 降順 ID で投稿のページを取得する。
 *
 * @param int|null $afterCursor  最後に見た投稿の ID; afterCursor より id の小さい投稿を返す
 * @param int      $limit        返す最大アイテム数（100 でキャップ）
 */
public function paginate(?int $afterCursor, int $limit): CursorPage
{
    $limit = max(1, min(100, $limit));
    // 次ページが存在するかを検出するために 1 つ余分に取得
    $fetch = $limit + 1;

    if ($afterCursor !== null) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterCursor, $fetch],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);   // 余分な行を破棄
    }

    $items      = array_map(fn (array $row): Post => $this->hydrate($row), $rows);
    $nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;

    return new CursorPage($items, $nextCursor, $hasMore);
}
```

主要なステップ:
1. **limit をクランプ**: `max(1, min(100, $limit))` — 0 行または暴走クエリを防ぐ。
2. **`limit + 1` を取得**: `$limit` より多い行が返ってきた場合、次のページが存在する。
3. **余分な行を削除**: `array_pop($rows)` で検出にのみ使った（limit+1）番目の行を破棄する。
4. **`nextCursor` を計算**: 最後のアイテムの `id` が次に呼び出し元が送るカーソルとなる。
5. **`$hasMore = false`** は `$nextCursor === null` のとき — それ以上ページがない。

最初のページにはカーソルがありません（`$afterCursor === null`）、最新の投稿を返します。
後続の各リクエストは `?cursor=<nextCursor>` を送信して、中断したところから続けます。

---

## `CursorPage` 値オブジェクト

```php
final readonly class CursorPage
{
    /** @param list<Post> $items */
    public function __construct(
        public array $items,
        public ?int  $nextCursor,
        public bool  $hasMore,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'       => array_map(static fn (Post $p): array => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_more'    => $this->hasMore,
        ];
    }
}
```

`next_cursor` は最後のページでは `null`（これ以上アイテムがない）。`has_more` はこれを反映します: `next_cursor` が設定されている場合は `true`、最後のページでは `false`。呼び出し元は `has_more === false` または `next_cursor === null` のときに停止します。

レスポンス形状:
```json
{
    "items": [...],
    "next_cursor": 42,
    "has_more": true
}
```

---

## コントローラー: カーソルの読み取りとバリデーション

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $query       = $request->getQueryParams();
    $limit       = isset($query['limit']) ? (int) $query['limit'] : 10;
    $cursorRaw   = isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null;
    $afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

    $page = $this->repo->paginate($afterCursor, $limit);

    return $this->json->create($page->toArray());
}
```

`ctype_digit($cursorRaw)` は `int` にキャストする前にカーソル文字列を検証します:
- `ctype_digit()` は空文字列、負の符号、浮動小数点、非数値文字列に対して `false` を返します — すべて「カーソルなし」（最初のページ）として扱われます。
- 無効なカーソルは `400` ではなく最初のページにフォールバックします — 古いまたは不正なカーソルを渡した呼び出し元は `400` ではなく最初のページを受け取ります。

これは実用的な選択です: 無効なカーソルはサイレントに不在として扱われます。より厳格な API のためには、`$cursorRaw` が非 null だが `ctype_digit()` に失敗する場合に `422 Unprocessable Entity` を返してください。

---

## limit クランプ

```php
$limit = max(1, min(100, $limit));
```

- 最小 `1`: ゼロ行クエリを防ぐ。
- 最大 `100`: 暴走フェッチを避けるためにページサイズをキャップする。

クランプはコントローラーではなくリポジトリで行われ、`paginate()` の呼び出し元が制限を迂回できないようにします。コントローラーは `$query['limit']` を読み取り、不在の場合はデフォルト `10` を使用します。

---

## ページネーションコントラクトまとめ

| クエリパラメーター | 型 | デフォルト | 動作 |
|---|---|---|---|
| `?limit=N` | 整数 | 10 | ページあたりアイテム数（1〜100 でクランプ） |
| `?cursor=ID` | 整数文字列 | 不在 | `id < ID` のアイテムを取得; 不在 = 最初のページ |

| レスポンスフィールド | 型 | 意味 |
|---|---|---|
| `items` | 配列 | このページのシリアライズされたアイテム |
| `next_cursor` | int \| null | 次のリクエストで `?cursor=` として渡す; `null` = 最後のページ |
| `has_more` | bool | さらにページが存在する場合は `true` |

---

## オフセットページネーションとの比較

NENE2 の `PaginationQueryParser` / `PaginationResponse` ビルトインは `LIMIT ? OFFSET ?` を使用します。
以下の場合はそちらを使用してください:
- ランダムページアクセスが必要な場合（`?page=5`）。
- 総アイテム数をユーザーに表示する場合。
- データセットが小さく、トラバーサル中にほとんど増加しない場合。

以下の場合はカーソルページネーションを使用してください:
- フィードデータが継続的に増加する場合（チャット、アクティビティストリーム、ログ）。
- 挿入負荷下で安定したトラバーサルが必要な場合。
- `OFFSET N` が遅くなるほどデータセットが大きい場合。

---

## 関連ハウツー

- [`pagination.md`](pagination.md) — `PaginationQueryParser` と `PaginationResponse` を使ったオフセットベースページネーション
- [`activity-feed.md`](activity-feed.md) — リアルタイムフィードパターン
- [`add-pagination.md`](add-pagination.md) — 既存エンドポイントへのページネーション追加
