# DX Scenario 25: 不動産検索

## アプリ概要

物件・条件検索・お気に入り・問い合わせを管理する不動産情報 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 物件管理 | `GET /properties`, `POST /properties`（title, address, price, bedrooms, area_sqm, type） |
| 条件検索 | `GET /properties?type=apartment&bedrooms_min=2&price_max=30000000&area_min=50` |
| 写真管理 | `POST /properties/{id}/photos`, `DELETE /properties/{id}/photos/{pid}` |
| お気に入り | `POST /properties/{id}/favorites`, `DELETE /properties/{id}/favorites` |
| 問い合わせ | `POST /properties/{id}/inquiries`（name, email, message） |
| 物件ステータス | `PATCH /properties/{id}/status`（available/under_contract/sold） |
| 類似物件 | `GET /properties/{id}/similar`（価格帯・エリア・間取りで類似） |
| オーナー一覧 | `GET /owners/{id}/properties` |

ポイント: 多条件範囲検索（価格・面積・間取り）、物件ステータス管理、類似物件クエリ。

---

## Persona A — 新田 一馬（新卒・男性・23 歳）

### 背景

不動産会社でアルバイト経験あり（電話受付）。SUUMO を毎日使っているが内部設計は知らない。

### 作業シナリオ

1. `properties` テーブルに全フィールドを持たせる設計。
2. 条件検索を `WHERE 1=1` パターンで実装しようとするが、`bedrooms_min` など範囲検索の
   SQL 書き方が分からない。
3. `price_max` フィルタを `WHERE price <= :price_max` で書いたが、
   `price_max` がない場合の NULL チェックを忘れて全件 WHERE が壊れる。
4. 類似物件 `GET /properties/{id}/similar` は「同じ `bedrooms` 値の物件一覧」という
   過度にシンプルな実装。
5. 写真は `photos(property_id, url, display_order)` を正しく設計できた。

### ハマりポイント

- **NULL パラメータのフィルタ**: `?price_max=` が空でも `WHERE price <= NULL` になってしまう。
- **範囲検索の書き方**: `BETWEEN` vs `>=` / `<=` の使い分け。
- **類似物件のロジック**: 単純な `=` 条件では「似ている」を表現できない。

### 解決策 & 感想

`WHERE ($priceMax === null || 'price <= :price_max')` パターンで NULL チェックを追加。
類似物件は「価格 ±20%、面積 ±20% 以内」の BETWEEN で実装した。

> 「NULL チェックのフィルタ、これ分かりにくい。
>  howto に動的 WHERE 句のパターンがあれば速かった。
>  SUUMO みたいな検索、裏側がこんな感じなんだとわかった。」

### DX スコア: ⭐⭐⭐（3/5）

動的 WHERE 句で詰まった。専用 howto があれば大幅改善。

---

## Persona B — 太田 麻衣（ロースキル・女性・35 歳）

### 背景

不動産仲介会社のシステム担当 8 年。物件データベースの運用経験豊富。

### 作業シナリオ

1. テーブル設計:
   - `properties(id, owner_id, type, title, address, price_yen, bedrooms, area_sqm, status, created_at)`
   - `property_photos(id, property_id, url, alt_text, display_order)`
   - `favorites(user_id, property_id)` UNIQUE(user_id, property_id)
   - `inquiries(id, property_id, name, email, message, created_at)`
2. 多条件検索: `WHERE 1=1` + 各条件分岐（NULL の場合はスキップ）。
3. 類似物件: 価格 ±30% かつ 同じ `type` かつ `bedrooms` が ±1 以内:
   ```sql
   WHERE type = :type AND bedrooms BETWEEN :bedrooms-1 AND :bedrooms+1
   AND price_yen BETWEEN :price*0.7 AND :price*1.3
   AND id != :current_id LIMIT 5
   ```
4. 物件ステータス遷移: `available→under_contract→sold`（逆戻りチェック）。
5. `GET /properties` にページネーションを実装（`add-pagination.md` 参照）。

### ハマりポイント

- **`BETWEEN :price*0.7`**: 型の問題。`price_yen` が INTEGER で `:price*0.7` が REAL に
  なる場合の SQLite の型強制を確認。
- **ソート**: `?sort=price_asc&sort=area_desc` の複数ソートを動的 SQL で組み立てる複雑さ。
- **写真の `display_order` 更新**: 順序変更は一括 UPDATE が必要（前のシナリオと同様）。

### 解決策 & 感想

業務知識でスムーズに完成。SQLite の型強制は動作確認して問題なかった。

> 「`BETWEEN` に計算式を使うのが合ってるか不安だったけど動いた。
>  SQLite って型が緩いんだなと改めて感じた。
>  複数ソートの動的組み立ては冗長になったけど仕方ない。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。複数ソートの動的組み立てパターンが欲しい。

---

## Persona C — 齋藤 博文（ベテラン・男性・51 歳）

### 背景

不動産 SaaS プラットフォーム開発 20 年。物件検索エンジンの設計・最適化の専門家。

### 作業シナリオ

1. テーブル設計（検索最適化）:
   - `properties` に複合インデックス: `(type, status, bedrooms, price_yen)`
   - `property_features(property_id, feature)` — 築年数・駐車場・バルコニー等の特徴フラグ
   - `property_geo(property_id, latitude, longitude)` — 位置情報（今回は省略）
2. 複合インデックスを活用した検索クエリ設計。
   フィルタ条件の選択肢 power set を考慮したクエリ設計。
3. 類似物件は「ベクトル類似度」的な考え方で重み付きスコアを計算:
   ```sql
   SELECT *, ABS(price_yen - :price)/:price AS price_diff,
     ABS(bedrooms - :bedrooms) AS bedroom_diff
   FROM properties WHERE status='available' AND id != :id
   ORDER BY price_diff + bedroom_diff * 0.5 ASC LIMIT 5
   ```
4. `GET /properties` の全件数計算を `SELECT COUNT(*)` で実装（ページネーションのため）。
   大量データでは遅くなることを承知の上で。
5. OpenAPI でフィルタパラメータの型（integer/number）と説明を丁寧に定義。

### ハマりポイント

- **複合インデックスの効果**: SQLite の `EXPLAIN QUERY PLAN` で確認したが、
  `OR` 条件が多い場合はインデックスが使われにくいことを確認。
- **類似物件の重み付け**: `price_diff + bedroom_diff * 0.5` は恣意的な重み。
  機械学習モデルは今回対象外。
- **COUNT(*) のパフォーマンス**: 大量データでは遅い。`estimated_count` への切り替えが必要。

### 解決策 & 感想

高品質で完成。大規模対応のため ElasticSearch への移行余地を残した。

> 「本格的な不動産検索は Elasticsearch や Algolia に移行すべきだが、
>  NENE2 + SQLite でも小規模なら十分動く。
>  インデックス設計の howto は SEO 記事よりも価値があると思う。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。複合インデックス設計と COUNT(*) スケーリングのドキュメントが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 新田（新卒） | ○ NULL フィルタ改善後完成 | 3/5 | 動的 WHERE 句・NULL パラメータ処理 |
| 太田（ロースキル） | ○ 実用的完成 | 3/5 | 複数ソートの動的組み立て、SQLite 型強制 |
| 齋藤（ベテラン） | ◎ 高品質完成 | 4/5 | 複合インデックス設計、COUNT(*) スケーリング |

**共通のフリクション**:
1. **動的 WHERE 句パターン** — NULL パラメータのスキップ処理（多数のシナリオで言及。最重要 howto 候補）。
2. **SQLite インデックス設計** — 複合インデックスの効果確認（EXPLAIN QUERY PLAN）の howto。
3. **類似レコード検索** — 重み付きスコアでの類似検索パターン howto（推薦エンジン的な使い方）。
