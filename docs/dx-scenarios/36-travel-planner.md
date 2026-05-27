# DX Scenario 36: 旅行プランナー

## アプリ概要

旅程・スポット・費用・共有を管理する旅行計画 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 旅程管理 | `POST /trips`（title, destination, start_date, end_date）|
| 日程作成 | `POST /trips/{id}/days`（day_number, date, notes）|
| スポット追加 | `POST /trips/{id}/days/{day}/spots`（name, category, time, address, booking_ref）|
| 費用記録 | `POST /trips/{id}/expenses`（category, amount_jpy, paid_by, description, date）|
| 費用割り勘 | `GET /trips/{id}/expenses/split`（参加者ごとの支払い額・受け取り額）|
| 旅行共有 | `POST /trips/{id}/members`（user_id, role: viewer/editor）|
| 旅行サマリー | `GET /trips/{id}/summary`（総費用・カテゴリ別・参加者別）|

ポイント: 日程 × スポットの階層、費用割り勘計算、複数メンバーの共有と権限。

---

## Persona A — 石井 理奈（新卒・女性・23 歳）

### 背景

観光学部卒でエンジニアに転向。旅行が趣味で「自分用に作りたい」動機が強い。

### 作業シナリオ

1. `trips` / `trip_spots` テーブルを作成（日程テーブルを省略して直接スポットに日付を持たせる）。
2. 費用割り勘を「合計費用 ÷ 参加者数」のシンプルな均等割で実装。
3. 費用を `expenses.amount REAL` で保存（精度問題あり）。
4. 旅行共有は `trips.shared_with TEXT`（コンマ区切り）で実装。
5. スポットの順序を考慮せず（`id` 順で表示）。

### ハマりポイント

- **費用の REAL 型**: 精度問題（`3000 / 3 = 999.9999...`）が発生。
- **不均等割り勘**: 「A が多く払った場合、誰に何円請求する」計算が複雑。
- **日程テーブルの省略**: スポットの日付ソートが複雑になった。

### 解決策 & 感想

`trip_days(trip_id, day_number, date)` テーブルを追加してスポットを日程に紐付け。
費用は `amount_yen INTEGER`（整数）に変更。

> 「整数演算って大事なんだ。割り勘の計算で小数になったら困ると知った。
>  日程テーブル作らなかったら後でソートが大変だった。」

### DX スコア: ⭐⭐⭐（3/5）

整数演算と階層設計を学習。割り勘計算ロジックの howto があれば助かる。

---

## Persona B — 桂 裕之（ロースキル・男性・33 歳）

### 背景

旅行会社の IT 担当 8 年。旅程管理の業務知識あり。

### 作業シナリオ

1. テーブル設計:
   - `trips(id, owner_id, title, destination, start_date, end_date, status)`
   - `trip_days(trip_id, day_number, date)` UNIQUE(trip_id, day_number)
   - `trip_spots(id, day_id, name, category, visit_time, address, order_index)`
   - `trip_expenses(id, trip_id, category, amount_yen, paid_by, description, expense_date)`
   - `trip_members(trip_id, user_id, role)` UNIQUE(trip_id, user_id)
2. 費用割り勘計算:
   - 総費用 = `SUM(amount_yen)`
   - 各人の支払い額 = `SUM(amount_yen) WHERE paid_by=user_id`
   - 理想分担 = `総費用 / 参加者数`（整数除算＋余りを適当に分配）
   - 差額 = 支払い額 - 理想分担 → プラスは受け取り、マイナスは支払い
3. アクセス制御: `trip_members` または `trips.owner_id = :user_id` チェック。
4. `GET /trips/{id}/summary` は PHP で費用を集計して返す（SQL も可だが今回は PHP）。

### ハマりポイント

- **割り勘の端数**: 3 人で 1000 円を割ると `333, 333, 334` になる。端数をどこに押し付けるか。
- **旅行ステータス**: `planning/ongoing/completed` の遷移管理。日付から自動計算するか手動か。
- **スポットカテゴリ**: 「観光/食事/宿泊/交通」のカテゴリを列挙するか自由入力にするか。

### 解決策 & 感想

割り勘は「最後の人が端数を持つ」ルールで実装。旅行ステータスは日付から計算で自動判定。

> 「割り勘の端数処理って地味に考えることある。
>  整数演算でどう処理するかの howto があれば迷わなかった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。整数演算の端数処理と階層データ設計が改善点。

---

## Persona C — 沖田 千恵（シニア・女性・38 歳）

### 背景

旅行 Tech スタートアップのテックリード 10 年。グローバルな旅行サービスの設計経験あり。

### 作業シナリオ

1. 設計（通貨対応を意識）:
   - `trip_expenses(id, trip_id, currency_code, amount_cents, exchange_rate_to_jpy, amount_yen_equiv)` — 外貨対応
   - `trip_members(trip_id, user_id, role, share_ratio)` — 不均等割り勘対応
2. 割り勘計算:
   - `share_ratio` を 1-100 の整数パーセントで管理（合計 100 になるよう検証）。
   - 各人の分担 = `total_yen * share_ratio / 100`
   - 誰が誰にいくら払うかの「精算グラフ」を最小化アルゴリズムで計算。
3. 旅行ステータスは `trips.status` を日付計算で返すが DB には保存しない。
4. スポットの `google_place_id` を参照データとして保存（外部 API との統合余地）。
5. 費用の通貨換算は入力時点のレートで `amount_yen_equiv` に保存（変動しない）。

### ハマりポイント

- **精算最小化アルゴリズム**: N 人の債務グラフを最小のトランザクション数で精算する
  アルゴリズムを PHP で実装した（greedy 法）。
- **`share_ratio` の合計検証**: `SUM(share_ratio) = 100` の UseCase レベルのバリデーション。
- **外貨の整数管理**: `amount_cents` を各通貨のサブ単位（JPY は 1 円単位）で管理。

### 解決策 & 感想

高品質で完成。精算最小化アルゴリズムは自力実装したが楽しかった。

> 「精算最小化アルゴリズムは NENE2 とは関係ないビジネスロジックだけど、
>  PHP でのアルゴリズム実装パターンの howto があれば参考になる。
>  多通貨の整数管理は決済系と共通のパターン。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。多通貨管理と割り勘精算アルゴリズムのパターンが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 石井（新卒） | ○ 整数演算を学習 | 3/5 | REAL 型精度問題、日程階層設計 |
| 桂（ロースキル） | ○ 実用的完成 | 3/5 | 端数処理ルール、旅行ステータス計算 |
| 沖田（シニア） | ◎ 高品質完成 | 4/5 | 多通貨管理、精算最小化アルゴリズム |

**共通のフリクション**:
1. **整数演算の端数処理** — 割り勘・税額計算で繰り返される問題。howto の丸め方針集。
2. **階層データ（旅程 → 日程 → スポット）の設計** — 3 レベル以上の階層設計パターン。
3. **費用の整数（セント/最小単位）管理** — 通貨ごとのサブ単位と整数演算（複数シナリオで言及）。
