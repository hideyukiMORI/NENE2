# DX Scenario 24: 健康記録

## アプリ概要

体重・血圧・睡眠時間・グラフ用統計を管理する健康記録 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 体重記録 | `POST /health/weight`（date, value_kg） |
| 血圧記録 | `POST /health/blood-pressure`（date, systolic, diastolic, pulse） |
| 睡眠記録 | `POST /health/sleep`（date, sleep_at, wake_at） |
| 記録取得 | `GET /health/weight?from=2026-01-01&to=2026-01-31` |
| 統計 | `GET /health/stats?metric=weight&period=monthly`（平均・最大・最小） |
| 最新値 | `GET /health/latest`（全メトリクスの最新値） |
| 目標設定 | `POST /health/goals`（metric, target_value, deadline） |

ポイント: 複数メトリクスの統一インターフェース、期間統計クエリ（週次/月次集計）、目標比較。

---

## Persona A — 塚田 春奈（新卒・女性・22 歳）

### 背景

看護系専門学校卒後プログラミングスクールを経てエンジニアに。健康データの概念は医療知識あり。

### 作業シナリオ

1. `health_records` テーブルに `type TEXT, value REAL` で全メトリクスを 1 テーブルに保存。
2. 血圧は「収縮期」と「拡張期」の 2 つの値があるため、`health_records` 1 テーブルでは
   保存できないことに気づき詰まる。
3. 睡眠時間計算 `julianday(wake_at) - julianday(sleep_at)` を知らず PHP で計算。
4. 週次集計を PHP で `array_chunk()` して計算する実装にした。
5. 目標設定テーブルはとりあえず作るが、「現在値と目標値の比較」API が未実装。

### ハマりポイント

- **血圧の複数値**: 収縮期・拡張期の 2 値を同一日時で持つには別テーブルか EAV が必要。
- **睡眠時間の計算**: `julianday()` の使い方を知らない。
- **週次/月次集計**: SQL の `strftime()` での集計が分からない。

### 解決策 & 感想

血圧専用テーブル `blood_pressure_records(date, systolic, diastolic, pulse)` を追加した。
SQLite の日付関数は前シナリオの学習があったので参照できた。

> 「血圧って 2 つの数字があるの分かってたけど、
>  どうDB設計するか悩んだ。専用テーブルが一番シンプルだった。
>  SQLite の日付関数は慣れてきた。」

### DX スコア: ⭐⭐⭐（3/5）

医療知識を活用して設計できた。メトリクス別テーブル vs 汎用テーブルの選択 howto が欲しい。

---

## Persona B — 宇野 浩之（ロースキル・男性・44 歳）

### 背景

ヘルスケア系 SaaS の運用担当 10 年。ウェアラブルデバイスのデータ管理経験あり。

### 作業シナリオ

1. テーブル設計（メトリクス別テーブル）:
   - `weight_records(user_id, record_date, value_kg)`
   - `blood_pressure_records(user_id, record_date, systolic_mmhg, diastolic_mmhg, pulse_bpm)`
   - `sleep_records(user_id, record_date, sleep_at, wake_at, duration_minutes)`
2. 睡眠時間: `duration_minutes = CAST((julianday(wake_at) - julianday(sleep_at)) * 1440 AS INTEGER)` で保存。
3. 月次統計:
   ```sql
   SELECT strftime('%Y-%m', record_date) AS month,
     AVG(value_kg) AS avg, MAX(value_kg) AS max, MIN(value_kg) AS min
   FROM weight_records WHERE user_id=? GROUP BY month ORDER BY month
   ```
4. `GET /health/latest` は各テーブルから `ORDER BY record_date DESC LIMIT 1` を
   別々に叩いて PHP で合成。
5. 目標比較: `goals` テーブルと最新値を JOIN して `current_value / target_value` で進捗率計算。

### ハマりポイント

- **`GET /health/latest` の複数クエリ**: 3 テーブルを個別に叩くと 3 クエリ発行。
  `UNION ALL` でまとめる方法を検討したが列数が違うため断念。
- **1 日複数回の記録**: 「1 日 1 件」なのか「複数回計測可能」なのかの仕様が曖昧。
  `UNIQUE(user_id, record_date)` を入れるかどうか迷った。
- **`duration_minutes` の保存**: 計算して保存か、都度 `julianday()` 計算かを迷い、保存を選択。

### 解決策 & 感想

業務知識で設計はスムーズ。複数クエリの合成は「今回はシンプルに PHP で」で妥協。

> 「1 日複数回の血圧測定は医療的にアリだから UNIQUE は入れなかった。
>  仕様が曖昧なまま実装すると後でトラブルになるから、
>  仕様を先に決める重要性を感じた。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。1 日複数回の仕様決定と `UNION ALL` パターンの説明が欲しい。

---

## Persona C — 笠原 恵美（シニア・女性・38 歳）

### 背景

ヘルステック系スタートアップの CTO。FDA の医療データ規制（HIPAA 等）の知識あり。
「ヘルスデータは特に正確性と監査証跡が重要」という信念。

### 作業シナリオ

1. テーブル設計（記録の不変性重視）:
   - 各メトリクステーブルに `recorded_by_device TEXT`（デバイス名）と
     `recorded_at TIMESTAMP` を追加
   - 過去の記録は DELETE/UPDATE 不可（論理削除のみ）
2. 月次統計は専用 `HealthStatsQuery` UseCase として実装。
   `strftime('%Y-%W', record_date)` で週次集計も対応。
3. `GET /health/latest` は各テーブルを `UNION ALL` で疑似的に結合して PHP でグループ化:
   ```sql
   SELECT 'weight' AS metric, record_date, CAST(value_kg AS TEXT) AS value FROM weight_records WHERE user_id=? ORDER BY record_date DESC LIMIT 1
   UNION ALL
   SELECT 'sleep' AS metric, record_date, CAST(duration_minutes AS TEXT) AS value FROM sleep_records WHERE user_id=? ORDER BY record_date DESC LIMIT 1
   ```
4. 目標比較と進捗: `HealthGoalComparisonUseCase` で現在値 vs 目標値の構造化レスポンスを返す。
5. OpenAPI のレスポンス定義に各メトリクスの単位（kg, mmHg, minutes）を記載。

### ハマりポイント

- **`UNION ALL` の列数統一**: `CAST(value AS TEXT)` で型を統一したが、血圧は 2 値あるため
  血圧を `UNION ALL` に含めるのは複雑。血圧だけ別処理にした。
- **`strftime('%Y-%W', ...)` の週番号**: SQLite の週番号が月曜始まりか日曜始まりか確認が必要。
- **データの不変性**: 過去の記録を編集不可にする設計はセキュリティ的に良いが、
  「入力ミスを修正したい」要求との相反を設計で解決する必要があった（訂正レコード追加）。

### 解決策 & 感想

高品質で完成。`UNION ALL` の血圧例外は「別ルートで取得」として割り切った。

> 「不変ログ設計は医療データには適しているけど、
>  入力ミス訂正の仕組みも必要。
>  append-only log で訂正をどう実現するかの howto があると参考になる。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。append-only ログの訂正パターンと SQLite 週番号の仕様説明が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 塚田（新卒） | ○ 改善後完成 | 3/5 | 複数値メトリクスの設計、SQLite 日付関数 |
| 宇野（ロースキル） | ○ 実用的完成 | 3/5 | 仕様の曖昧さ解決、複数クエリの合成 |
| 笠原（シニア） | ◎ 高品質完成 | 4/5 | append-only ログの訂正、SQLite 週番号 |

**共通のフリクション**:
1. **メトリクス別テーブル vs 汎用テーブルの設計指針** — EAV vs 専用テーブルのトレードオフ。
2. **SQLite 日付関数の週番号・タイムゾーン** — `strftime('%Y-%W')` の挙動説明（複数シナリオで言及）。
3. **append-only ログの訂正パターン** — 不変ログで入力ミスを訂正する「correction record」設計。
