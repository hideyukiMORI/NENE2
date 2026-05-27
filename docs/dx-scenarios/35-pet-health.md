# DX Scenario 35: ペット健康管理

## アプリ概要

ペット・ワクチン・体重記録・診察履歴を管理するペット健康管理 API。

| 機能 | エンドポイント例 |
|------|----------------|
| ペット管理 | `GET /pets`, `POST /pets`（name, species, breed, birth_date, owner_id）|
| 体重記録 | `POST /pets/{id}/weight-logs`（date, weight_kg） |
| ワクチン管理 | `POST /pets/{id}/vaccinations`（vaccine_name, vaccinated_at, next_due_at）|
| 期限切れアラート | `GET /vaccinations/due`（今後 30 日以内に期限が来るワクチン）|
| 診察記録 | `POST /pets/{id}/vet-visits`（visit_date, clinic, diagnosis, treatment）|
| 体重グラフ | `GET /pets/{id}/weight-trend`（月別平均体重）|
| 健康サマリー | `GET /pets/{id}/health-summary`（最新体重・直近診察・次のワクチン）|
| 共有 | `POST /pets/{id}/caretakers`（user_id）← 家族や保護者に共有 |

ポイント: 複数オーナー/ケアテイカー共有、期限アラート（30 日以内）、月別体重トレンド。

---

## Persona A — 篠原 七海（新卒・女性・22 歳）

### 背景

動物看護学科卒でプログラミングスクール経由。愛犬の健康管理アプリを作りたいと思っていた。

### 作業シナリオ

1. `pets` / `vaccinations` / `vet_visits` テーブルを作成。
2. 体重記録を `pets.current_weight REAL` で上書き管理（履歴なし）。
3. ワクチン期限アラートを「毎回全件取得して PHP でフィルタ」する実装。
4. 共有機能（`caretakers`）を思いつかず省略。
5. 健康サマリーを 3 回クエリで取得（体重/診察/ワクチンを個別クエリ）。

### ハマりポイント

- **体重履歴の欠如**: 「増えた/減った」のトレンドが見られない。
- **期限アラートの SQL**: `WHERE next_due_at BETWEEN today AND today+30` の書き方。
- **複数ケアテイカー**: 家族で共有するには `pet_caretakers(pet_id, user_id)` が必要。

### 解決策 & 感想

`weight_logs(pet_id, date, weight_kg)` テーブルを追加。
期限アラートを SQL WHERE 句で実装した。

> 「体重って履歴で見るのが大事なんだと分かった。
>  犬の健康記録って獣医師みたいな専門知識が必要で、
>  仕様を決めるのが難しかった。」

### DX スコア: ⭐⭐⭐（3/5）

基本機能は完成。健康記録系のパターンは howto 19 の読書記録と類似。

---

## Persona B — 松井 英二（ロースキル・男性・38 geq 歳）

### 背景

ペット保険会社の IT 担当 10 年。ペット医療の業務知識豊富。

### 作業シナリオ

1. テーブル設計:
   - `pets(id, owner_id, name, species, breed, birth_date, microchip_number)` + `pet_caretakers(pet_id, user_id)` UNIQUE(pet_id, user_id)
   - `weight_logs(pet_id, record_date, weight_kg)` UNIQUE(pet_id, record_date) — 1 日 1 記録
   - `vaccinations(id, pet_id, vaccine_name, vaccinated_at, next_due_at)` + インデックス(`next_due_at`)
   - `vet_visits(id, pet_id, visit_date, clinic_name, diagnosis, treatment, cost_yen)`
2. 期限アラート: `WHERE next_due_at BETWEEN date('now') AND date('now', '+30 days')`.
3. 月別体重: `SELECT strftime('%Y-%m', record_date) AS month, AVG(weight_kg) FROM weight_logs WHERE pet_id=? GROUP BY month`.
4. 健康サマリー: 3 クエリを PHP で合成（OK、今回はパフォーマンス許容）。
5. アクセス制御: `pet_caretakers` または `pets.owner_id = :user_id` でアクセス権確認。

### ハマりポイント

- **`date('now', '+30 days')`**: SQLite の相対日付計算の構文確認が必要。
- **体重の UNIQUE(pet_id, record_date)**: 1 日複数回の計測を許可するかどうかの仕様決定。
  今回は「1 日 1 回」で統一。
- **費用(cost_yen)の管理**: 診察費用を保存するか否かはオプションにした（NULL 許可）。

### 解決策 & 感想

業務知識でスムーズに設計できた。SQLite の相対日付は確認が必要だった。

> 「`date('now', '+30 days')` って SQLite でできるの知らなかった。
>  ドキュメントないと分からないよな。
>  NENE2 の howto に SQLite 日付計算の cheatsheet があると嬉しい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。SQLite 相対日付計算の cheatsheet が欲しい。

---

## Persona C — 野口 美保（シニア・女性・40 歳）

### 背景

医療系 SaaS のアーキテクト 12 年。HIPAA 準拠の経験あり（動物医療は対象外だが意識）。

### 作業シナリオ

1. 設計（拡張性重視）:
   - `pets` + `pet_caretakers` + `weight_logs` + `vaccinations` + `vet_visits`
   - `vaccine_types(id, name, species, typical_interval_days)` — ワクチン種類マスター
   - `vaccinations.vaccine_type_id` で正規化
2. 期限アラート: `WHERE next_due_at BETWEEN date('now') AND date('now', '+30 days')`
   + `JOIN pets ON p.id = v.pet_id JOIN (pet_caretakers or owner)` でアクセス制御。
3. 体重トレンド: 月別平均 + 理想体重範囲（`breed_standards` テーブルから）との比較。
4. 健康サマリー: 単一 SQL で `JSON_OBJECT()` 集計は複雑なため PHP 合成を選択。
5. 通知: ワクチン期限アラートをプッシュ通知として実装したいが、
   NENE2 にプッシュ通知機能がないため「アラートフラグ」レスポンスで代替。

### ハマりポイント

- **`breed_standards` テーブル**: 品種ごとの理想体重範囲は種が多く、データセットの準備が大変。
  今回はシードデータとして限定的に用意。
- **SQLite `JSON_OBJECT()`**: SQLite 3.38 以降で使えるが、バージョン確認が必要。
- **アクセス制御の JOIN 複雑さ**: オーナー OR ケアテイカーの OR 条件を全クエリに付けるのが冗長。

### 解決策 & 感想

高品質で完成。アクセス制御 OR 条件の付与を共通化できると良かった。

> 「SQLite のバージョンによって使える機能が違うのは注意が必要。
>  NENE2 の動作確認済み SQLite バージョンを明示してほしい。
>  アクセス制御の共通化は NENE2 のミドルウェアでできるかもしれない。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。SQLite バージョン依存機能の確認方法と共通アクセス制御パターンが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 篠原（新卒） | ○ 基本機能完成 | 3/5 | 体重履歴設計、日付範囲フィルタ |
| 松井（ロースキル） | ○ 実用的完成 | 3/5 | SQLite 相対日付計算 |
| 野口（シニア） | ◎ 高品質完成 | 4/5 | SQLite バージョン依存機能、共通 ACL |

**共通のフリクション**:
1. **SQLite 日付計算 cheatsheet** — `date('now', '+30 days')` 等の相対日付計算（複数シナリオで言及）。
2. **SQLite バージョン依存機能の確認方法** — `JSON_OBJECT()` / FTS5 / 計算カラム等の対応バージョン。
3. **共通アクセス制御パターン** — OR 条件（owner OR member）を全クエリに付ける共通化方法。
