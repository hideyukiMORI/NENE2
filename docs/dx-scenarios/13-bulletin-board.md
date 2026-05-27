# DX Scenario 13: 社内掲示板

## アプリ概要

カテゴリ・投稿・既読管理・通知を備えた社内掲示板 API。

| 機能 | エンドポイント例 |
|------|----------------|
| カテゴリ管理 | `GET /categories`, `POST /categories`（name, is_public） |
| 投稿 CRUD | `GET /posts`, `POST /posts`, `GET /posts/{id}`, `PUT /posts/{id}` |
| 固定化 | `PATCH /posts/{id}/pin`, `PATCH /posts/{id}/unpin` |
| 既読管理 | `POST /posts/{id}/read`（自分が読んだ記録）|
| 未読一覧 | `GET /posts/unread`（自分が未読の投稿） |
| お知らせ通知設定 | `POST /categories/{id}/subscribe`, `DELETE /categories/{id}/subscribe` |
| 添付ファイル | `POST /posts/{id}/attachments`, `GET /posts/{id}/attachments` |

ポイント: 既読管理（N:M テーブル）、未読取得クエリ（NOT IN vs LEFT JOIN）、カテゴリ購読。

---

## Persona A — 遠藤 大輔（新卒・男性・23 歳）

### 背景

IT 専門学校卒 1 年目。HTML/CSS/JavaScript + PHP の基礎教育を受けた。
「掲示板なら分かる」という感覚。

### 作業シナリオ

1. `categories` / `posts` / `attachments` テーブルを作成。
2. 既読管理を「posts テーブルに `read_by TEXT`（ユーザー ID のコンマ区切り）」で実装。
3. 未読一覧を「全投稿を取得して PHP で `read_by` に自分の ID があるか確認する」実装。
4. カテゴリ購読は `category_subscriptions(category_id, user_id)` を正しく設計できた
   （別プロジェクトのコードを参考にした）。
5. 固定化を `posts.is_pinned BOOLEAN` で実装。固定投稿が先頭に来る ORDER BY を書けなかった。

### ハマりポイント

- **コンマ区切り文字列の既読管理**: 大量ユーザーで検索・更新が遅くなる問題に後で直面。
- **ORDER BY BOOLEAN DESC**: `ORDER BY is_pinned DESC, created_at DESC` の書き方が分からない。
- **未読クエリの効率**: PHP ループによる未読判定がデータが多くなると遅い。

### 解決策 & 感想

`post_reads(post_id, user_id)` テーブルに設計を変更。未読クエリを `NOT EXISTS` で書き直した。

> 「コンマ区切りって最初はアリだと思ってたけど、
>  あとで全然使えないって分かった。
>  ORDER BY で複数条件の順番は知らなかった。」

### DX スコア: ⭐⭐（2/5）

既読管理の設計ミス。N:M テーブルと NOT EXISTS クエリの howto が欲しい。

---

## Persona B — 中川 由美（ロースキル・女性・29 歳）

### 背景

中小企業の社内 IT 担当 5 年目。PHP は独学。以前 BBS を作ったことがある（フラット型）。

### 作業シナリオ

1. `post_reads(post_id, user_id, read_at)` UNIQUE(post_id, user_id) テーブルで設計。
2. 未読一覧: `GET /posts/unread`:
   ```sql
   SELECT p.* FROM posts p
   WHERE NOT EXISTS (
     SELECT 1 FROM post_reads pr
     WHERE pr.post_id = p.id AND pr.user_id = :user_id
   )
   ORDER BY p.is_pinned DESC, p.created_at DESC
   ```
3. カテゴリ購読は `category_subscriptions(category_id, user_id)` テーブル。
4. 添付ファイルは `attachments(post_id, file_path, original_name, size_bytes)` テーブル。
   ファイルは前回スシナリオ(12)の経験を参考に `var/storage/` に保存。
5. 投稿の `category_id` が公開カテゴリかどうかの権限チェックを一部忘れる。

### ハマりポイント

- **`NOT EXISTS` vs `LEFT JOIN IS NULL`**: どちらを使うかで迷い、パフォーマンスの差が不明。
- **カテゴリ権限チェック**: 非公開カテゴリへのアクセス制限を投稿取得時に忘れた。
- **未読件数のカウント**: `GET /posts` に「自分の未読件数を含める」フィールドを後で要求された。

### 解決策 & 感想

全体的によくできた。`NOT EXISTS` クエリは自力で書けた。

> 「NOT EXISTS か LEFT JOIN かはパフォーマンスが同じなら NOT EXISTS の方が読みやすい。
>  でも本当に同じ速さなのか分からないまま選んだ。
>  howto に比較があると嬉しい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。クエリ選択の根拠とカテゴリ権限チェックの注意点が欲しい。

---

## Persona C — 菊地 誠（シニア・男性・44 歳）

### 背景

グループウェア開発 15 年のベテラン。既読管理の設計パターンを熟知している。

### 作業シナリオ

1. テーブル設計:
   - `post_reads(post_id, user_id, read_at)` — `UNIQUE(post_id, user_id)`, インデックス必須
   - カテゴリ可視性: `is_public` + `category_members(category_id, user_id)` で非公開カテゴリを管理
2. 未読一覧は `LEFT JOIN post_reads ON pr.post_id = p.id AND pr.user_id = ?` で実装
   （`NOT EXISTS` より `LEFT JOIN IS NULL` の方が SQLite では速いことを経験上知っている）。
3. 未読カウントを `COUNT(CASE WHEN pr.post_id IS NULL THEN 1 END)` で一緒に計算。
4. 固定投稿: `ORDER BY is_pinned DESC, pinned_at DESC, created_at DESC`。
5. カテゴリ購読: `category_subscriptions` + 通知は「仮 API エンドポイント」として定義
   （実際の通知配信はキューが必要なため今回は省略）。

### ハマりポイント

- **`LEFT JOIN IS NULL` vs `NOT EXISTS`**: SQLite での実際のパフォーマンス差を
  EXPLAIN QUERY PLAN で確認した。どちらも似たようなプランだった。
- **非公開カテゴリの複合権限チェック**: 「is_public OR member」の OR 条件を
  正しく書くのが少し冗長になった。
- **通知の配信**: 「購読者に通知する」処理は非同期が必要。今回は仕様外とした。

### 解決策 & 感想

高品質で完成。通知配信の非同期パターンについて NENE2 の方向性が気になった。

> 「EXPLAIN QUERY PLAN が使えるのを確認した。
>  howto に「SQLite のクエリ最適化確認方法」があると嬉しい。
>  通知配信の非同期化、NENE2 でどうやるか答えが見つからなかった。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。クエリ最適化確認と非同期通知パターンのドキュメントが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 遠藤（新卒） | △ 設計ミス・改修必要 | 2/5 | N:M 既読テーブル、NOT EXISTS クエリ |
| 中川（ロースキル） | ○ 実用的完成 | 3/5 | NOT EXISTS vs LEFT JOIN 比較、権限チェック漏れ |
| 菊地（シニア） | ◎ 高品質完成 | 4/5 | EXPLAIN QUERY PLAN、非同期通知パターン |

**共通のフリクション**:
1. **既読管理の設計パターン** — `post_reads(post_id, user_id)` テーブルパターンの howto。
2. **`NOT EXISTS` vs `LEFT JOIN IS NULL`** — 使い分けとパフォーマンスの説明。
3. **SQLite クエリ最適化** — `EXPLAIN QUERY PLAN` の使い方 howto。
