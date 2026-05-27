# DX Scenario 42: 車両管理

## アプリ概要

車両・整備記録・燃費・アラートを管理するフリート車両管理 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 車両管理 | `GET /vehicles`, `POST /vehicles`（plate, make, model, year, mileage_km）|
| 整備記録 | `POST /vehicles/{id}/maintenance`（type, description, cost_yen, mileage_at_service）|
| 次回整備予定 | `GET /vehicles/{id}/next-maintenance`（走行距離または日程ベース）|
| 燃費記録 | `POST /vehicles/{id}/fuel-logs`（liters, cost_yen, mileage_at_fill）|
| 燃費統計 | `GET /vehicles/{id}/fuel-efficiency`（L/100km・円/km）|
| アラート | `GET /vehicles/alerts`（整備期限・保険更新・車検）|
| 走行距離更新 | `PATCH /vehicles/{id}/mileage`（current_mileage_km）|
| フリート統計 | `GET /fleet/summary`（総走行距離・総整備費用・燃費平均）|

ポイント: 走行距離ベースのアラート（次回整備まで X km）、燃費計算（2 記録間の距離と給油量）、期間ベースアラート（車検期限）。

---

## Persona A — 深谷 健太（新卒・男性・25 geq 歳）

### 背景

工業高校→自動車整備士→エンジニアに転向。車の知識は豊富。

### 作業シナリオ

1. `vehicles(id, plate, make, model, year, current_mileage)` テーブル。
2. 整備記録は `maintenance_records(vehicle_id, type, cost, date)` — 走行距離を記録しない。
3. 燃費計算を「(給油量 × 燃費) = 走行距離」という逆算で実装しようとして詰まる。
4. 次回整備を「最後の整備から 3000km 後」として計算しようとするが、
   最後の整備時の走行距離がないため計算できない。
5. アラートを PHP でループして判定（全車両を取得してチェック）。

### ハマりポイント

- **燃費計算の方式**: 燃費は「走行距離 ÷ 使用燃料」で計算するが、
  2 回の給油間の「走行距離」= 「今回給油時の走行距離 - 前回給油時の走行距離」が必要。
- **整備時の走行距離**: `mileage_at_service` がないと次回整備距離が計算できない。
- **距離ベースアラートの SQL**: `current_mileage - mileage_at_last_service >= interval_km` の条件。

### 解決策 & 感想

`mileage_at_service` と `mileage_at_fill` を追加して再設計。
燃費計算の方式を先輩に教わった。

> 「燃費計算って前回給油時の走行距離が必要って知らなかった。
>  車の知識はあるけど DB 設計に翻訳するのが難しかった。」

### DX スコア: ⭐⭐⭐（3/5）

業務知識を DB 設計に翻訳する支援が欲しい。

---

## Persona B — 清田 由美（ロースキル・女性・38 geq 歳）

### 背景

物流会社の車両管理担当 12 年。ERP の車両管理モジュールを運用してきた。

### 作業シナリオ

1. テーブル設計:
   - `vehicles(id, plate, make, model, year, current_mileage, insurance_expires_at, inspection_expires_at)`
   - `maintenance_records(id, vehicle_id, type, description, cost_yen, mileage_at_service, serviced_at)`
   - `maintenance_intervals(vehicle_id, maintenance_type, interval_km, interval_days)` — 整備間隔設定
   - `fuel_logs(id, vehicle_id, liters, cost_yen, mileage_at_fill, filled_at)`
2. 燃費計算:
   ```sql
   SELECT fl.mileage_at_fill - LAG(fl.mileage_at_fill) OVER (PARTITION BY fl.vehicle_id ORDER BY fl.filled_at) AS distance_km,
   fl.liters FROM fuel_logs fl WHERE fl.vehicle_id=?
   ```
   ※ SQLite の `LAG()` ウィンドウ関数（バージョン 3.25+）を使用。
3. 距離ベースアラート: `WHERE v.current_mileage - mi.mileage_at_fill >= mi.interval_km`
4. 期間ベースアラート: `WHERE inspection_expires_at BETWEEN date('now') AND date('now', '+30 days')`
5. フリート統計は GROUP BY でまとめて返す。

### ハマりポイント

- **`LAG()` ウィンドウ関数**: SQLite 3.25+ で使えるが、バージョン確認が必要。
  NENE2 の対応バージョンを確認した。
- **前回の給油記録がない場合**: `LAG()` の結果が NULL になる初回給油の扱い。
  `COALESCE(LAG, 0)` で 0 にするか NULL で除外するか。
- **整備間隔のデフォルト値**: メーカー推奨の整備間隔をシードデータとして持つべきか。

### 解決策 & 感想

`LAG()` ウィンドウ関数が使えて燃費計算がシンプルになった。

> 「LAG ウィンドウ関数って SQLite でも使えるの知らなかった。
>  NENE2 の howto に ウィンドウ関数の説明があれば早かった。
>  SQLite のバージョン依存機能の一覧が欲しい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。SQLite ウィンドウ関数の howto が欲しい。

---

## Persona C — 星野 誠（ベテラン・男性・46 geq 歳）

### 背景

物流 SaaS のアーキテクト 18 年。IoT センサーと連携した車両追跡の設計経験あり。

### 作業シナリオ

1. テーブル設計（IoT 連携対応）:
   - `vehicles` + `vehicle_telemetry_log(vehicle_id, mileage_km, recorded_at)` — IoT ログ（今回は手動更新で代替）
   - `maintenance_schedule(vehicle_id, type, next_due_mileage, next_due_date)` — 計算済みスケジュール
   - `fuel_logs` に `station_name, fuel_type(regular/premium/diesel)` を追加
2. 整備スケジュール: 整備記録 INSERT 時にトランザクション内で `maintenance_schedule` を更新。
3. 燃費計算: ウィンドウ関数 `LAG()` で効率的に計算し、結果を `fuel_efficiency_cache` に保存。
4. アラート: `maintenance_schedule.next_due_mileage - vehicles.current_mileage < alert_threshold_km`
   + `maintenance_schedule.next_due_date <= date('now', '+30 days')` を OR 条件で返す。
5. フリート統計を `fleet_stats_cache` テーブルで事前計算（日次更新）。

### ハマりポイント

- **`maintenance_schedule` の同期**: 整備記録追加・走行距離更新の両方でスケジュールを再計算する必要あり。
- **IoT デバイスの仮想実装**: 今回は「手動走行距離更新 API」で代替したが、本番では WebSocket や MQTT が必要。
- **燃費キャッシュの粒度**: 全給油の都度計算するか、月次平均に絞るかの設計判断。

### 解決策 & 感想

高品質で完成。IoT 連携は仮想実装で代替した。

> 「IoT との統合で NENE2 は弱い（リアルタイム処理が必要なため）。
>  ただしデータ保存と集計 API は NENE2 で十分できる。
>  SQLite ウィンドウ関数の howto と、IoT 的な高頻度 INSERT への対応ガイドがあれば嬉しい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。SQLite ウィンドウ関数と高頻度 INSERT への対応が改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 深谷（新卒） | ○ 整備時走行距離の重要性を習得 | 3/5 | 業務知識の DB 設計翻訳 |
| 清田（ロースキル） | ○ 実用的完成 | 3/5 | SQLite `LAG()` ウィンドウ関数 |
| 星野（ベテラン） | ◎ 高品質完成 | 4/5 | ウィンドウ関数、高頻度 INSERT |

**共通のフリクション**:
1. **SQLite ウィンドウ関数 (`LAG`, `LEAD`, `ROW_NUMBER`)** — 3.25+ 対応の使い方 howto。
2. **SQLite バージョン依存機能の一覧** — FTS5, ウィンドウ関数, 計算カラム等の対応バージョン表。
3. **計算済みスケジュール（次回日程）の同期パターン** — 「計算して保存する」vs「都度計算する」の設計指針。
