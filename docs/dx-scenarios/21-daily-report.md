# DX Scenario 21: 日報 + 承認フロー

## アプリ概要

日報投稿・上司承認・フィードバック・履歴を管理する業務日報 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 日報提出 | `POST /daily-reports`（date, work_summary, issues, tomorrow_plan） |
| 日報一覧 | `GET /daily-reports?user_id=3&month=2026-05` |
| 承認 | `POST /daily-reports/{id}/approve`（supervisor_id）|
| 差し戻し | `POST /daily-reports/{id}/reject`（reason）|
| フィードバック | `POST /daily-reports/{id}/feedback`（comment）|
| フィードバック一覧 | `GET /daily-reports/{id}/feedbacks` |
| 未承認一覧 | `GET /daily-reports?status=submitted&supervisor_id=5` |
| 月次サマリー | `GET /users/{id}/report-summary?month=2026-05`（承認率・提出率） |

ポイント: 承認フロー（draft→submitted→approved/rejected）、差し戻し後の再提出、承認者権限チェック。

---

## Persona A — 津田 奈美（新卒・女性・23 歳）

### 背景

商業系大学卒 1 年目。会社でグループウェアの日報機能を毎日使っている。

### 作業シナリオ

1. `daily_reports` テーブルを作成。`status = TEXT DEFAULT 'draft'`。
2. 承認は `PATCH /daily-reports/{id}` に `status = 'approved'` を送るシンプルな実装。
   承認者が誰かの記録なし。
3. 差し戻し理由を `daily_reports.reject_reason TEXT` として持たせる（履歴なし）。
4. フィードバックは `daily_reports.feedback TEXT` カラムに上書き（複数フィードバック不可）。
5. 「差し戻し後に再提出できる」仕様を後で聞いて驚く（rejected → submitted の遷移を追加）。

### ハマりポイント

- **ステータス遷移の設計**: `draft→submitted→approved/rejected` の遷移グラフを最初から考慮しない。
- **承認者の記録**: 「誰が承認したか」の監査証跡がない。
- **フィードバックの複数件**: カラム 1 つでは複数コメントを持てない。

### 解決策 & 感想

`state-machine-workflow-api.md` を読んで遷移ルールを設計。
`feedbacks(report_id, user_id, comment, created_at)` テーブルを追加した。

> 「状態遷移って図を先に書かないと実装できない。
>  howto に『まずは遷移図を描いてから』って書いてあったら良かった。」

### DX スコア: ⭐⭐⭐（3/5）

`state-machine-workflow-api.md` 活用で改善。承認履歴の概念サポートが欲しい。

---

## Persona B — 増田 俊彦（ロースキル・男性・38 歳）

### 背景

物流会社の業務システム担当 12 年。紙の承認フローを電子化したシステムの運用経験あり。

### 作業シナリオ

1. テーブル設計:
   - `daily_reports(id, user_id, report_date, work_summary, issues, tomorrow_plan, status, submitted_at, approved_by, approved_at)`
   - `report_feedbacks(id, report_id, user_id, comment, created_at)`
2. ステータス遷移: `state-machine-workflow-api.md` のパターンを参考に `allowedTransitions()` を実装。
   `rejected → submitted`（再提出）の遷移も含める。
3. 承認は `approved_by = supervisor_id, approved_at = now()` を設定。
   ただし「督促権限チェック（supervisor の subordinate であるか）」は省略。
4. `GET /daily-reports?month=2026-05` は `BETWEEN '2026-05-01' AND '2026-05-31'` でフィルタ。
5. 月次サマリーは PHP ループで集計（SQL 集計未使用）。

### ハマりポイント

- **日付範囲フィルタ**: `BETWEEN` を `TEXT` 日付に使う際の文字列比較の信頼性を確認。
  ISO 8601 形式 (`YYYY-MM-DD`) なら辞書順でソート可能と確認。
- **権限チェック**: 「部下の日報だけ承認できる」のチェックを省略した（設計上の穴）。
- **月次集計 SQL**: PHP ループで集計したが、SQLのクエリで書く方法を考えると複雑に感じた。

### 解決策 & 感想

業務知識で設計はスムーズ。権限チェックの省略は「後でやる」として残した。

> 「日付の BETWEEN が TEXT 型でも動くのは分かったけど、
>  DB ごとに挙動が違うかもしれない。
>  ISO 8601 の日付を TEXT で持つのが正解なのか howto で明示してほしい。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。日付フィルタのベストプラクティスと権限チェックの説明が欲しい。

---

## Persona C — 前田 綾子（シニア・女性・41 歳）

### 背景

グループウェア開発会社でバックエンドリード 12 年。承認フローエンジンの設計経験あり。

### 作業シナリオ

1. テーブル設計:
   - `daily_reports(id, user_id, supervisor_id, report_date, work_summary, issues, tomorrow_plan, status)`
   - `report_status_history(id, report_id, from_status, to_status, changed_by, reason, changed_at)` — 監査ログ
   - `report_feedbacks(id, report_id, user_id, comment, created_at)`
2. ステータス遷移を `DailyReportWorkflow::allowedTransitions()` で定義し、
   遷移時に `report_status_history` に INSERT（同一トランザクション）。
3. 権限チェック: `daily_reports.supervisor_id = :current_user_id` の確認を UseCase に実装。
4. `GET /daily-reports?month=2026-05` は `strftime('%Y-%m', report_date) = ?` でフィルタ。
5. 月次サマリー: SQL で `COUNT(*)` / `COUNT(CASE WHEN status='approved' THEN 1 END)` で集計。

### ハマりポイント

- **`strftime('%Y-%m', report_date) = ?`**: インデックスが使えないため月次フィルタが遅い。
  `report_year_month TEXT` の追加カラムを検討（今回は省略）。
- **`report_status_history` と状態遷移の整合**: ステータス変更と履歴 INSERT を同一トランザクションにする
  ことを確認。`tx->run()` 内でネスト不要でそのまま使えた。
- **差し戻し理由の必須化**: `POST /daily-reports/{id}/reject` で `reason` が必須かどうかの
  仕様決定（必須にした）。

### 解決策 & 感想

高品質で完成。`report_status_history` の監査ログパターンは多くのアプリで使えると思った。

> 「監査ログ（status_history）のパターンは承認フロー系で必須。
>  howto に入れてほしい。
>  あと strftime でのフィルタが遅い問題は、
>  year_month カラムを追加するか発生時に考えるかが判断基準になる。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。監査ログパターンと日付フィルタの最適化 howto が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 津田（新卒） | ○ state-machine 活用で改善 | 3/5 | 遷移図の事前設計、承認者記録 |
| 増田（ロースキル） | ○ 実用的完成 | 3/5 | 日付 BETWEEN の信頼性、権限チェック漏れ |
| 前田（シニア） | ◎ 高品質完成 | 4/5 | 監査ログパターン、`strftime` フィルタ最適化 |

**共通のフリクション**:
1. **状態遷移の事前設計指針** — `state-machine-workflow-api.md` の「まず遷移図を描く」ガイダンス。
2. **監査ログ（ステータス変更履歴）パターン** — `status_history` テーブルの標準パターン howto。
3. **日付フィルタのベストプラクティス** — `TEXT` 日付型と `strftime` の使い方・制限。
