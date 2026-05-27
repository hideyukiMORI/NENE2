# DX Scenario 45: メンターマッチング

## アプリ概要

メンターとメンティーのスキルマッチング・面談管理・進捗トラッキングを行う API。

| 機能 | エンドポイント例 |
|------|----------------|
| プロフィール | `POST /mentors`（name, skills[], bio, available_hours_per_month）|
| スキル検索 | `GET /mentors?skills[]=Python&skills[]=ML` |
| マッチング申請 | `POST /mentors/{id}/requests`（mentee_id, message, desired_skills[]）|
| 面談記録 | `POST /sessions`（mentor_id, mentee_id, scheduled_at, duration_minutes）|
| 面談完了 | `PATCH /sessions/{id}/complete`（notes, feedback_score）|
| 進捗管理 | `GET /mentees/{id}/progress`（面談回数・スキル習得状況）|
| レビュー | `POST /mentors/{id}/reviews`（rating, comment）|
| マッチング推薦 | `GET /mentors/recommend?mentee_id=1`（スキル一致度ランキング）|

ポイント: スキルの多対多マッチング（AND 検索）、面談予定の重複チェック、スキル一致度スコア計算。

---

## Persona A — 青木 梓（新卒・女性・23 歳）

### 背景

教育学部卒でエドテック企業に就職。自分がメンタリングサービスを利用した経験あり。

### 作業シナリオ

1. `mentors(id, name, bio, skills)` — `skills` をカンマ区切り TEXT で保存。
2. スキル検索を PHP で `explode()` してフィルタリング（全件取得後に絞り込み）。
3. 面談記録を `sessions(id, mentor_id, mentee_id, date, duration)` で単純 INSERT。
4. レビューを `reviews(id, mentor_id, rating, comment)` で管理、平均は PHP で計算。
5. スキル一致度を PHP ループで `array_intersect()` して計算。

### ハマりポイント

- **スキルの AND 検索**: 「Python と ML の両方できるメンター」は `HAVING COUNT(DISTINCT) = 2` が必要。
- **面談の重複チェック**: 同じ時間帯に別の面談が入っていないかの確認 SQL。
- **推薦ランキング**: スキル一致数で ORDER BY するには集計クエリが必要。

### 解決策 & 感想

`mentor_skills(mentor_id, skill_id)` 中間テーブルを作成し直した。
スキル AND 検索は `22-job-board.md` の `HAVING COUNT(DISTINCT)` パターンを参考にした。

> 「スキルをカンマ区切りで保存したのが最大の失敗。
>  N:M テーブルに直したら検索もソートも一気に解決した。
>  AND 検索パターン、転職サイトの作業で使った人の howto がそのまま使えた。」

### DX スコア: ⭐⭐⭐（3/5）

N:M 再設計で解決。スキル AND 検索 howto の再利用が有効。

---

## Persona B — 三浦 健二（ロースキル・男性・40 歳）

### 背景

IT 研修会社の運営スタッフ 15 年。メンタリングプログラムの企画・運営を担当。

### 作業シナリオ

1. テーブル設計:
   - `mentors(id, name, bio, available_hours_per_month, avg_rating)`
   - `skills(id, name, category)`
   - `mentor_skills(mentor_id, skill_id, proficiency_level: 1-5)` UNIQUE(mentor_id, skill_id)
   - `match_requests(id, mentor_id, mentee_id, status: pending/accepted/rejected, message, requested_at)`
   - `sessions(id, request_id, scheduled_at, duration_minutes, status: scheduled/completed/cancelled)`
2. スキル AND 検索:
   ```sql
   SELECT m.* FROM mentors m
   JOIN mentor_skills ms ON ms.mentor_id = m.id
   JOIN skills s ON s.id = ms.skill_id
   WHERE s.name IN ('Python', 'ML')
   GROUP BY m.id
   HAVING COUNT(DISTINCT s.name) = 2
   ```
3. 面談重複チェック:
   ```sql
   SELECT id FROM sessions
   WHERE mentor_id = ? AND status != 'cancelled'
   AND scheduled_at < datetime(?, '+' || duration_minutes || ' minutes')
   AND datetime(scheduled_at, '+' || duration_minutes || ' minutes') > ?
   ```
4. スキル一致度ランキング:
   ```sql
   SELECT m.*, COUNT(DISTINCT ms.skill_id) AS match_count
   FROM mentors m
   JOIN mentor_skills ms ON ms.mentor_id = m.id
   WHERE ms.skill_id IN (SELECT skill_id FROM mentee_skills WHERE mentee_id = ?)
   GROUP BY m.id ORDER BY match_count DESC
   ```
5. レビュー追加時に `mentors.avg_rating` をトランザクション内で更新。

### ハマりポイント

- **SQLite の動的 interval**: `datetime(?, '+' || duration_minutes || ' minutes')` の文字列結合。
  SQLite の `datetime()` は `+N minutes` 形式の文字列引数を受け付けるが、動的に作る必要あり。
- **面談重複チェックの境界条件**: 「終了時刻ぴったりから始まる面談」は重複しないの確認。
- **`avg_rating` の逆更新**: レビューを削除した場合の平均の再計算ポリシー。

### 解決策 & 感想

`datetime()` の動的 interval は文字列結合で解決。重複チェックの境界は `< end AND > start` で確認。

> 「SQLite の datetime() に文字列で interval を渡せるのは知らなかった。
>  '+30 minutes' のように固定文字列しか知らなかったので、
>  カラム値との結合で動的に作れるのが分かって便利だった。
>  重複チェックの境界条件は図を書いて確認した。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。SQLite 動的 interval と重複チェックの境界条件確認が必要だった。

---

## Persona C — 富田 昌子（シニア・女性・47 歳）

### 背景

HRテック企業のエンジニアリングマネージャー 15 年。スキルグラフ・推薦システムの設計経験あり。

### 作業シナリオ

1. テーブル設計（スキルグラフ対応）:
   - `skills(id, name, category, parent_skill_id)` — 階層スキル（Python → Machine Learning → NLP）
   - `mentor_skills(mentor_id, skill_id, proficiency: beginner/intermediate/expert, verified_at)`
   - `mentee_goals(mentee_id, skill_id, target_proficiency, target_date)` — 目標スキル
   - `sessions(id, mentor_id, mentee_id, scheduled_at, duration_minutes, status, outcome_json)`
   - `skill_endorsements(from_user_id, to_user_id, skill_id, endorsed_at)` — 相互評価
2. スキル推薦: 要求スキルと子スキルも含めた再帰的マッチング（SQLite は再帰 CTE 対応）。
3. 面談重複チェック: `sessions` に `end_at GENERATED COLUMN AS (datetime(scheduled_at, '+' || duration_minutes || ' minutes'))` で計算カラム。
4. 進捗集計: `sessions` × `mentee_goals` を JOIN して達成率を計算。
5. `skill_endorsements` を使ったプロフィール信頼スコア計算。

### ハマりポイント

- **再帰 CTE でのスキル階層検索**: SQLite の `WITH RECURSIVE` で親スキルから子孫スキルを展開。
- **Generated Column の重複チェック**: `end_at` カラムを使った `BETWEEN` クエリのインデックス設計。
- **スキル階層と中間テーブルの整合**: 親スキルのメンターが子スキルの検索にも出るべきか否かの仕様判断。

### 解決策 & 感想

再帰 CTE が思ったよりシンプルに書けた。Generated Column で重複チェックが分かりやすくなった。

> 「SQLite の WITH RECURSIVE は `hierarchical-data.md` の CTE パターンとほぼ同じだった。
>  スキル階層はドメイン知識として面白い問題。
>  Generated Column（計算カラム）を使えば面談の終了時刻を常に一致させられる。
>  これは日程管理全般で使えるパターン。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。再帰 CTE と Generated Column の活用が改善点の参考になる。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 青木（新卒） | ○ N:M 再設計で解決 | 3/5 | スキルカンマ区切り、AND 検索 |
| 三浦（ロースキル） | ○ 実用的完成 | 3/5 | SQLite 動的 interval、重複チェック境界 |
| 富田（シニア） | ◎ 高品質完成 | 4/5 | 再帰 CTE、Generated Column |

**共通のフリクション**:
1. **スキル N:M AND 検索** — `HAVING COUNT(DISTINCT) = N` パターン（複数シナリオで言及）。
2. **日程重複チェック SQL** — `(start1 < end2) AND (end1 > start2)` の境界条件 howto。
3. **SQLite Generated Column（計算カラム）** — 3.31+ 対応の使い方 howto（`end_at` など）。
