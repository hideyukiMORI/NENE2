# DX Scenario 29: 顧客管理 (CRM)

## アプリ概要

顧客・商談・フォローアップ・タグを管理する CRM API。

| 機能 | エンドポイント例 |
|------|----------------|
| 顧客管理 | `GET /customers`, `POST /customers`（name, company, email, phone） |
| 顧客検索 | `GET /customers?q=田中&tag=VIP&assigned_to=5` |
| 商談管理 | `POST /customers/{id}/deals`（title, amount, stage, expected_close）|
| 商談更新 | `PATCH /deals/{id}/stage`（stage の遷移：lead→qualified→proposal→closed_won/lost）|
| アクティビティ | `POST /customers/{id}/activities`（type: call/email/meeting, note, occurred_at）|
| フォローアップ | `POST /customers/{id}/follow-ups`（due_date, note）|
| 期限切れ一覧 | `GET /follow-ups/overdue` |
| パイプライン | `GET /deals/pipeline`（ステージ別件数・合計金額）|

ポイント: 商談のファネルステージ遷移、パイプライン集計、タグの多対多、フォローアップ期限管理。

---

## Persona A — 門田 隆一（新卒・男性・25 歳）

### 背景

経営学部卒で営業志望だったがシステム職に。「CRM は Salesforce のことでしょ」という理解。

### 作業シナリオ

1. `customers` / `deals` テーブルを作成。
2. 商談ステージを `deals.stage TEXT` で保存。遷移ルールの概念がない。
3. アクティビティを `deals.last_activity TEXT` カラムで上書き管理。
4. フォローアップを `customers.next_followup DATE` カラム 1 つで管理（1 件しか持てない）。
5. パイプライン集計を PHP ループで実装。

### ハマりポイント

- **ステージ遷移の概念**: 「lost → qualified」の逆行が可能な設計。
- **アクティビティログ**: 過去のアクティビティ履歴が消える設計。
- **フォローアップの複数件**: 1 カラムでは複数のフォローアップを管理できない。

### 解決策 & 感想

`activities(customer_id, type, note, occurred_at)` テーブルと
`follow_ups(customer_id, due_date, note, completed_at)` テーブルを追加。
商談遷移は `state-machine-workflow-api.md` を参照した。

> 「CRM って履歴がめっちゃ大事なんだと分かった。
>  Salesforce のすごさが少し理解できた。
>  state-machine howto を早めに読むべきだった。」

### DX スコア: ⭐⭐⭐（3/5）

state-machine howto 活用で改善。CRM ドメイン固有のパターン説明が欲しい。

---

## Persona B — 下條 美智代（ロースキル・女性・36 geq 歳）

### 背景

IT 商社の営業サポート 10 年。Salesforce の利用者として日常的に CRM を使っている。

### 作業シナリオ

1. テーブル設計:
   - `customers(id, name, company, email, phone, assigned_to, created_at)`
   - `customer_tags(customer_id, tag_id)` + `tags(id, name)`
   - `deals(id, customer_id, title, amount_yen, stage, expected_close, created_at)`
   - `activities(id, customer_id, deal_id, type, note, occurred_at)`
   - `follow_ups(id, customer_id, due_date, note, completed_at)`
2. ステージ遷移を `state-machine-workflow-api.md` のパターンで実装。
   `closed_won` / `closed_lost` はターミナルステート。
3. パイプライン集計:
   ```sql
   SELECT stage, COUNT(*) AS deal_count, SUM(amount_yen) AS total_amount
   FROM deals WHERE stage NOT IN ('closed_won','closed_lost')
   GROUP BY stage
   ```
4. 期限切れフォローアップ: `WHERE due_date < date('now') AND completed_at IS NULL`。
5. 顧客検索は `WHERE 1=1` 条件分岐 + `customer_tags` JOIN。

### ハマりポイント

- **タグ AND 検索**: 「VIP かつ 既存顧客」のタグ AND 検索は `HAVING COUNT(DISTINCT)` が必要
  （前シナリオ 22 と同様の問題）。
- **`activities.deal_id`**: アクティビティが顧客レベルか商談レベルかで NULL 許可設計。
- **パイプライン集計**: ステージの順序（funnel の順番）を SQL では保証できないため、
  PHP 側でソートした。

### 解決策 & 感想

業務知識で設計はスムーズ。タグ AND 検索は前回の学習を活かした。

> 「HAVING COUNT(DISTINCT) のパターン、二度目なので今回は詰まらなかった。
>  こういうパターンは一度覚えればいい。
>  howto に載ってれば初心者でも使えるのに。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。タグ AND 検索は繰り返しの問題。パイプラインのソート保証も課題。

---

## Persona C — 立花 誠（ベテラン・男性・48 歳）

### 背景

CRM ソフトウェア会社の元アーキテクト 20 年。Salesforce の内部設計に詳しい。

### 作業シナリオ

1. テーブル設計（CRM の標準パターン）:
   - `customers(id, assigned_to, status: active|inactive)` + `customer_tags` + `deal_scores(customer_id, score)`
   - `deals(id, customer_id, stage, amount_cents, probability_pct, expected_close)`
   - `deal_stage_history(deal_id, from_stage, to_stage, changed_by, changed_at)` — 商談変更監査
   - `activities(customer_id, deal_id, type, direction: inbound|outbound, note, occurred_at)`
2. パイプライン集計は「ステージ定義テーブル」から JOIN して順序を保証:
   ```sql
   SELECT sd.order_index, sd.name AS stage, COUNT(d.id) AS count, COALESCE(SUM(d.amount_cents),0) AS total
   FROM stage_definitions sd
   LEFT JOIN deals d ON d.stage = sd.name AND d.stage NOT IN ('closed_won','closed_lost')
   GROUP BY sd.name ORDER BY sd.order_index
   ```
3. 商談の `weighted_amount = amount_cents * probability_pct / 100` を派生値として計算して返す。
4. 顧客スコアリング: `deal_scores` テーブルに定期的に更新（今回は手動更新 API で代替）。
5. `PATCH /customers/{id}` で `assigned_to` を変更時にアクティビティログに「担当者変更」を記録。

### ハマりポイント

- **ステージ定義テーブル**: `stage_definitions(name, order_index)` を別テーブルで持つか、
  コードで定義するかの選択。DB に持てばパイプライン順序をクエリで保証できる。
- **`weighted_amount` の保存 vs 計算**: 保存すると更新が必要。今回は毎回計算を選択。
- **担当者変更の自動記録**: UseCase 内での自動アクティビティ生成ロジックの置き場所。

### 解決策 & 感想

高品質で完成。ステージ定義テーブルのパターンは CRM では重要だった。

> 「ステージ定義をコードに書くか DB に持つかは設計の選択。
>  DB に持つとパイプライン集計が綺麗に書けた。
>  NENE2 のアーキテクチャ設計で『マスターデータを DB に持つパターン』の
>  howto があると参考になる。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。マスターデータテーブルのパターンと自動アクティビティ記録の howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 門田（新卒） | ○ state-machine 活用で改善 | 3/5 | CRM ドメイン知識、アクティビティログ設計 |
| 下條（ロースキル） | ○ 業務知識で完成 | 3/5 | タグ AND 検索（繰り返し）、パイプラインソート |
| 立花（ベテラン） | ◎ 高品質完成 | 4/5 | マスターデータ DB 管理、自動アクティビティ記録 |

**共通のフリクション**:
1. **タグ AND 検索 HAVING パターン** — 複数シナリオで繰り返し言及。最重要 howto 候補の一つ。
2. **マスターデータのテーブル管理** — コード定義 vs DB テーブルのトレードオフ howto。
3. **自動副作用ロジックの置き場所** — 「A を変更したら B に記録する」パターンのアーキテクチャ。
