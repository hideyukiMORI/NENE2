# DX Scenario 04: ブログ + コメント + タグ

## アプリ概要

記事投稿・コメントスレッド・タグ分類・全文検索を備えたブログ API。

| 機能 | エンドポイント例 |
|------|----------------|
| 記事 CRUD | `GET /posts`, `POST /posts`, `GET /posts/{id}`, `PUT /posts/{id}`, `DELETE /posts/{id}` |
| 公開・下書き | `PATCH /posts/{id}/publish`, `PATCH /posts/{id}/unpublish` |
| タグ管理 | `POST /tags`, `POST /posts/{id}/tags/{tagId}`, `DELETE /posts/{id}/tags/{tagId}` |
| コメント | `GET /posts/{id}/comments`, `POST /posts/{id}/comments`, `DELETE /comments/{id}` |
| 検索 | `GET /posts?q=keyword&tag=php` |
| 著者別一覧 | `GET /authors/{id}/posts` |

ポイント: タグの多対多、コメントのスレッド（2 レベル）、下書き/公開ステータス、全文検索。

---

## Persona A — 小林 あやの（新卒・女性・23 歳）

### 背景

文系学部卒後プログラミングスクール経由でエンジニアに。PHP は学んだが設計は初めて。
「ブログなら作ったことある（WordPress）」という自信あり。

### 作業シナリオ

1. WordPress のイメージで `wp_posts` 風の `posts` テーブルを作る。`post_status` は `TEXT`。
2. タグは `posts.tags` に JSON 配列で保存（`["php", "nene2"]`）という選択をする。
3. コメントはスレッド対応で `parent_id` カラムを追加しようとするが、深さ制限の概念が分からない。
4. `GET /posts?q=keyword` を `LIKE '%keyword%'` で実装。インデックスが効かない。
5. 公開/下書きを `PATCH /posts/{id}/publish` エンドポイントにすべきか、
   `PUT /posts/{id}` の `status` フィールドで変更すべきか分からず両方作ってしまう。

### ハマりポイント

- **タグの多対多 vs JSON カラム**: JSON カラムでも「動く」のでそのまま進んでしまう。
  タグ検索が後でできないことに気づく。
- **コメントの深さ制限**: 制限なしで実装、無限ネスト可能な設計になる。
- **PATCH vs PUT**: HTTP メソッドの使い分けが曖昧で二重実装。

### 解決策 & 感想

`docs/howto/threaded-comments-api.md` を読んで深さ制限（`depth <= 1`）パターンを習得。
タグは後で `post_tags` 中間テーブルに直した。

> 「WordPress のノリで作ったら全然違った。
>  howto のコメントのやつ読んだら『ああ、こういう設計なんだ』ってなった。
>  PATCH と PUT の違いまだよく分かってないけど、一応直した。」

### DX スコア: ⭐⭐⭐（3/5）

howto を活用できれば改善できる。REST メソッドの使い分け説明があると良い。

---

## Persona B — 岡田 誠一郎（ロースキル・男性・36 歳）

### 背景

個人事業主の Web 制作者 10 年。案件はほぼ WordPress で、純粋 PHP は 3 年前から。
「REST API は雰囲気で作ってる」状態。

### 作業シナリオ

1. `posts` / `tags` / `post_tags` / `comments` テーブルを作る（多対多を知っていた）。
2. `GET /posts?q=keyword` を `WHERE title LIKE ? OR body LIKE ?` で実装。
3. コメント削除に「ソフトデリート」を使おうとして `docs/howto/soft-delete.md` を探すが
   `soft-delete-restore-permanent.md` しか見つからず、読んだが restore 不要なので独自実装した。
4. タグフィルタ `GET /posts?tag=php` は `EXISTS (SELECT ...)` サブクエリで実装。
5. 下書き・公開は `posts.status` を `draft/published` の 2 値で管理。遷移チェックなし。

### ハマりポイント

- **ソフトデリートの howto が restore 込みで重い**: コメントの単純な soft delete だけ欲しいのに
  restore/permanent の全機能が書かれていて読むのが大変だった。
- **タグ検索の JOIN**: `EXISTS` は正しいが `INNER JOIN` + `GROUP BY` の方が可読性が高いと
  後でレビューで指摘された。
- **ページネーション**: `GET /posts` にページネーションを付け忘れた。

### 解決策 & 感想

全体的に動作するものを作れた。コードレビューで JOIN 書き方の改善点を指摘された。

> 「soft-delete の howto 、restore いらない場合でもとりあえず読めたからよかった。
>  ページネーション忘れてたのは自分のミスだけど、howto にもっと強調されてたら
>  意識したかもしれない。」

### DX スコア: ⭐⭐⭐（3/5）

実用的なものが完成。JOIN パターンとページネーション reminder が欲しい。

---

## Persona C — 藤本 真理子（シニア・女性・44 歳）

### 背景

PHP エンジニア 15 年、現在テックリード。DDD と CQRS に慣れている。
「ドキュメント先に読む」習慣。

### 作業シナリオ

1. `docs/howto/` を一通りスキャンしてパターンを把握。
   `threaded-comments-api.md` / `soft-delete-restore-permanent.md` / `hierarchical-data.md` を確認。
2. ドメインモデル設計:
   - `Post` (id, title, body, status, author_id, published_at, created_at)
   - `Tag` (id, name, slug)
   - `PostTag` (post_id, tag_id) ← 中間テーブル (value object として扱う)
   - `Comment` (id, post_id, parent_id, body, deleted_at)
3. `PostRepository::search(string $q, ?string $tag)` で検索クエリを構築。
   FTS (Full Text Search) は SQLite FTS5 を検討したが、複雑なので `LIKE` で妥協。
4. コメントのソフトデリートは `deleted_at` + `[deleted]` 置換のトゥームストーンパターンを採用。
5. OpenAPI スキーマを先に書き、`composer check` 全通過で完成。

### ハマりポイント

- **SQLite FTS5**: `CREATE VIRTUAL TABLE posts_fts USING fts5(...)` の使い方が howto にない。
  自分で調べたが NENE2 との統合パターンが不明なため断念。
- **`PostTag` の ReadModel**: 記事に含めるタグ一覧を効率的に取得するクエリ（`GROUP_CONCAT`）が
  howto にない。
- **公開スケジューリング**: `published_at` を未来日時に設定して自動公開したいが、
  バックグラウンドジョブ系の機能がないため断念。

### 解決策 & 感想

高品質で完成できた。FTS と GROUP_CONCAT は自力実装した。

> 「SQLite FTS5 の統合方法は欲しかった。GROUP_CONCAT は SQL の問題なので
>  howto が無くても困らないけど、あれば嬉しい。
>  スケジュール公開はバッチ設計の話になるから今回は諦めた。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。FTS 統合と GROUP_CONCAT パターンのドキュメントが改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 小林（新卒） | ○ 動作するが設計改善要 | 3/5 | REST メソッド使い分け、タグの多対多 |
| 岡田（ロースキル） | ○ 実用的な完成 | 3/5 | JOIN パターン、ページネーション忘れ |
| 藤本（シニア） | ◎ 高品質完成 | 4/5 | SQLite FTS5 統合、GROUP_CONCAT |

**共通のフリクション**:
1. **SQLite FTS5 (全文検索) の統合 howto がない** — 検索機能は多くのアプリで必要。
2. **GROUP_CONCAT / JSON_ARRAYAGG** — タグ付きレスポンスの効率的な取得パターン。
3. **HTTP メソッド選択の説明** — PATCH vs PUT vs POST の使い分けガイドが欲しい。
