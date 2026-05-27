# DX Scenario 03: タスク管理 + タイムトラッキング

## アプリ概要

プロジェクト・タスク・サブタスク・作業時間を管理する API。

| 機能 | エンドポイント例 |
|------|----------------|
| プロジェクト CRUD | `GET /projects`, `POST /projects`, `PUT /projects/{id}`, `DELETE /projects/{id}` |
| タスク管理 | `POST /projects/{id}/tasks`, `GET /tasks/{id}`, `PATCH /tasks/{id}/status` |
| サブタスク | `POST /tasks/{id}/subtasks`, `PATCH /subtasks/{id}/complete` |
| 担当者割り当て | `POST /tasks/{id}/assignees`, `DELETE /tasks/{id}/assignees/{uid}` |
| タイムエントリ | `POST /tasks/{id}/time-entries`（開始・終了・メモ）, `GET /tasks/{id}/time-entries` |
| サマリー | `GET /projects/{id}/summary`（合計工数・完了率） |

ポイント: 階層構造（プロジェクト > タスク > サブタスク）、複数担当者、集計クエリ。

---

## Persona A — 大野 颯太（新卒・男性・22 歳）

### 背景

情報系大学院卒業直後。アルゴリズムは得意だが Web API の実務は初めて。
「設計より実装が好き」タイプ。

### 作業シナリオ

1. `projects` → `tasks` → `subtasks` の 3 テーブルを作るが、
   外部キー制約を全部省略してしまう（「どうせ SQLite で制約効かないと思った」）。
2. タスクステータス遷移（todo/in_progress/done）を enum 風に設計しようとするが、
   SQLite に ENUM 型がなく `TEXT` + アプリ側バリデーションで実装。
3. 担当者割り当ての `task_assignees` 中間テーブルに気づかず、`tasks.assignee_id` で単一担当者にしてしまう。
4. タイムエントリの合計計算を PHP の `array_sum()` でメモリに載せて計算（大量データで遅くなる）。
5. `GET /projects/{id}/summary` の「完了率」を `tasks` と `subtasks` の両方から計算すべきか迷い、
   `tasks` のみで計算した。

### ハマりポイント

- **中間テーブル設計**: 多対多リレーションの経験がなく、担当者設計を単一 FK にしてしまう。
- **集計クエリの SQL vs PHP**: どちらで計算するかの判断基準が分からない。
- **階層ステータス**: 「サブタスクが全部完了したら親タスクも完了にすべき？」の判断ができない。

### 解決策 & 感想

先輩レビューで中間テーブルの指摘を受け、`task_assignees` に作り直した。
SQL 集計は `SUM()` / `COUNT()` / `GROUP BY` を使うよう教わった。

> 「外部キーって SQLite でも設定はできるって知らなかった。
>  多対多は学校で習ったけど、自分で設計するのは全然違うな。」

### DX スコア: ⭐⭐⭐（3/5）

基本機能は完成。設計ミスをレビューで修正。多対多の howto サポートがあれば改善。

---

## Persona B — 松田 恵美（ロースキル・女性・31 歳）

### 背景

中小 SIer の社内 SE として 6 年。Excel + Access が得意で PHP は副業で勉強中。
「動けばいい。設計は後で直せばいい」思考。

### 作業シナリオ

1. 既存の CRUD パターンを参考にプロジェクト・タスクを実装。
2. サブタスクを独立テーブルではなく `tasks` テーブルの `parent_id` カラムで実装（自己参照 FK）。
   `docs/howto/hierarchical-data.md` を見つけ「これじゃん！」と活用できた。
3. 担当者はコンマ区切り文字列で `assignees` カラムに保存（`"1,3,7"`）。
4. タイムエントリの開始・終了を `VARCHAR` で保存（ISO 8601 形式の文字列）。
   時間差計算は PHP で `strtotime()` を使用。
5. `GET /projects/{id}/summary` はリクエストのたびに全タスクを PHP ループで集計。

### ハマりポイント

- **コンマ区切り文字列の落とし穴**: 担当者検索・削除が後でできないことに気づいたが、
  直す余裕がなかった。
- **タイムゾーン**: `strtotime()` のタイムゾーン挙動でバグが出た（サーバーのロケール依存）。
- **サブタスクのステータス集計**: 自己参照 FK で実装したため、サブタスクのみを集計する
  クエリが複雑になった。

### 解決策 & 感想

`docs/howto/hierarchical-data.md` が自己参照の設計例として機能した。
タイムゾーンバグはスタック自体のドキュメントが少なく、Stack Overflow で解決した。

> 「自己参照の howto があって助かった。
>  タイムゾーンはマジで何時間も潰れた。PHPのタイムゾーン怖い。」

### DX スコア: ⭐⭐⭐（3/5）

`hierarchical-data.md` 活用で部分的に正しい設計。コンマ区切りの技術的負債が残る。

---

## Persona C — 西田 浩二（ベテラン・男性・47 歳）

### 背景

フリーランスエンジニア 20 年。LAMP スタック・DDD・マイクロサービスの実務経験あり。
「新しいフレームワークはコアを 30 分読んで判断する」スタイル。

### 作業シナリオ

1. `src/` を 20 分で読んで NENE2 の設計方針を把握。DI・リポジトリ・UseCase パターンを確認。
2. ドメインモデルを設計:
   - `Project` / `Task` / `Subtask` / `Assignee` (value object) / `TimeEntry`
   - `ProjectRepository` / `TaskRepository` / `TimeEntryRepository`
3. 多対多は `task_assignees` 中間テーブルで実装。削除は `DELETE WHERE task_id=? AND user_id=?`。
4. タイムエントリは `started_at TEXT` (ISO 8601 UTC) で保存。集計は `SUM(julianday(ended_at) - julianday(started_at)) * 86400` で SQL 計算。
5. `GET /projects/{id}/summary` は 1 クエリで `COUNT(*)` / `SUM()` / `GROUP BY` を使って効率化。
   サブタスク込みの完了率は `WITH RECURSIVE` CTE で一撃。

### ハマりポイント

- **`julianday()` 集計**: SQLite の日時計算関数を確認するために SQLite ドキュメントを参照。
  NENE2 howto には SQLite 固有関数の使い方がない。
- **`WITH RECURSIVE` CTE**: `docs/howto/` に再帰 CTE の実例がなく、自分で書いた
  （後で `category-hierarchy-api.md` に近い内容があることを知った）。
- **テスト戦略**: UseCase の単体テスト構造が分かりやすく書けた。
  `DatabaseTransactionManagerInterface` のモックは少し面倒。

### 解決策 & 感想

ほぼ自力で完成。SQLite 固有の日時計算は外部ドキュメントを参照した。

> 「CTE の howto あれば時間節約できた。あと SQLite の日時関数リファレンスへのリンクを
>  howto に入れてくれるだけでだいぶ楽になる。
>  フレームワーク設計は思ったより良かった。PSR 準拠はエンジニアとしてありがたい。」

### DX スコア: ⭐⭐⭐⭐（4/5）

ほぼ問題なく完成。SQL 計算の howto があればさらに良い。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 大野（新卒） | ○ 部分的に設計ミス | 3/5 | 多対多設計例、SQL vs PHP 集計の判断基準 |
| 松田（ロースキル） | ○ 動作するが負債あり | 3/5 | コンマ区切り文字列の落とし穴、タイムゾーン |
| 西田（ベテラン） | ◎ 高品質完成 | 4/5 | SQLite 固有関数リファレンス、CTE howto |

**共通のフリクション**:
1. **多対多 (N:M) 設計の howto がない** — 中間テーブルパターンの実例が欲しい。
2. **SQL 集計パターン** — `SUM` / `COUNT` / `GROUP BY` + CTE の使い方 howto。
3. **タイムゾーン/日時計算** — SQLite 固有の日時関数と PHP の組み合わせ例があると良い。
