# DX Scenario 31: プロジェクト進捗ダッシュボード

## アプリ概要

マイルストーン・タスク・バーンダウン用データを提供するプロジェクト管理ダッシュボード API。

| 機能 | エンドポイント例 |
|------|----------------|
| プロジェクト管理 | `GET /projects`, `POST /projects`（name, start_date, end_date） |
| マイルストーン | `POST /projects/{id}/milestones`（name, due_date, description） |
| タスク | `POST /milestones/{id}/tasks`（name, estimated_hours, assignee_id） |
| タスク完了 | `PATCH /tasks/{id}/complete`（actual_hours） |
| バーンダウン | `GET /projects/{id}/burndown`（日別残タスク数・工数）|
| サマリー | `GET /projects/{id}/summary`（完了率・遅延タスク数・リスク）|
| ベロシティ | `GET /projects/{id}/velocity`（期間別完了タスク数）|

ポイント: バーンダウンチャート用時系列データ生成、遅延タスクの検出、見積もり精度統計。

---

## Persona A — 高木 俊哉（新卒・男性・24 歳）

### 背景

情報系大学院修了直後。「ガントチャート」の概念は知っているが実装は初めて。

### 作業シナリオ

1. `projects` / `milestones` / `tasks` テーブルを作成。
2. バーンダウンを「日別の残タスク数を記録する `burndown_log` テーブル」を事前計算で持たせようとして、
   「毎日更新するのは誰がやるの？」という問題に気づく。
3. バーンダウンデータを「全タスクの完了日から逆算して生成する」ロジックに変更するが、
   SQL でどう書くか分からずPHP ループになる。
4. 遅延タスク: `WHERE due_date < date('now') AND completed_at IS NULL` で実装。
5. ベロシティを「完了タスク数 / 経過日数」の単純計算にした。

### ハマりポイント

- **バーンダウンの時系列生成**: 「ある日の残タスク数」を計算するには
  `completed_at` が特定日以前かどうかのフィルタが必要。
- **バッチ処理の不在**: 「毎日のバーンダウン値を計算して保存する」処理を NENE2 でどう実装するか。
- **`due_date` のない tasks**: 期限のないタスクの遅延判定をどうするか。

### 解決策 & 感想

バーンダウンは「特定日を指定してその日の残タスク数を計算する」API に変更した。
バッチ処理の欠如は「管理者 API エンドポイントとして手動実行」で代替。

> 「バーンダウンって実はリアルタイム計算できるんだと気づいた。
>  事前計算しなくていいんだ。ただ計算コストは気になる。
>  バッチ処理が NENE2 にないのは正直困った。」

### DX スコア: ⭐⭐⭐（3/5）

発想の転換でバーンダウン実装完了。バッチ処理の代替パターン howto が欲しい。

---

## Persona B — 辻本 幸恵（ロースキル・女性・37 歳）

### 背景

プロジェクトマネージャー経験 10 年、最近エンジニアも担当。Jira / Backlog の管理経験豊富。

### 作業シナリオ

1. テーブル設計:
   - `projects(id, name, start_date, end_date, status)`
   - `milestones(id, project_id, name, due_date, status)` ← `status: pending/in_progress/done/overdue`
   - `tasks(id, milestone_id, assignee_id, name, estimated_hours, actual_hours, completed_at, due_date)`
2. バーンダウン: `GET /projects/{id}/burndown?date=2026-06-15`:
   ```sql
   SELECT COUNT(*) AS remaining FROM tasks t
   JOIN milestones m ON m.id = t.milestone_id
   WHERE m.project_id=? AND (t.completed_at IS NULL OR t.completed_at > :date)
   ```
3. 遅延タスク一覧 + マイルストーン遅延自動更新（タスクが遅延したらマイルストーンも更新）。
4. ベロシティ: 週別の完了タスク数を `strftime('%Y-%W', completed_at)` でグループ化。
5. リスク指標: 「残日数に対して残タスク工数が多い」を計算して `risk_level` として返す。

### ハマりポイント

- **バーンダウンの「ある時点での残タスク数」**: `completed_at > :date` のクエリが直感的でない。
- **マイルストーン自動更新**: タスク遅延時にマイルストーンの `status` を自動更新する
  「副作用ロジック」の置き場所（UseCase vs イベント）。
- **ベロシティの `strftime('%Y-%W')` の週番号**: 前シナリオと同様の問題。

### 解決策 & 感想

PM の経験が活きてダッシュボード設計はスムーズ。副作用ロジックは UseCase に集約した。

> 「バーンダウンのクエリ、completed_at > :date の部分が直感的じゃないけど
>  考えたら確かにそうだと分かった。
>  週番号の strftime 問題は SQLite の弱点だな。」

### DX スコア: ⭐⭐⭐（3/5）

実用的に完成。副作用ロジックの設計パターンと SQLite 週番号の注意点が欲しい。

---

## Persona C — 天野 謙二（ベテラン・男性・49 歳）

### 背景

大手 SIer のプロジェクト管理ツール開発リード 20 年。EVM（Earned Value Management）の実務経験あり。

### 作業シナリオ

1. テーブル設計（EVM を意識）:
   - `tasks(id, milestone_id, planned_hours, actual_hours, completed_at, baseline_date)` — バースライン
   - `daily_snapshots(project_id, snapshot_date, remaining_tasks, remaining_hours)` — 事前計算キャッシュ
2. バーンダウンは `daily_snapshots` から取得（パフォーマンス重視）。
   スナップショット更新は `POST /admin/projects/{id}/snapshot` で手動実行。
3. EVM 指標: CPI (Cost Performance Index) = `earned_value / actual_cost`、SPI (Schedule Performance Index)。
   `earned_value = SUM(planned_hours * percent_complete)`。
4. 見積もり精度: `actual_hours / estimated_hours` の分布統計を `tasks` から集計。
5. リスクモデル: `remaining_days < remaining_hours / team_velocity` の判定。

### ハマりポイント

- **スナップショットの管理**: 「いつ更新するか」をどう設計するかの問題（前述）。
  今回は管理者 API で手動実行としたが、本番では cron 的な仕組みが必要。
- **EVM の percent_complete**: タスクに「進捗率」を入力させるか、完了/未完了の 2 値にするかの選択。
  今回は 2 値のみ。
- **team_velocity の計算**: 過去の平均ベロシティをどの期間で計算するかの設計。

### 解決策 & 感想

高品質で完成。NENE2 にバッチ/cron の仕組みがないことで設計が制約された。

> 「スナップショット管理は cron があれば自動化できるが、
>  NENE2 にはない。バッチ処理の howto（定期実行の代替パターン）が欲しい。
>  EVM は過剰かもしれないが、プロジェクト管理では標準的な指標。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。バッチ/cron 代替パターンと EVM 指標実装が改善余地。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 高木（新卒） | ○ 発想転換で完成 | 3/5 | バーンダウン時系列計算、バッチ処理の不在 |
| 辻本（ロースキル） | ○ 実用的完成 | 3/5 | 副作用ロジックの置き場所、週番号 |
| 天野（ベテラン） | ◎ 高品質完成 | 4/5 | バッチ/cron 代替パターン |

**共通のフリクション**:
1. **バッチ/cron 代替パターン** — NENE2 に定期実行機能がないため、手動 API で代替する howto。
2. **時系列データの集計クエリ** — 「ある時点での状態」を計算するパターン。
3. **副作用ロジックの置き場所** — UseCase → Repository への副作用の設計パターン。
