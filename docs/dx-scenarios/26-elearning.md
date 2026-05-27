# DX Scenario 26: オンライン学習

## アプリ概要

コース・レッスン・進捗・クイズを管理するオンライン学習プラットフォーム API。

| 機能 | エンドポイント例 |
|------|----------------|
| コース管理 | `GET /courses`, `POST /courses`（title, description, instructor_id） |
| レッスン管理 | `POST /courses/{id}/lessons`（title, content, video_url, order） |
| 受講登録 | `POST /courses/{id}/enroll`（user_id） |
| 進捗更新 | `POST /lessons/{id}/complete`（user_id）|
| 進捗確認 | `GET /courses/{id}/progress`（完了レッスン数/全レッスン数） |
| クイズ | `POST /lessons/{id}/quiz`（questions と選択肢を定義）|
| クイズ回答 | `POST /quizzes/{id}/answers`（question_id, answer_index） |
| 修了証 | `GET /courses/{id}/certificate`（全レッスン完了後のみ取得可能）|

ポイント: レッスン順序管理、進捗の百分率計算、クイズ採点、修了証の条件付き発行。

---

## Persona A — 秋山 望（新卒・女性・23 歳）

### 背景

教育学部卒でプログラミングスクール経由。「Udemy みたいなものを作る」という意欲。

### 作業シナリオ

1. `courses` / `lessons` テーブルを作成。`lessons.order_index INTEGER`。
2. 進捗管理を「`user_progress TEXT`（完了した lesson_id のコンマ区切り）」で実装。
3. クイズを `lessons.quiz_json TEXT` に JSON で保存（前回の過ちを繰り返す）。
4. 修了証は「全レッスン完了したかどうか」の判定をコントローラーで毎回 PHP で計算。
5. 受講登録の「1 ユーザー 1 コース 1 登録」制限を忘れる。

### ハマりポイント

- **進捗のコンマ区切り**: 後から「どのレッスンを完了したか」を SQL でクエリできない。
- **クイズ JSON**: 設問数・選択肢・正解を JSON に入れると採点ロジックが複雑になる。
- **修了証の条件**: 「全レッスン完了判定」を毎回計算するのは遅い。

### 解決策 & 感想

`user_lesson_progress(user_id, lesson_id, completed_at)` UNIQUE(user_id, lesson_id) に変更。
クイズは `quiz_questions` + `quiz_options` テーブルに正規化した。

> 「JSON カラムって最初楽に見えるけど後で絶対困る、を学んだ。
>  進捗のコンマ区切りも同じ轍を踏んだ。
>  こういうアンチパターンを howto に書いてほしい。」

### DX スコア: ⭐⭐⭐（3/5）

基本動作するが設計アンチパターンを繰り返した。アンチパターン集 howto が欲しい。

---

## Persona B — 堀田 達也（ロースキル・男性・30 歳）

### 背景

教育系スタートアップの IT 担当 6 年目。Moodle の運用経験あり。

### 作業シナリオ

1. テーブル設計:
   - `courses` / `lessons(course_id, title, content, video_url, order_index)`
   - `enrollments(user_id, course_id, enrolled_at)` UNIQUE(user_id, course_id)
   - `lesson_completions(user_id, lesson_id, completed_at)` UNIQUE(user_id, lesson_id)
   - `quiz_questions(lesson_id, text, correct_option_index)` + `quiz_options(question_id, text, option_index)`
2. 進捗 `GET /courses/{id}/progress`:
   ```sql
   SELECT COUNT(*) AS total_lessons,
     COUNT(lc.id) AS completed_lessons
   FROM lessons l
   LEFT JOIN lesson_completions lc ON lc.lesson_id = l.id AND lc.user_id = :user_id
   WHERE l.course_id = :course_id
   ```
3. 修了証は「`completed_lessons = total_lessons` かつ `total_lessons > 0`」を UseCase でチェック。
4. クイズ採点: `correct_option_index = :submitted_option_index` を `quiz_questions` で確認。
5. `PATCH /courses/{id}/lessons/order` で一括順序変更を実装。

### ハマりポイント

- **進捗の LEFT JOIN**: `LEFT JOIN ... AND lc.user_id = :user_id` の条件を ON 句に書くか
  WHERE 句に書くかで結果が変わることを確認（ON 句が正解）。
- **修了証の「取り消し」**: レッスンが後から追加された場合、修了証が無効になるポリシーの検討。
  今回は「発行済みは取り消しなし」とした。
- **クイズの複数回挑戦**: `quiz_answers(user_id, question_id, answer, is_correct, attempted_at)` で
  複数回挑戦を許可するか、1 回限りにするか。複数回許可で実装。

### 解決策 & 感想

Moodle の経験が活きて設計はスムーズ。LEFT JOIN の ON vs WHERE で少し詰まった。

> 「LEFT JOIN の ON と WHERE の違いって微妙だけど大事。
>  howto にサンプルがあれば助かる。
>  修了証の取り消しポリシーみたいなビジネスルールは
>  仕様として先に決めておくべきだと改めて思った。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。LEFT JOIN の ON vs WHERE の使い分け説明が欲しい。

---

## Persona C — 木下 尚子（シニア・女性・46 歳）

### 背景

EdTech スタートアップのバックエンドアーキテクト 12 年。LMS（Learning Management System）設計の専門家。

### 作業シナリオ

1. テーブル設計（LMS ドメインの観点）:
   - `courses(id, instructor_id, title, status, total_lessons_count)` — `total_lessons_count` はスナップショット
   - `lessons(id, course_id, title, content_type, content_url, duration_sec, order_index)`
   - `enrollments(id, user_id, course_id, enrolled_at, completed_at, certificate_issued_at)`
   - `lesson_completions(user_id, lesson_id, completed_at)` UNIQUE(user_id, lesson_id)
   - `quiz_attempts(id, user_id, lesson_id, score, max_score, passed, attempted_at)`
2. 進捗率: `completed / total_lessons_count` でスナップショット参照。
   `total_lessons_count` は `lessons` テーブルの INSERT/DELETE トリガーで更新（手動 UPDATE で代替）。
3. 修了証発行: `enrollments.completed_at` が NULL でなく、全レッスン完了チェック後に
   `certificate_issued_at = now()` を設定。
4. クイズ採点: `quiz_attempts` に採点結果を記録。`passed` フラグで合否判定。
5. `GET /courses?sort=popularity` は `enrollments` の COUNT を JOIN。

### ハマりポイント

- **`total_lessons_count` の維持**: レッスン追加/削除時のカウント同期をトランザクション内で実装。
  「トリガー」が使えない（Phinx では SQLite トリガー作成がサポート不明）ため手動 UPDATE。
- **クイズパス条件**: 「70% 以上で合格」など閾値をどこで定義するか（`courses.quiz_pass_rate`）。
- **コースのバージョン管理**: コンテンツを更新した場合の既存受講者への影響ポリシー。

### 解決策 & 感想

高品質で完成。Phinx のトリガーサポートについて確認が必要だった。

> 「LMS 設計は奥深い。コースバージョン管理、修了条件の柔軟性、
>  コンテンツタイプ多様性など要件が増えていく。
>  NENE2 で始めて後から MySQL に移行するパターンで設計した。
>  Phinx の SQLite トリガーサポートを確認する方法が分からなかった。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。Phinx 高度機能のドキュメントとカウントスナップショット管理が欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 秋山（新卒） | ○ アンチパターン後に改善 | 3/5 | JSON カラム・コンマ区切りのアンチパターン繰り返し |
| 堀田（ロースキル） | ○ 実用的完成 | 3/5 | LEFT JOIN の ON vs WHERE |
| 木下（シニア） | ◎ 高品質完成 | 4/5 | Phinx トリガー、カウントスナップショット |

**共通のフリクション**:
1. **LEFT JOIN の ON vs WHERE の違い** — フィルタ条件の位置で結果が変わる重要な違いの howto。
2. **「アンチパターン」の明示** — コンマ区切り・JSON カラムが後でどう困るかを示す反例 howto。
3. **Phinx 高度機能（トリガー・計算カラム・Raw SQL）** — マイグレーションのより高度な使い方。
