# DX Scenario 33: 社員研修管理

## アプリ概要

研修プログラム・受講・試験・修了証を管理する社員研修 API。

| 機能 | エンドポイント例 |
|------|----------------|
| 研修プログラム | `GET /programs`, `POST /programs`（name, required_role, deadline）|
| セッション管理 | `POST /programs/{id}/sessions`（date, location, max_participants）|
| 申込 | `POST /sessions/{id}/enrollments`（employee_id） |
| 出欠記録 | `PATCH /enrollments/{id}/attendance`（status: attended/absent）|
| 試験管理 | `POST /programs/{id}/exams`（pass_score）|
| 試験結果 | `POST /exams/{id}/results`（employee_id, score）|
| 修了証 | `GET /employees/{id}/certificates`（合格済みプログラムの一覧）|
| 未受講一覧 | `GET /programs/{id}/incomplete`（まだ受講していない対象社員）|

ポイント: 対象ロール別の必須研修、試験合否判定、修了証発行条件（出席 + 試験合格）。

---

## Persona A — 谷川 蓮（新卒・男性・23 歳）

### 背景

商業学部卒 1 年目。会社の新入社員研修を受けた直後で「研修管理システムをAPI化する」課題が来た。

### 作業シナリオ

1. `programs` / `sessions` / `enrollments` テーブルを作成。
2. 試験結果を `enrollments.exam_score INTEGER` カラムで持たせる（1 回しか記録できない）。
3. 修了証の発行条件（出席 + 試験合格）を UseCase で確認せず、
   「管理者が手動で修了証を発行する」設計にした。
4. 未受講一覧を「全社員リストを取得してループで研修受講者を引く」PHP実装。
5. 必須研修の「対象ロール」チェックを実装しない（全社員が全研修の対象になる）。

### ハマりポイント

- **試験の複数受験**: 再受験を想定していない設計（`exam_score` 1 カラム）。
- **修了条件の自動判定**: 出席 AND 試験合格の論理を自動で判定する設計。
- **未受講のSQL**: `NOT EXISTS` または `LEFT JOIN IS NULL` が必要。

### 解決策 & 感想

`exam_results(employee_id, exam_id, score, passed, taken_at)` テーブルと
`certificates(employee_id, program_id, issued_at)` テーブルを追加して再設計。

> 「再受験の仕様は最初に聞くべきだった。
>  修了条件の自動判定、UseCase でやるべきところを
>  最初は全部省略してた。」

### DX スコア: ⭐⭐⭐（3/5）

基本概念を習得して再設計できた。修了条件判定のパターンを howto 化すると良い。

---

## Persona B — 小野寺 絵里（ロースキル・女性・34 歳）

### 背景

社内研修担当の人事部員から IT 部門に転向 4 年目。研修管理の業務知識豊富。

### 作業シナリオ

1. テーブル設計（業務知識から）:
   - `programs(id, name, target_roles_json, deadline)` ← `target_roles_json: ["general","manager"]`
   - `sessions(id, program_id, session_date, location, max_participants)`
   - `enrollments(id, session_id, employee_id, attendance_status)` UNIQUE(session_id, employee_id)
   - `exams(id, program_id, pass_score)` + `exam_results(employee_id, exam_id, score, passed, taken_at)`
   - `certificates(employee_id, program_id, issued_at)` UNIQUE(employee_id, program_id)
2. 修了証発行条件 UseCase:
   - 出席チェック: `enrollments.attendance_status = 'attended'`
   - 試験合格チェック: `exam_results.passed = 1` AND `MAX(score) >= pass_score`
   - 両方満たせば `certificates` に INSERT
3. 未受講一覧: `LEFT JOIN enrollments ON e.employee_id = emp.id WHERE e.id IS NULL`
4. `target_roles_json` のフィルタ: `WHERE target_roles_json LIKE '%"general"%'`（簡易実装）。

### ハマりポイント

- **`target_roles_json` の LIKE**: `"general"` の完全一致で検索したいが `LIKE` は過剰一致する。
- **修了証の再発行**: 試験を再受験してより高い点数を取った場合の修了証更新処理。
- **セッションの定員管理**: `max_participants` を超えた申込みを防ぐチェック（ホテル予約と同様）。

### 解決策 & 感想

業務知識でスムーズに設計できた。`target_roles_json` の検索精度は今後の課題。

> 「ロールの JSON 配列検索は json_each() の方が正確だけど複雑。
>  とりあえず LIKE で動いてる。
>  セッションの定員管理は hotel-booking シナリオと同じ問題だった。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。JSON 配列検索と定員管理の howto が欲しい。

---

## Persona C — 古川 茂樹（シニア・男性・47 歳）

### 背景

HR Tech 企業のアーキテクト 15 年。LMS と HRM（人事管理）の統合設計経験あり。

### 作業シナリオ

1. テーブル設計（正規化 + パフォーマンス）:
   - `program_target_roles(program_id, role)` — roles を正規化テーブルに
   - `sessions` / `enrollments` / `exams` / `exam_results` / `certificates`
2. 修了条件は `CompletionCriteriaUseCase::check()` として抽象化。
   設定可能なルール（出席のみ/試験のみ/両方）を `programs.completion_criteria` JSON で管理。
3. 未受講一覧: `NOT IN (SELECT employee_id FROM certificates WHERE program_id=?)` で
   認証済み社員を除外。
4. バルク修了証発行: `POST /admin/programs/{id}/bulk-certify`（条件を満たす全社員に一括発行）。
5. 研修レポート: `GET /programs/{id}/report`（受講率・合格率・平均スコア）を SQL 集計。

### ハマりポイント

- **`completion_criteria` JSON**: 条件のルールを JSON で持つ設計の柔軟性 vs 複雑さ。
- **バルク修了証発行の冪等性**: `INSERT OR IGNORE INTO certificates` で重複をスキップ。
- **研修レポートの権限**: 全社員の成績を誰が見られるかの権限設計。

### 解決策 & 感想

高品質で完成。`INSERT OR IGNORE` を活用した冪等な一括処理は便利なパターンだった。

> 「`INSERT OR IGNORE` は NENE2 の howto にないが便利。
>  SQLite 固有の構文なので MySQL 移行時には `INSERT IGNORE` に変わる点を
>  howto に書いてほしい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。`INSERT OR IGNORE` / `INSERT IGNORE` の DB 差異と一括処理パターンが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 谷川（新卒） | ○ 再設計後完成 | 3/5 | 修了条件判定の自動化 |
| 小野寺（ロースキル） | ○ 業務知識で完成 | 3/5 | JSON 配列検索精度、定員管理 |
| 古川（シニア） | ◎ 高品質完成 | 4/5 | `INSERT OR IGNORE` の DB 差異 |

**共通のフリクション**:
1. **`INSERT OR IGNORE` / `ON CONFLICT IGNORE` パターン** — 重複スキップの冪等処理 howto。
2. **JSON 配列カラムの要素完全一致検索** — `json_each()` の活用パターン。
3. **定員管理パターン** — ホテル予約・イベント登録と共通のパターン。専用 howto を作ると重複利用できる。
