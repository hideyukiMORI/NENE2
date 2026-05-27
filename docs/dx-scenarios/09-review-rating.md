# DX Scenario 09: ユーザー評価 + レビュー

## アプリ概要

商品レビュー・評価集計・スパムフラグを管理する API。

| 機能 | エンドポイント例 |
|------|----------------|
| レビュー投稿 | `POST /products/{id}/reviews`（rating 1-5, body, user_id） |
| レビュー一覧 | `GET /products/{id}/reviews?sort=newest&page=1` |
| 評価集計 | `GET /products/{id}/rating-summary`（平均・分布・件数） |
| 自分のレビュー | `GET /users/{id}/reviews` |
| レビュー更新 | `PUT /reviews/{id}`（自分のレビューのみ） |
| スパムフラグ | `POST /reviews/{id}/flag`, `GET /reviews?flagged=true`（管理者用） |
| フラグ解除 | `DELETE /reviews/{id}/flag/{fid}` |

ポイント: 1 ユーザー 1 商品 1 レビュー制限、評価集計クエリ、スパムフラグの多対多。

---

## Persona A — 青木 港（新卒・男性・23 歳）

### 背景

EC 系インターン経験あり。「Amazon のレビューみたいなもの」という理解で着手。

### 作業シナリオ

1. `reviews` テーブル: `id, product_id, user_id, rating, body, created_at`。
2. 「1 ユーザー 1 商品 1 レビュー」制限を思いつかず、同一ユーザーが複数回投稿できる設計になる。
3. `GET /products/{id}/rating-summary` で「PHP ループで平均を計算」する実装にする。
4. スパムフラグを `reviews.is_flagged BOOLEAN` の単純フラグで実装
   （誰がフラグしたかの記録なし）。
5. `PUT /reviews/{id}` で「自分のレビューかどうかの確認」を忘れ、他人のレビューも更新可能になる。

### ハマりポイント

- **ユニーク制約の設計**: `UNIQUE(product_id, user_id)` の発想がなかった。
- **集計 SQL**: `AVG(rating)` / `COUNT(*)` / `GROUP BY rating` を知らない。
- **認可チェック**: 「自分のものかどうか確認する」認可ロジックのパターンが分からない。

### 解決策 & 感想

先輩レビューで `UNIQUE` 制約の追加と SQL 集計の書き直しを指摘された。
認可チェックのパターンは `upvote-downvote-api.md` を参考にした。

> 「UNIQUE(product_id, user_id) って DB 側に入れるものなの？
>  アプリ側でチェックするものだと思ってた。
>  DB 制約の使い方まとめた howto があったら助かった。」

### DX スコア: ⭐⭐（2/5）

設計ミスが複数。DB 制約と認可パターンの howto が不足。

---

## Persona B — 長谷川 美紀（ロースキル・女性・30 歳）

### 背景

SaaS スタートアップの CS 兼エンジニア 4 年目。PHP は動画学習独学。

### 作業シナリオ

1. `reviews` テーブルに `UNIQUE(product_id, user_id)` を設定。
   `upvote-downvote-api.md` を参考にした（類似パターンとして気づいた）。
2. 評価集計は SQL で:
   ```sql
   SELECT AVG(rating) AS average, COUNT(*) AS total,
     SUM(CASE WHEN rating=5 THEN 1 ELSE 0 END) AS five_star, ...
   FROM reviews WHERE product_id=?
   ```
3. スパムフラグは `review_flags` テーブル（`review_id, user_id, reason, created_at`）で実装。
4. `PUT /reviews/{id}` の認可は `WHERE id=? AND user_id=?` の UPDATE で実装（0 行なら 403）。
5. ページネーションを `add-pagination.md` howto を参考に実装。

### ハマりポイント

- **集計 SQL の `CASE WHEN`**: 構文を確認するために MySQL リファレンスを参照。
  howto で見たことがなく自力で書いた。
- **認可の返すべきステータス**: 他人のレビューを更新しようとしたとき 403 vs 404 のどちらを
  返すべきか悩んだ（情報隠蔽の観点）。
- **スパム管理インターフェース**: 管理者向けエンドポイントの認証をどう設定するか不明。

### 解決策 & 感想

全体的にうまく完成できた。`upvote-downvote-api.md` が類似パターンとして役立った。

> 「CASE WHEN は初めて書いた。難しくはないけど howto にあれば速かった。
>  認可で 403 か 404 か悩んだ。セキュリティ的には 404 の方がいい気がしたけど確証がなかった。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。CASE WHEN 集計と認可ステータスの説明が欲しい。

---

## Persona C — 吉田 康弘（ベテラン・男性・50 歳）

### 背景

楽天市場系 EC プラットフォーム開発 20 年。大規模レビューシステムの設計経験あり。

### 作業シナリオ

1. `reviews` テーブルに `UNIQUE(product_id, user_id)` + `CHECK(rating BETWEEN 1 AND 5)`。
2. 評価集計は `rating_snapshots` テーブルで事前集計を管理（レビュー INSERT/UPDATE/DELETE 時に同期更新）。
   `GET /products/{id}/rating-summary` はスナップショットから O(1) で返す。
3. スパムフラグは `review_flags(review_id, user_id, reason, created_at)` 中間テーブル。
   管理者向けの一覧は `flag_count` 降順で返す。
4. 認可は `ResourceAuthorizationService::ownsResource()` として抽象化し UseCase で利用。
   存在しないレビューに対しては 404、他人のレビューには 404（情報隠蔽）。
5. OpenAPI で全エラーケースを網羅。

### ハマりポイント

- **スナップショット更新の原子性**: レビュー INSERT と スナップショット UPDATE を同一トランザクションにする必要あり。`tx->run()` で解決したが、ネストしたトランザクションが必要になる場面があった（NENE2 でサポートされているか確認が必要）。
- **認可抽象化**: `ResourceAuthorizationService` をコンテナに登録する方法を `DependencyInjection/` で確認。
- **情報隠蔽**: 「他人のレビューは 404 で隠す」ポリシーを API ドキュメントにどう書くか。

### 解決策 & 感想

高品質で完成。スナップショット更新のトランザクション処理で少し時間がかかった。

> 「スナップショット方式は大規模には必須だけど、NENE2 でのパターンが確立されてないから
>  自分で考えた。あとネストトランザクションのサポート状況が不明で確認が大変だった。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。スナップショット管理とネストトランザクション仕様の明確化が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 青木（新卒） | △ 設計ミス複数 | 2/5 | UNIQUE 制約の設計、認可チェック |
| 長谷川（ロースキル） | ○ 良好に完成 | 3/5 | CASE WHEN 集計例、認可 403 vs 404 |
| 吉田（ベテラン） | ◎ 高品質完成 | 4/5 | スナップショット管理、ネストトランザクション仕様 |

**共通のフリクション**:
1. **DB ユニーク制約の活用パターン** — `UNIQUE(compound)` の設計例 howto が欲しい。
2. **認可エラーの 403 vs 404** — 情報隠蔽観点での選択基準ドキュメント。
3. **CASE WHEN 集計** — SQL 集計パターン集（AVG/SUM/COUNT/CASE WHEN）の howto が欲しい。
