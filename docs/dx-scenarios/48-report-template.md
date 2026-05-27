# DX Scenario 48: 報告書テンプレート

## アプリ概要

テンプレート・セクション・入力値・PDF 出力データを管理する報告書作成 API。

| 機能 | エンドポイント例 |
|------|----------------|
| テンプレート管理 | `POST /templates`（title, description, category, sections[]）|
| セクション管理 | `POST /templates/{id}/sections`（title, field_type, order_index, required）|
| 報告書作成 | `POST /reports`（template_id, title）|
| フィールド入力 | `PUT /reports/{id}/fields`（section_id, value）|
| 提出 | `POST /reports/{id}/submit`（バリデーション → status 変更）|
| 承認フロー | `POST /reports/{id}/approve`（approver_id, comment）|
| PDF データ | `GET /reports/{id}/export`（セクション+値の構造化 JSON）|
| テンプレート複製 | `POST /templates/{id}/duplicate`（新しいバージョンとして複製）|

ポイント: テンプレートのバージョン管理、動的フィールドのバリデーション、セクションの順序管理、承認フロー。

---

## Persona A — 藤田 菜摘（新卒・女性・22 歳）

### 背景

情報系専門学校卒 1 年目。社内の週次報告書を電子化するタスクを担当。

### 作業シナリオ

1. `templates(id, title, fields_json)` — セクションを JSON カラムに全部詰め込む設計。
2. 報告書を `reports(id, template_id, values_json, status)` で管理 — 値も JSON に。
3. バリデーションを「`required` フィールドが空でないか」のみ PHP でチェック。
4. 承認フローを `approved_by` カラム 1 つで管理（複数承認者に対応できない）。
5. セクション順序を `order_index` で管理するが、並び替え時の更新を 1 件ずつ UPDATE。

### ハマりポイント

- **動的フィールドのバリデーション**: `field_type: text/number/date/checkbox/file` それぞれの型チェックロジック。
- **セクションの並び替え**: `order_index` の一括更新（10件あれば 10回 UPDATE が必要）。
- **テンプレートのバージョン**: テンプレート変更時に既存の報告書への影響をどう防ぐか。

### 解決策 & 感想

`template_sections(id, template_id, title, field_type, order_index, required)` テーブルに分離。
セクション並び替えは `PUT /templates/{id}/sections/reorder` に配列を渡すエンドポイントを作った。

> 「JSON カラムに全部詰め込むの最初便利そうだけど、
>  バリデーションや並び替えが全部 PHP になって複雑になった。
>  正規化してテーブルに出した方が結局シンプルだった。
>  order_index の一括更新パターン、覚えておきたい。」

### DX スコア: ⭐⭐（2/5）

JSON 詰め込みから正規化への再設計。order_index 一括更新パターンが必要。

---

## Persona B — 橋本 武志（ロースキル・男性・45 歳）

### 背景

コンサルティング会社の IT 部門 18 年。Word テンプレートや Excel フォームを業務で多用。

### 作業シナリオ

1. テーブル設計:
   - `templates(id, title, category, version, status: draft/published, created_by, published_at)`
   - `template_sections(id, template_id, title, description, field_type, order_index, is_required, validation_rules_json)`
   - `reports(id, template_id, template_version, title, status: draft/submitted/approved/rejected, submitted_at)`
   - `report_values(id, report_id, section_id, value_text, value_number, value_date, value_bool)` — 型別カラム
   - `report_approvals(id, report_id, approver_id, status, comment, reviewed_at)` — 多段階承認
2. 動的バリデーション: `template_sections.validation_rules_json` に `{"min": 1, "max": 1000}` を保存。
   提出時に PHP でルールを読んで検証。
3. セクション並び替え: `PUT /templates/{id}/sections/reorder` でフロントから配列を受け取り、
   ループで `UPDATE template_sections SET order_index = ? WHERE id = ?` を実行。
4. テンプレート複製: `templates` と `template_sections` を新 ID で INSERT（トランザクション）。
5. テンプレートバージョン: 報告書に `template_version` を保存して「どのバージョンで作成したか」を追跡。

### ハマりポイント

- **`report_values` の型別カラム**: `value_text` / `value_number` / `value_date` を分けると、
  フィールドを 1 つの形式で返す API レスポンスが複雑（NULL カラムを除外する必要）。
- **並び替えのパフォーマンス**: 10 件なら 10 回 UPDATE でいいが、100 件になったら重い。
  SQLite の `INSERT OR REPLACE` による一括更新が使えるか。
- **テンプレート複製の自己参照**: `parent_template_id` で元テンプレートを追跡する場合の設計。

### 解決策 & 感想

`report_values` は `value_text` に統一して、型変換は API レスポンス時に PHP で行うことで解決。

> 「型別カラム（EAV パターン）は最初きれいに見えたけど、
>  値を取り出すときの NULL チェックが煩雑だった。
>  全部 TEXT で保存して取り出し時に変換するのがシンプルだった。
>  これは 24-health-log.md の EAV vs typed tables の議論と同じ問題だった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。EAV パターンの使いどころと order_index 一括更新が課題。

---

## Persona C — 木下 文子（シニア・女性・49 歳）

### 背景

官公庁向け業務システム開発 20 年。電子申請・ワークフロー・電子署名の設計経験あり。

### 作業シナリオ

1. テーブル設計（監査証跡対応）:
   - `template_versions(id, template_id, version, sections_snapshot_json, published_by, published_at)` — バージョン履歴
   - `report_values` は `TEXT` 型に統一、`raw_value` + `normalized_value` + `validation_error`
   - `approval_flows(template_id, step_number, approver_role, is_mandatory)` — 承認フロー定義
   - `report_audit_log(report_id, action, actor_id, old_values_json, new_values_json, acted_at)` — 監査ログ
2. テンプレートバージョン管理: テンプレート公開時に `sections_snapshot_json` に全セクションを保存。
   報告書は公開時のスナップショットを使って表示・バリデーション。
3. order_index 一括更新: `UPDATE template_sections SET order_index = CASE id WHEN ? THEN ? ... END WHERE id IN (?)` で 1 クエリに。
4. 動的バリデーション: UseCase でバリデーションルールをファクトリで生成して適用。
5. PDF データ出力: `reports + report_values + template_versions.sections_snapshot_json` を結合した構造化 JSON。

### ハマりポイント

- **`CASE WHEN` 一括 UPDATE**: `UPDATE ... SET order_index = CASE id WHEN 1 THEN 10 WHEN 2 THEN 20 END WHERE id IN (1, 2)` の構文。
  PDO のプレースホルダーとの組み合わせで動的生成が複雑。
- **テンプレートスナップショットのサイズ**: セクション数が多いと `sections_snapshot_json` が大きくなる。
  正規化テーブルに保存するか JSON に保存するかのトレードオフ。
- **監査ログの `old_values_json`**: 変更前の値を保存するために UPDATE 前に SELECT が必要（パフォーマンス）。

### 解決策 & 感想

`CASE WHEN` 一括 UPDATE は PHP でクエリを動的生成。スナップショットは JSON で十分と判断。

> 「官公庁システムでは監査ログと版管理は必須要件。
>  NENE2 でも append-only ログパターン（event-sourcing-cqrs-api.md）が活用できた。
>  CASE WHEN の一括更新は動的 SQL の良い例で、howto に書く価値がある。
>  テンプレートスナップショットは JSON で保存する判断を今後の howto に残したい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。CASE WHEN 一括 UPDATE パターンと監査ログの設計が参考になる。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 藤田（新卒） | △ JSON 詰め込みから再設計 | 2/5 | JSON vs 正規化、order_index 一括更新 |
| 橋本（ロースキル） | ○ 実用的完成 | 3/5 | EAV 型別カラム vs TEXT 統一 |
| 木下（シニア） | ◎ 高品質完成 | 4/5 | CASE WHEN 一括 UPDATE、テンプレートスナップショット |

**共通のフリクション**:
1. **order_index の一括更新** — `CASE WHEN` で 1 クエリ化するパターン（並び替えで必須）。
2. **EAV パターンの使いどころ** — 型別カラム vs TEXT 統一の設計判断基準。
3. **テンプレートバージョン管理** — スナップショット JSON vs 正規化テーブルのトレードオフ howto。
