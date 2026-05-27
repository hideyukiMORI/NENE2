# DX Scenario 20: レシピ共有

## アプリ概要

レシピ・材料・手順・お気に入り・カテゴリを管理するレシピ共有 API。

| 機能 | エンドポイント例 |
|------|----------------|
| レシピ投稿 | `POST /recipes`（title, description, cook_time_min, servings） |
| 材料追加 | `POST /recipes/{id}/ingredients`（name, amount, unit, order） |
| 手順追加 | `POST /recipes/{id}/steps`（step_number, description, tip） |
| カテゴリ設定 | `POST /recipes/{id}/categories`（category_id） |
| お気に入り | `POST /recipes/{id}/favorites`, `DELETE /recipes/{id}/favorites` |
| レシピ検索 | `GET /recipes?category=main&cook_time_max=30&q=パスタ` |
| 人気順 | `GET /recipes?sort=favorites` |
| 公開/下書き | `PATCH /recipes/{id}/publish`, `PATCH /recipes/{id}/unpublish` |

ポイント: 材料・手順の順序管理（`order_index`）、お気に入りカウント集計、多条件検索。

---

## Persona A — 中田 美緒（新卒・女性・22 歳）

### 背景

栄養学科卒でプログラミングスクール経由。料理好きで「クックパッド風のものを作りたい」という動機。

### 作業シナリオ

1. `recipes` / `ingredients` / `steps` テーブルを作成。
2. 材料の順序を考慮せず `id` 順で返す（`order_index` なし）。
3. 手順も同様に `step_number` を入力させるが、値の重複チェックをしない。
4. お気に入りを `recipes.favorite_count INTEGER` を直接更新する設計。
5. レシピ検索 `GET /recipes?q=パスタ` を `WHERE title LIKE '%パスタ%'`
   （日本語の部分一致で動作したが遅い）。

### ハマりポイント

- **順序管理**: `order_index` なしで材料を登録すると後から順序変更ができない。
- **`step_number` の重複**: step 3 を 2 回登録できてしまう。
- **お気に入りカウントの直接更新**: 誰がお気に入りしたかの記録がなく重複登録が起きる。

### 解決策 & 感想

先輩レビューで `order_index` と `recipe_favorites(recipe_id, user_id)` テーブルを追加。
`step_number` は `UNIQUE(recipe_id, step_number)` で重複防止。

> 「順序って大事なんだな。表示順って DB に保存しないといけないんだ。
>  お気に入りの数え方、カラムに保存するのがダメだって理由をちゃんと説明してほしかった。」

### DX スコア: ⭐⭐⭐（3/5）

基本動作するが設計改善必要。順序管理と N:M カウントの howto があると良い。

---

## Persona B — 春田 義宏（ロースキル・男性・36 歳）

### 背景

食品系 EC の IT 担当 9 年目。商品管理システムの経験から「材料リストは商品スペックと同じ」感覚。

### 作業シナリオ

1. テーブル設計:
   - `recipes(id, user_id, title, cook_time_min, servings, status, created_at)`
   - `ingredients(id, recipe_id, name, amount, unit, order_index)` ← 順序あり
   - `recipe_steps(id, recipe_id, step_number, description, tip)` UNIQUE(recipe_id, step_number)
   - `recipe_favorites(recipe_id, user_id)` UNIQUE(recipe_id, user_id)
2. お気に入り数: `SELECT COUNT(*) FROM recipe_favorites WHERE recipe_id=?` で都度計算。
3. `GET /recipes?sort=favorites` は:
   ```sql
   SELECT r.*, COUNT(rf.recipe_id) AS fav_count
   FROM recipes r
   LEFT JOIN recipe_favorites rf ON rf.recipe_id = r.id
   GROUP BY r.id
   ORDER BY fav_count DESC
   ```
4. 検索 `GET /recipes?category=main&cook_time_max=30&q=パスタ` は条件分岐 WHERE で実装。
5. 材料の順序変更 `PATCH /recipes/{id}/ingredients/order` を「配列で受け取ってループ UPDATE」で実装。

### ハマりポイント

- **`LEFT JOIN + GROUP BY` のページネーション**: `COUNT(*) ORDER BY ... LIMIT ? OFFSET ?` の
  組み合わせが正しく動かない場合があった（GROUP BY の後の LIMIT）。
- **カテゴリの多対多**: `recipes` と `categories` の N:M を `recipe_categories` テーブルで実装したが、
  カテゴリフィルタの JOIN が複雑になった。
- **材料順序変更の整合**: ループ UPDATE をトランザクション内で行わなかったため、
  途中で失敗すると不整合になる可能性があった。

### 解決策 & 感想

おおむね完成できた。`GROUP BY` + ページネーションは修正に時間がかかった。

> 「GROUP BY とページネーションの組み合わせは詰まりどころ。
>  LEFT JOIN して GROUP BY すると行数の計算がおかしくなった。
>  サブクエリで先に集計してから JOIN するパターンに変えたら直った。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。GROUP BY + ページネーションと多対多フィルタの howto が欲しい。

---

## Persona C — 原田 和子（シニア・女性・45 歳）

### 背景

フードテック系スタートアップのテックリード 10 年。多言語対応レシピサービスの経験あり。

### 作業シナリオ

1. テーブル設計（拡張性考慮）:
   - `recipes` / `ingredients(order_index)` / `recipe_steps(step_number)` / `recipe_categories` / `recipe_favorites`
   - `categories(id, name, slug, parent_id)` — 階層カテゴリ（`hierarchical-data.md` 参照）
2. お気に入り数は `recipe_stats(recipe_id, favorite_count)` スナップショットで管理。
   お気に入り追加/削除時に同時更新（トランザクション内）。
3. `GET /recipes?sort=favorites` は `recipe_stats` JOIN で O(1) に近い集計。
4. 複数フィルタ検索は `WHERE 1=1 AND ...` パターン + LIMIT/OFFSET ページネーション。
5. `GET /recipes/{id}` で材料・手順を `ORDER BY order_index` / `ORDER BY step_number` で返す。

### ハマりポイント

- **スナップショット同期**: お気に入り追加/削除と `recipe_stats` 更新を同一トランザクションにする
  必要があり、トランザクション内 UPDATE を確認した。
- **階層カテゴリのフィルタ**: 「メイン料理カテゴリ以下を全部検索」する場合、
  サブツリー取得が必要になる（`hierarchical-data.md` の LIKE パターンを活用）。
- **材料の ORDER BY**: フロントエンドでの DnD (Drag & Drop) 並べ替えに対応するには
  `order_index` の更新 API が必要で、一括更新のアトミック性を確保した。

### 解決策 & 感想

高品質で完成。`hierarchical-data.md` のカテゴリツリーフィルタが直接使えた。

> 「hierarchical-data howto が再び活躍した。
>  スナップショット方式のお気に入りカウントは
>  読み取り負荷を考えると必須。これを howto にするとよいかもしれない。
>  材料順序の一括更新 API は意外と多くのアプリで使われるパターン。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。スナップショットカウントと一括順序更新パターンの howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 中田（新卒） | ○ 設計改善後完成 | 3/5 | 順序管理の重要性、N:M カウント |
| 春田（ロースキル） | ○ 実用的完成 | 3/5 | GROUP BY + ページネーション、多対多フィルタ |
| 原田（シニア） | ◎ 高品質完成 | 4/5 | スナップショットカウント、一括順序更新 |

**共通のフリクション**:
1. **順序管理 (`order_index`) のパターン** — 一括更新のアトミック実装。多くのアプリで必要。
2. **GROUP BY + ページネーションの組み合わせ** — 複数シナリオで詰まるポイント。専用 howto。
3. **スナップショットカウント管理** — N:M カウントの効率的な管理パターン（複数シナリオで言及）。
