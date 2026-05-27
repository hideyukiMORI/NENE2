# DX Scenario 47: フィットネストラッカー

## アプリ概要

エクササイズ・セット・目標・週次サマリーを管理するフィットネストラッキング API。

| 機能 | エンドポイント例 |
|------|----------------|
| エクササイズ一覧 | `GET /exercises`（name, muscle_group, equipment_required）|
| ワークアウト記録 | `POST /workouts`（date, notes）|
| セット記録 | `POST /workouts/{id}/sets`（exercise_id, weight_kg, reps, set_number）|
| 最高記録 | `GET /exercises/{id}/personal-best`（最大重量・最大レップ数）|
| ボリューム計算 | `GET /workouts/{id}/volume`（セット × レップ × 重量）|
| 目標管理 | `POST /goals`（exercise_id, target_weight_kg, target_date）|
| 週次サマリー | `GET /stats/weekly?week=2026-W22`（頻度・ボリューム・筋肉群別割合）|
| 進捗グラフ | `GET /exercises/{id}/progress?days=90`（重量の時系列推移）|

ポイント: ボリューム計算（重量×レップ×セット数の積）、最高記録（1RM換算含む）、週単位の集計。

---

## Persona A — 佐久間 大河（新卒・男性・23 歳）

### 背景

体育系大学でスポーツ科学を学んだエンジニア 1 年目。トレーニングの知識は豊富。

### 作業シナリオ

1. `workouts(id, user_id, date, notes)` と `workout_sets(id, workout_id, exercise_name, weight, reps)` テーブル。
   `exercise_name` を TEXT で直接保存（`exercises` テーブルなし）。
2. 最高記録を PHP でループして `max()` で探す（全レコード取得）。
3. ボリュームを「1 セットのボリューム = weight * reps」として各セットを PHP で合計。
4. 週次サマリーを「今週の日付」を PHP で計算してクエリに渡す。
5. 1RM 換算（Epley 式: `weight * (1 + reps / 30)`）を PHP 計算で返す。

### ハマりポイント

- **エクササイズ正規化**: `exercise_name` を TEXT のまま保存すると「ベンチプレス」「Bench Press」が別物になる。
- **最高記録の SQL**: `MAX(weight) GROUP BY exercise_id` ではなく「重量が最大のセット全体」が欲しい。
- **週の定義**: 「月曜始まり」の週 vs ISO 週（`strftime('%W', date)` は日曜始まり）。

### 解決策 & 感想

`exercises(id, name, muscle_group, equipment)` テーブルを追加。
週は `strftime('%Y-W%W', date)` では不十分で、PHP で月曜日を計算して渡すことにした。

> 「トレーニングアプリをコードにするのは楽しかった。
>  でもエクササイズの正規化を最初から考えるべきだった。
>  週の定義が日本語で曖昧（月曜始まり vs 日曜始まり）で、
>  SQLite の strftime がどちらの週を返すか howto に書いてほしかった。」

### DX スコア: ⭐⭐⭐（3/5）

正規化の重要性を学習。SQLite 週番号の定義 howto が欲しい。

---

## Persona B — 峯岸 洋子（ロースキル・女性・39 歳）

### 背景

フィットネスクラブの IT 担当 10 年。会員・予約・コーチ管理システムの運用経験あり。

### 作業シナリオ

1. テーブル設計:
   - `exercises(id, name, muscle_groups_json, equipment: barbell/dumbbell/machine/bodyweight, unit: kg/lb/bodyweight)`
   - `workouts(id, user_id, workout_date, duration_minutes, notes)`
   - `workout_sets(id, workout_id, exercise_id, set_number, weight_kg, reps, is_warmup)`
   - `goals(id, user_id, exercise_id, target_weight_kg, target_reps, target_date, achieved_at)`
2. 最高記録（ベスト重量）:
   ```sql
   SELECT weight_kg, reps, workout_date FROM workout_sets ws
   JOIN workouts w ON w.id = ws.workout_id
   WHERE ws.exercise_id = ? AND w.user_id = ? AND is_warmup = 0
   ORDER BY weight_kg DESC, reps DESC LIMIT 1
   ```
3. ボリューム: `SELECT SUM(weight_kg * reps) AS total_volume FROM workout_sets WHERE workout_id = ?`
4. 週次集計:
   ```sql
   SELECT strftime('%Y-%W', workout_date) AS week,
     COUNT(DISTINCT workout_date) AS workout_days,
     SUM(ws.weight_kg * ws.reps) AS total_volume
   FROM workouts w JOIN workout_sets ws ON ws.workout_id = w.id
   WHERE w.user_id = ? GROUP BY week
   ```
5. 筋肉群別割合: `muscle_groups_json` を PHP で `json_decode()` して集計。

### ハマりポイント

- **`strftime('%Y-%W', date)` の週定義**: SQLite は日曜始まりの週番号（ISO 8601 とは異なる）。
  月曜始まりの週番号が必要な場合は `strftime('%Y-%W', date(date, 'weekday 1', '-6 days'))` のような調整が必要。
- **`muscle_groups_json` の集計**: JSON 配列を行として展開する `json_each()` の使い方。
- **ウォームアップセットの除外**: 最高記録からウォームアップ（`is_warmup = 1`）を除外すべきか。

### 解決策 & 感想

週番号問題は PHP 側で月曜日の日付を計算して `WHERE workout_date BETWEEN ? AND ?` に変換して対応。

> 「strftime の週番号が日曜始まりなのはハマった。
>  ISO 週番号（月曜始まり）が欲しかったのに、SQLite には直接サポートがない。
>  PHP で週の月曜日を計算してから WHERE で絞るほうがシンプルだった。
>  json_each() でJSON配列を集計できるのは後で知って便利だった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。SQLite 週番号の ISO 対応と json_each() の howto が欲しい。

---

## Persona C — 近藤 雅史（ベテラン・男性・44 歳）

### 背景

ヘルステック系スタートアップの CTO 15 年。ウェアラブルデバイスとの API 統合経験あり。

### 作業シナリオ

1. テーブル設計（将来のデバイス統合対応）:
   - `exercises` に `met_value` (Metabolic Equivalent) を追加（消費カロリー計算用）
   - `workout_sets` に `rest_seconds` と `tempo_json` を追加
   - `body_measurements(user_id, measured_at, weight_kg, body_fat_pct, muscle_mass_kg)` — 体組成記録
   - `workout_summaries(workout_id, total_volume_kg, estimated_calories, muscle_groups_json, computed_at)` — 計算済みサマリー
2. 1RM 換算の保存: セット記録時にトランザクション内で Epley 式で計算・保存。
   `personal_bests(user_id, exercise_id, estimated_1rm_kg, achieved_at)` で管理。
3. 週次サマリー: PHP で ISO 週（月曜始まり）を計算して `workout_summaries` を集計。
4. 進捗グラフ: `personal_bests` の時系列で推移を返す（INSERT より多い SELECT に最適化）。
5. 目標達成チェック: `personal_bests.estimated_1rm_kg >= goals.target_weight_kg` でトリガー判定。

### ハマりポイント

- **Epley 式の精度**: `weight * (1 + reps / 30.0)` の浮動小数点計算（PHP で intdiv か round か）。
- **`workout_summaries` の同期**: ワークアウトにセットを追加するたびにサマリーを再計算する設計。
- **消費カロリーの計算**: `MET × body_weight_kg × duration_hours` でカロリー推定（体重が必要）。

### 解決策 & 感想

1RM は REAL 型で `ROUND(..., 1)` 精度。サマリーは lazy（ワークアウト完了時に更新）で設計。

> 「フィットネスドメインは計算が多いので数値型の精度設計が重要。
>  1RM は 0.5kg 精度で十分なので ROUND(x, 1) で保存。
>  workout_summaries の同期を 'eager（毎セット更新）' か 'lazy（完了時更新）' か迷ったが、
>  ユーザーがワークアウト中は参照しないので lazy にした。これは設計判断の howto に使える例。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。Eager/Lazy 更新の設計判断と消費カロリー計算が改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 佐久間（新卒） | ○ エクササイズ正規化を習得 | 3/5 | SQLite 週番号定義、エクササイズ正規化 |
| 峯岸（ロースキル） | ○ 実用的完成 | 3/5 | strftime 週番号 ISO 対応、json_each() |
| 近藤（ベテラン） | ◎ 高品質完成 | 4/5 | Eager/Lazy 更新設計判断、1RM 精度 |

**共通のフリクション**:
1. **SQLite 週番号（ISO 週 vs 日曜始まり）** — `strftime('%W')` の動作と月曜始まりへの変換方法 howto。
2. **JSON 配列の集計** — `json_each()` を使った JSON カラムの行展開と GROUP BY パターン。
3. **集計サマリーテーブルの更新タイミング** — Eager（都度更新）vs Lazy（完了時更新）vs バッチの設計指針。
