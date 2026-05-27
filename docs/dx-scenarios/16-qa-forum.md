# DX Scenario 16: QA フォーラム

## アプリ概要

質問・回答・ベストアンサー・投票を備えた Q&A フォーラム API。

| 機能 | エンドポイント例 |
|------|----------------|
| 質問投稿 | `POST /questions`（title, body, tags） |
| 質問一覧 | `GET /questions?tag=php&sort=votes&page=1` |
| 回答投稿 | `POST /questions/{id}/answers` |
| 投票 | `POST /questions/{id}/votes`（direction: up/down） |
| 回答への投票 | `POST /answers/{id}/votes` |
| ベストアンサー | `PATCH /questions/{id}/accepted-answer`（answer_id）← 質問者のみ |
| 自分の質問 | `GET /users/{id}/questions` |
| 自分の回答 | `GET /users/{id}/answers` |
| タグ一覧 | `GET /tags?q=php`（タグ検索）|

ポイント: 投票スコア集計（upvote-downvote）、ベストアンサー（1 質問 1 件）、タグの多対多。

---

## Persona A — 水野 蒼（新卒・男性・23 歳）

### 背景

工業高校→大学情報学部→新卒入社。Stack Overflow は毎日使っているが「仕組み」は考えたことない。

### 作業シナリオ

1. `questions` / `answers` テーブルを作成。
2. 投票は `questions.vote_count INTEGER` カラムを直接更新する設計。
3. 「ベストアンサー」を `questions.best_answer_id` として外部キー設定しようとするが、
   循環参照（`questions` → `answers` → `questions`）でマイグレーションが通らない。
4. タグは `questions.tags TEXT`（コンマ区切り）。
5. `GET /questions?sort=votes` の実装方法が分からず、デフォルトの日付順のみ実装。

### ハマりポイント

- **循環外部キー**: `questions.best_answer_id → answers.id` と `answers.question_id → questions.id`
  の相互参照でマイグレーションエラー。
- **投票スコアの直接更新**: 誰が投票したかの記録がなく、取り消し・二重投票防止ができない。
- **タグのコンマ区切り**: タグ検索ができない（毎回詰まるパターン）。

### 解決策 & 感想

`docs/howto/upvote-downvote-api.md` を読んで投票テーブルに設計変更。
循環外部キーは `DEFERRABLE` または `NULL` スタートで解決した。

> 「upvote-downvote の howto 助かった。あれ読まなかったら
>  vote_count カラムのまま進んでた。
>  循環外部キーってどうやって解決するの？ってなったけど、
>  とりあえず NOT NULL 外して先に INSERT する方法で対応した。」

### DX スコア: ⭐⭐⭐（3/5）

`upvote-downvote-api.md` を活用できれば改善。循環外部キーの howto が欲しい。

---

## Persona B — 鈴木 愛（ロースキル・女性・27 歳）

### 背景

Web 系会社でフロントエンドメインだが最近バックエンドも担当し始めた 4 年目。

### 作業シナリオ

1. `upvote-downvote-api.md` を事前に読んでいた（チームで共有されていた）。
   `question_votes(question_id, user_id, direction)` / `answer_votes(answer_id, user_id, direction)` で設計。
2. スコア計算は `COALESCE(SUM(CASE WHEN direction='up' THEN 1 ELSE -1 END), 0)` を
   サブクエリで使用。
3. ベストアンサー: `questions.accepted_answer_id INTEGER NULL` → `answers.id` の外部キー。
   循環参照は `answers.question_id` を先に作成してから `questions.accepted_answer_id` を追加。
4. タグは `tags` テーブル + `question_tags(question_id, tag_id)` で多対多。
5. `GET /questions?sort=votes` は `ORDER BY (SELECT SUM(...) FROM votes WHERE question_id=q.id) DESC`
   相関サブクエリで実装（遅い可能性あり）。

### ハマりポイント

- **相関サブクエリのパフォーマンス**: `ORDER BY` に相関サブクエリを使うと全行で計算が走る。
  実用的なデータ量ではまだ遅くなかったが、本番規模では問題になる。
- **ベストアンサーの認可**: 「質問者のみが accept できる」の認可チェックを忘れそうになる。
- **`accepted_answer_id` と `answers.is_accepted` の整合**: 両方に状態が分散しないよう
  `accepted_answer_id` のみで管理した（`is_accepted` カラムは作らない）。

### 解決策 & 感想

howto を活用して比較的スムーズに実装できた。

> 「upvote-downvote howto は参考になった。
>  ORDER BY でサブクエリ使うの遅そうだとは思ったけど、
>  JOIN で score を計算する書き方が分からなかった。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。スコア ORDER BY の効率的な書き方の howto が欲しい。

---

## Persona C — 田村 正樹（シニア・男性・37 歳）

### 背景

Stack Overflow クローン開発経験あり。Q&A システムの設計パターンを熟知。

### 作業シナリオ

1. テーブル設計:
   - `questions(id, user_id, title, body, accepted_answer_id, created_at)`
   - `answers(id, question_id, user_id, body, created_at)`
   - `votes(id, votable_type, votable_id, user_id, direction)` — ポリモーフィック
   - `tags(id, name, slug)` + `question_tags(question_id, tag_id)`
2. スコアは `votes_score` VIEW を作成して事前計算:
   ```sql
   CREATE VIEW votes_score AS
   SELECT votable_type, votable_id,
     SUM(CASE WHEN direction='up' THEN 1 ELSE -1 END) AS score
   FROM votes GROUP BY votable_type, votable_id
   ```
3. `GET /questions?sort=votes` は VIEW を JOIN して `ORDER BY vs.score DESC`。
4. ベストアンサーの accept: トランザクション内で
   `UPDATE questions SET accepted_answer_id=? WHERE id=? AND user_id=?`（行数 0 → 403）。
5. OpenAPI でポリモーフィック投票のスキーマを定義（`votable_type: enum[question, answer]`）。

### ハマりポイント

- **SQLite の VIEW**: VIEW は読み取りのみ可能。`votes_score` VIEW の更新は `votes` テーブルへの
  INSERT で自動反映される。パフォーマンスは許容範囲か。
- **ポリモーフィック外部キー**: `votes.votable_id` は `questions.id` にも `answers.id` にも
  参照するため、DB 制約で整合性を強制できない（アプリ側で管理）。
- **タグのオートコンプリート**: `GET /tags?q=ph` のプレフィックス検索は `LIKE 'ph%'`
  でインデックスが効く（`%ph%` は効かない）ことを確認。

### 解決策 & 感想

高品質で完成。ポリモーフィック設計はトレードオフを理解した上で採用。

> 「ポリモーフィック外部キーは DB 制約が使えないのが弱点。
>  NENE2 でこのパターンを使う場合の注意事項を howto に書いておくと良いと思う。
>  SQLite の VIEW はパフォーマンスを測ってからが良いかもしれない。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。ポリモーフィック設計と SQLite VIEW の注意点ドキュメントが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 水野（新卒） | ○ howto 活用で改善 | 3/5 | 循環外部キー、タグの多対多 |
| 鈴木（ロースキル） | ○ 良好に完成 | 3/5 | スコア ORDER BY のパフォーマンス最適化 |
| 田村（シニア） | ◎ 高品質完成 | 4/5 | ポリモーフィック外部キーの注意点 |

**共通のフリクション**:
1. **循環外部キーの解決パターン** — `DEFERRABLE` と NULL スタートの 2 通りの howto。
2. **スコアを使った効率的な ORDER BY** — VIEW 作成 vs 相関サブクエリの比較。
3. **ポリモーフィック関連の設計パターンと注意点** — DB 制約外になるリスクと代替手段。
