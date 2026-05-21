# Field Trial Report — FT127: Threaded Comments

**Date**: 2026-05-21
**Release**: v1.5.61
**App**: `commentlog` (`/home/xi/docker/NENE2-FT/commentlog/`)
**Tests**: 20/20 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement a threaded comment system with depth limits and soft delete. Comments form a self-referencing tree: each comment has a `parent_id` (null for top-level), a denormalized `depth`, and a `status`. The full tree is loaded with a single query and assembled in PHP using a two-pass adjacency-list approach.

## Core Design

### Depth Denormalization

Rather than computing depth by walking ancestor rows on each insert, `depth` is stored directly in the comment row. A top-level comment has `depth = 0`; a reply inherits `parent.depth + 1`. This trades a tiny write-side redundancy for a clean read path — no recursive query needed.

The limit (`MAX_DEPTH = 3`) is enforced at write time by `Comment::canHaveReplies()`:

```php
public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

At depth 3, `canHaveReplies()` returns false and the handler returns 422.

### Soft Delete Preserves Thread Structure

Hard delete would orphan children and produce gaps in the tree. Instead:

```php
UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?
```

The tree fetch includes deleted nodes with `[deleted]` body so the thread remains coherent. Replying to a deleted comment returns 409 (Conflict) — the thread exists but further replies are blocked.

### Two-Pass Tree Building (PHPStan-Safe)

Loading the tree with a single `SELECT * FROM comments WHERE post_id = ? ORDER BY id ASC` and assembling it in PHP avoids N+1. `ORDER BY id ASC` guarantees parents appear before their children.

The challenge: PHPStan cannot narrow union types when the same array holds both raw rows and partially-assembled value objects. The solution keeps raw data and value objects completely separate:

**Pass 1** — build adjacency structures from raw rows only:
```php
/** @var array<int, array<string, mixed>> $rowMap */
$rowMap = [];
/** @var array<int, int[]> $childIds */
$childIds = [];
```

**Pass 2** — `buildTree()` takes only `int[]` IDs, recurses, and hydrates `Comment` objects with fully-assembled `$children` arrays. No type ambiguity.

### State Machine for Comments

| State | body | canHaveReplies | isDeleted |
|---|---|---|---|
| published | original text | depth < 3 | false |
| deleted | `[deleted]` | false | true |

## Files

```
database/schema.sql
src/Comment/Post.php
src/Comment/Comment.php
src/Comment/CommentRepository.php
src/Comment/RouteRegistrar.php
tests/Comment/CommentTest.php    (20 tests)
docs/howto/threaded-comments.md
```

## Developer Experience (DX) Review

### ペルソナ1: 初心者（プログラミング歴1.5年・PHP独学・バックエンド志望）

「ツリー構造をどうやってデータベースに保存するの？」が最初の壁。`parent_id` の自己参照が分かると「なるほど」となるが、`depth` をわざわざ別カラムに入れる理由（毎回 ancestors を遡らなくて済む）はすぐには気づかない。howto の「denormalized into the row to avoid recursive ancestor queries」の説明が救いになる。`ORDER BY id ASC` でなぜ親が先に来るのかも最初は分からない — `id` は挿入順だから親は常に子より先に挿入されていると気づくのに少し時間がかかる。

### ペルソナ2: ロースキル経験者（PHP歴4年・受託Web開発・SES）

ソフトデリートの扱いで「削除されたコメントを返すのは非効率では？」と思いがち。削除されたコメントを除外すると子コメントが孤立してツリーが壊れることを理解するのが鍵。また、「なぜ 204 ではなく 409 で二重削除を返すのか？」と疑問に持つ — 已に消えている状態への操作は冪等 204 でもいいのでは、という発想がある。ただし 409 は「状態遷移が不正」の意味で正しく、FT99（冪等性）との整合を意識した設計であることが説明できれば納得できる。

### ペルソナ3: フロントエンド寄り経験者（React/TS歴4年・フルスタック転向中）

レスポンスの `children` 配列が再帰的にネストしているのがすぐ分かり、UI 側の再帰コンポーネントとの相性が良い。`depth` フィールドがレスポンスに含まれているのでインデント幅の計算も簡単。気になるのはペジネーション — 大きなスレッドで全件返すのかどうかは仕様に明示されていない。「とりあえずポスト単位で全件取得」の設計は小規模には問題ないが、TypeScript クライアントでカーソルページネーションを将来追加する余地を残しておくべきだと感じる。

### ペルソナ4: バックエンド経験者（Laravel歴6年・リードエンジニア）

Laravel の Eloquent のような `hasMany` + `load()` を使わず、生 SQL + PHP 組み立てにしているのが「シンプルで良い」と感じる。が、`SELECT *` を使っている点が気になる — 将来カラムが増えると SELECT が重くなる。また `ORDER BY id ASC` はタイムスタンプが同じ場合に IDシーケンスに依存しているが、UUID 主キーだと崩れる設計になる（このフレームワークでは INTEGER PRIMARY KEY なので問題ない）。深さ制限をアプリレイヤーで担保している点は「DB 制約に落とせないか」と考えるが、深さはツリー全体の状態であり DB 制約では表現しにくいのでアプリ側が妥当と判断できる。

### ペルソナ5: シニアエンジニア（設計・コードレビュー担当・12年）

コードレビューで最初に確認するのは「FOREIGN KEY (parent_id) REFERENCES comments(id) に CASCADE DELETE がないか」（ない — 正しい）。CASCADE DELETE があると親削除で子が消えるため、ソフトデリート設計と矛盾する。次に確認するのは「ソフトデリートされたコメントへの返信ブロック」（ある — 409 で正しく処理）。ツリー組み立ての PHPStan 対策（rowMap と childIds を分離）は「コンパイラを説得するために設計を変えた」典型例で、良い意思決定。ドキュメントに残っているのも評価できる。

### ペルソナ6: 設計者・ポリシー照合（NENE2 設計ポリシー目線）

- **明示的ルーティング**: ✓ — 6 ルートが RouteRegistrar に一覧。
- **薄いコントローラー**: ✓ — バリデーション → リポジトリ → レスポンス。
- **No magic**: ✓ — ツリー組み立てが明示的な 2-pass ループ。
- **RFC 9457**: ✓ — 全エラーが ProblemDetailsResponseFactory 経由。
- **設計懸念**: `createPost()` / `addComment()` が「INSERT → fetchOne LAST」の 2 ステップ（トランザクションなし）。FT102 のトランザクション境界ポリシーとの整合は FT126 と同じ指摘。SQLite では LAST_INSERT_ROWID() で代替できるが、現在の設計は並列リクエストで別スレッドの INSERT を拾う可能性がゼロではない（ただし SQLite の write lock で現実的リスクは低い）。
