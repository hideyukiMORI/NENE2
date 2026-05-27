# DX Scenario 43: 資産台帳

## アプリ概要

固定資産の登録・減価償却・廃棄・QR コード管理を行う資産台帳 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 資産登録 | `POST /assets`（name, category, purchase_date, purchase_price_yen, useful_life_years）|
| 資産一覧 | `GET /assets?category=PC&location=3F&status=active` |
| 減価償却 | `GET /assets/{id}/depreciation-schedule`（年次償却計画）|
| 現在価値 | `GET /assets/{id}/current-value`（今日時点の簿価）|
| 廃棄処理 | `POST /assets/{id}/dispose`（reason, disposed_at）|
| QR コード | `GET /assets/{id}/qr-code`（資産 ID を含む QR データ）|
| QR スキャン | `GET /assets/by-qr/{code}` |
| 年次集計 | `GET /assets/annual-summary?year=2026`（カテゴリ別取得・償却・廃棄）|

ポイント: 減価償却の計算（定額法/定率法）、QR コードの一意性、廃棄後の残存価値記録。

---

## Persona A — 吉川 拓磨（新卒・男性・24 geq 歳）

### 背景

商学部で「会計学」を履修。減価償却の概念は知っているがプログラミングで実装は初。

### 作業シナリオ

1. `assets(id, name, category, purchase_price, purchase_date, useful_life_years)` テーブル。
2. 減価償却を「purchase_price / useful_life_years」の年次定額で PHP 計算。
   `REAL` 型で計算（精度問題あり）。
3. QR コードを「`qr_{id}_{random}`」という文字列で生成。実際の QR データは未実装。
4. 廃棄処理を物理削除で実装（廃棄記録なし）。
5. 現在価値計算: `purchase_price - (depreciation_per_year * years_elapsed)` を PHP で計算。

### ハマりポイント

- **整数演算**: 減価償却の金額を REAL で計算すると端数が出る。
- **廃棄記録**: 会計上、廃棄した資産の記録は保持義務がある。
- **QR コード**: 「QR コードのデータ」と「QR コード画像生成」は別の問題。

### 解決策 & 感想

`purchase_price_yen` を INTEGER に変更。廃棄は `disposed_at TEXT` + `disposal_value_yen` で管理。

> 「会計学で習ったことを API にするのは全然違う難しさがある。
>  整数演算の重要性が改めて分かった。
>  QR コードって API で返すのは 'データ' だけで、表示はフロントの仕事なんだと理解した。」

### DX スコア: ⭐⭐⭐（3/5）

会計知識を活かした設計。整数演算と廃棄記録の概念で詰まった。

---

## Persona B — 柴田 明美（ロースキル・女性・42 geq 歳）

### 背景

中小製造業の経理担当 15 年。固定資産台帳を Excel で管理してきた。

### 作業シナリオ

1. テーブル設計（会計実務に基づく）:
   - `assets(id, name, category, location, status: active/disposed, purchase_date, purchase_price_yen, useful_life_years, salvage_value_yen, qr_code)` UNIQUE(qr_code)
   - `disposals(asset_id, reason, disposal_date, book_value_yen_at_disposal, disposal_amount_yen)` — 廃棄記録
2. QR コード: `bin2hex(random_bytes(8))` で生成、資産登録時に割り当て。
3. 現在価値（定額法）:
   ```php
   $yearsElapsed = (int)(floor((today - purchase_date) / 365));
   $annualDepreciation = intdiv($purchase_price - $salvage_value, $useful_life_years);
   $currentValue = max($salvage_value, $purchase_price - $annualDepreciation * $yearsElapsed);
   ```
4. 減価償却スケジュール: 年次ループで PHP 配列として生成して返す。
5. 年次集計: `GROUP BY STRFTIME('%Y', purchase_date)` で取得年次別集計。

### ハマりポイント

- **残存価値の扱い**: 残存価値 0 yen で完全償却する場合と残存価値ありの場合で計算が変わる。
- **`useful_life_years` の端数**: 耐用年数 2.5 年のような場合（法的には整数が多いが）。
- **定率法の実装**: 定額法は実装できたが定率法（毎年帳簿価額の一定率を償却）は後回しにした。

### 解決策 & 感想

会計知識でスムーズに設計できた。定率法は「今回は定額法のみ実装」として省略。

> 「固定資産台帳って会計ルールが複雑。
>  税法上の耐用年数と会計上の耐用年数が違ったりするから、
>  アプリで完全対応しようとすると大変。
>  今回はシンプルな定額法のみで実装した。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。定額/定率法の切り替えと年数の端数処理が改善余地。

---

## Persona C — 田中 英則（シニア・男性・48 geq 歳）

### 背景

会計系 SaaS の開発リード 16 年。J-SOX 準拠のシステム設計経験あり。

### 作業シナリオ

1. テーブル設計（監査対応）:
   - `assets(id, asset_code, status)` + `asset_snapshots(asset_id, snapshot_date, book_value_yen)` — 年次スナップショット
   - `depreciation_method: straight_line|declining_balance` — 償却方法設定
   - `asset_history(asset_id, event_type, data_json, recorded_at)` — 全変更履歴
   - `disposals(asset_id, type: sale|scrap|transfer, disposal_value_yen, gl_account_code)`
2. 定額法: `annual_depreciation = intdiv(cost - salvage, life_years)`
   定率法: `annual_depreciation = (int)($book_value * $rate)` — 複利効果
3. QR コード: `bin2hex(random_bytes(16))` で 32 文字の一意コード。
   `assets/by-qr/{code}` エンドポイントで検索。
4. 年次スナップショット: `POST /admin/assets/annual-snapshot`（年次決算時に全資産の現在価値を記録）。
5. J-SOX 対応: 資産の変更・廃棄は全て `asset_history` に記録（append-only）。

### ハマりポイント

- **定率法の率**: 日本税法の償却率は耐用年数ごとに規定。ハードコードか設定可能にするか。
- **`asset_snapshots` の整合**: 年次スナップショット後に過去の記録を変更した場合の対応。
- **J-SOX の「修正不可」要件**: 一度記録した `asset_history` は変更できないよう UseCase でチェック。

### 解決策 & 感想

高品質で完成。J-SOX 要件への対応として append-only ログが重要だった。

> 「append-only ログは event-sourcing-cqrs-api.md のパターンがそのまま使えた。
>  会計系の不変ログ要件と event-sourcing は相性が良い。
>  定率法の償却率テーブルはシードデータとして持つべきだった。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。定率法の設定管理と append-only ログの活用が改善点の参考になる。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 吉川（新卒） | ○ 会計知識を活かした改善 | 3/5 | 整数演算、廃棄記録の重要性 |
| 柴田（ロースキル） | ○ 実用的完成 | 3/5 | 定率法の実装、端数処理 |
| 田中（シニア） | ◎ 高品質完成 | 4/5 | 定率法テーブル、append-only ログ活用 |

**共通のフリクション**:
1. **整数演算の金額計算** — 減価償却・手数料・割り勘で繰り返される。howto の最重要候補。
2. **append-only ログパターン** — `event-sourcing-cqrs-api.md` が会計・法務系に活用できる。発見パスの改善。
3. **会計ドメイン知識のプログラミング翻訳** — 「減価償却とは」から「どうテーブル設計するか」への橋渡し。
