# DX Scenario 40: 寄付・クラウドファンディング

## アプリ概要

プロジェクト・目標金額・寄付・進捗を管理するクラウドファンディング API。

| 機能 | エンドポイント例 |
|------|----------------|
| プロジェクト | `POST /projects`（title, description, goal_amount_yen, deadline）|
| 寄付 | `POST /projects/{id}/pledges`（amount_yen, donor_name, message）|
| 進捗 | `GET /projects/{id}/progress`（達成率・残り日数・支援者数）|
| 支援者一覧 | `GET /projects/{id}/backers`（金額順・時系列）|
| プロジェクト一覧 | `GET /projects?status=active&sort=funded_pct` |
| 資金達成通知 | （目標金額達成時のフラグ）|
| 返金処理 | `POST /projects/{id}/refunds`（未達成プロジェクトの全額返金）|
| 手数料計算 | `GET /projects/{id}/fee-summary`（プラットフォーム手数料 5%）|

ポイント: 達成率の計算（集計 → 比較）、締め切り管理、未達成時の返金処理、手数料計算（整数演算）。

---

## Persona A — 梅田 恭子（新卒・女性・24 geq 歳）

### 背景

社会学部卒でエンジニアに転向。クラウドファンディングが好きで Kickstarter / CAMPFIRE を使う。

### 作業シナリオ

1. `projects(id, title, goal_amount_yen, deadline, status)` テーブル。
2. 寄付を `pledges(id, project_id, amount_yen, donor_name, message)` テーブル。
3. 達成率を PHP で `SUM(amount_yen) / goal_amount_yen * 100` で計算。
4. 手数料を `pledges.amount_yen * 0.05` で計算（REAL 型・精度問題）。
5. 返金処理は「全pledgeを削除する」物理削除で実装（返金履歴が消える）。

### ハマりポイント

- **手数料の REAL 型精度**: `1000 * 0.05 = 50.0` は問題ないが `1001 * 0.05 = 50.05...` で精度が出る。
- **返金の履歴**: 物理削除では返金記録が残らない。`refunds` テーブルが別途必要。
- **目標達成の自動検出**: 「寄付を受け付けるたびに達成判定する」ロジックの場所。

### 解決策 & 感想

手数料は `intdiv($amount, 20)` で整数計算（5% = 1/20）に修正。
返金は `refunds(pledge_id, amount_yen, reason, refunded_at)` テーブルを追加。

> 「手数料の小数計算って浮動小数点だとずれるの初めて知った。
>  整数で計算するって考え方が面白い。
>  返金は何かあった時の証明になるから削除しちゃダメだった。」

### DX スコア: ⭐⭐⭐（3/5）

整数演算と返金記録の概念を習得。金額計算の howto が欲しい。

---

## Persona B — 土屋 克也（ロースキル・男性・37 geq 歳）

### 背景

NPO の IT 担当 10 年。寄付管理システムの運用経験あり（Salesforce の非営利版）。

### 作業シナリオ

1. テーブル設計:
   - `projects(id, title, description, goal_amount_yen, deadline, status: draft/active/funded/failed/cancelled)`
   - `pledges(id, project_id, donor_name, donor_email, amount_yen, message, status: pending/confirmed/refunded)`
   - `refunds(id, pledge_id, amount_yen, reason, processed_at)`
2. 達成率: `(SUM(confirmed_pledges) / goal_amount * 100)` を SQL で計算。
   `ROUND(SUM * 100.0 / goal, 1)` で小数点 1 桁に丸め。
3. 手数料: `ROUND(amount_yen * 5 / 100)` で整数演算（ROUND は SQLite では四捨五入）。
4. 返金処理: `pledges.status = 'refunded'` に更新 + `refunds` テーブルに記録（トランザクション内）。
5. 未達成締め切りの自動処理: `POST /admin/projects/check-deadlines` で手動実行（バッチ代替）。

### ハマりポイント

- **SQLite の `ROUND()` の動作**: 整数に丸めるか小数点以下を指定するかの確認が必要。
- **5% の整数演算**: `amount * 5 / 100` は整数除算になる可能性（PHP では `int / int = float` なので問題ないが、SQLiteでは注意）。
- **達成後の追加寄付**: 目標達成後も寄付を受け付けるか（オーバーファンディング）の仕様。

### 解決策 & 感想

業務知識でスムーズに設計できた。SQLite の `ROUND()` の動作は確認が必要だった。

> 「`amount * 5 / 100` の演算順序で結果が変わるの注意。
>  `amount * 5.0 / 100` にすると REAL になる。
>  SQLite の整数除算のルールを howto に書いてほしい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。SQLite の整数/浮動小数点除算ルールの説明が欲しい。

---

## Persona C — 成田 彩子（シニア・女性・42 geq 歳）

### 背景

フィンテック系 NPO のシステムアーキテクト 14 年。寄付管理と会計の統合設計経験あり。

### 作業シナリオ

1. テーブル設計（会計的整合性）:
   - `pledges(id, project_id, amount_cents, platform_fee_cents, net_amount_cents, status)`
   - `platform_fee_cents = FLOOR(amount * fee_rate)` で保存（スナップショット）
   - `net_amount_cents = amount_cents - platform_fee_cents`
   - `project_stats(project_id, total_pledged_cents, backer_count, last_updated_at)` — スナップショット
2. 寄付受付時: トランザクション内で `pledges` INSERT + `project_stats` 更新 + 達成判定。
3. 達成判定: `total_pledged_cents >= goal_amount_cents` → `projects.status = 'funded'` 更新。
4. 返金: `refunds(pledge_id, amount_cents, type: full/partial, reason, processed_at)` で管理。
   返金時に `project_stats` を逆方向に更新。
5. 手数料集計: `GET /projects/{id}/fee-summary` で `SUM(platform_fee_cents)` を返す。

### ハマりポイント

- **`FLOOR()` vs `ROUND()`**: 手数料は `FLOOR`（切り捨て）が有利な場合（プラットフォームが多く受け取らない）。
  `project_stats` の逆更新と `FLOOR` の整合性。
- **達成後の寄付**: 達成後もストレッチゴールとして受付継続する設計。今回は停止に設定。
- **返金後の `project_stats` 修正**: 返金時に `total_pledged_cents` を減算するトランザクションが必要。

### 解決策 & 感想

高品質で完成。会計的整合性を保つための設計が正しかった。

> 「FLOOR か ROUND かは業界と要件によって変わる。
>  どちらを使うかを仕様として先に決めてドキュメントに書くことが重要。
>  NENE2 の howto でも『金額計算の丸め方針は先に決める』と書いてほしい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。金額計算の丸め方針の howto と達成判定の自動化パターンが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 梅田（新卒） | ○ 整数演算を習得 | 3/5 | REAL 型精度問題、返金記録 |
| 土屋（ロースキル） | ○ 実用的完成 | 3/5 | SQLite 整数/REAL 除算ルール |
| 成田（シニア） | ◎ 高品質完成 | 4/5 | FLOOR vs ROUND 方針決定 |

**共通のフリクション**:
1. **金額計算の丸め方針ドキュメント** — FLOOR/ROUND/intdiv の使い分け（複数シナリオで言及。最重要）。
2. **SQLite の整数/浮動小数点除算** — `5 / 100 = 0` になる整数除算の注意点。
3. **スナップショット統計 + イベント駆動更新パターン** — 寄付/注文系で頻出。専用 howto 価値高い。
