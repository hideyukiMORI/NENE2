# DX Scenario 34: コードレビュー記録

## アプリ概要

PR・レビューコメント・承認・マージ記録を管理するコードレビュー記録 API。

| 機能 | エンドポイント例 |
|------|----------------|
| PR 管理 | `POST /pull-requests`（title, description, author_id, base_branch, head_branch）|
| コメント | `POST /pull-requests/{id}/comments`（body, file_path, line_number, type: general/inline）|
| コメント解決 | `PATCH /comments/{id}/resolve`（reviewer_id）|
| 承認 | `POST /pull-requests/{id}/reviews`（verdict: approved/requested_changes）|
| マージ記録 | `POST /pull-requests/{id}/merge`（merged_by）|
| PR 一覧 | `GET /pull-requests?status=open&author_id=3` |
| レビュー統計 | `GET /users/{id}/review-stats`（平均レビュー時間・承認率）|
| 未解決コメント | `GET /pull-requests/{id}/unresolved-comments` |

ポイント: PR ステータス遷移（open→merged/closed）、レビュー承認の集計（全員承認で merge 可）、統計。

---

## Persona A — 村田 拓海（新卒・男性・23 歳）

### 背景

情報系大学卒 1 年目。GitHub を毎日使うが「PR を API で管理する」という発想は新鮮。

### 作業シナリオ

1. `pull_requests` / `pr_comments` テーブルを作成。
2. 承認を `pull_requests.approved = 1` のフラグで実装（誰が承認したか不明）。
3. 「全員承認でマージ可能」の判定を「承認数 >= 2」のハードコードにした。
4. コメントの `file_path` + `line_number` を保存したが、
   「インラインコメント vs 全体コメント」の区別をしなかった。
5. レビュー統計はカラムがなく未実装。

### ハマりポイント

- **レビュー承認の N:M**: 複数レビュアーの承認状態を管理するには `pr_reviews(pr_id, reviewer_id, verdict)` テーブルが必要。
- **マージ可能判定**: 「承認した全レビュアーが approve したか」の論理。
- **`requested_changes` → 再提出サイクル**: コード修正後のレビュー状態リセット処理。

### 解決策 & 感想

`pr_reviews` テーブルを追加してレビュアーごとの verdict を管理。
`requested_changes` 後のリセットは「新しいコミット = 既存の `requested_changes` を無効化」と設計。

> 「PR のレビューって複数人がいるから、フラグ 1 個じゃ無理だった。
>  GitHub の仕組みを考えながら設計するとイメージしやすかった。」

### DX スコア: ⭐⭐⭐（3/5）

N:M レビュー承認の設計を学習。実務ツールを参考にした設計アプローチが有効。

---

## Persona B — 佐野 美香（ロースキル・女性・28 geq 歳）

### 背景

ソフトウェア開発会社の QA エンジニア 5 年目。コードレビューのプロセスを外側から熟知。

### 作業シナリオ

1. テーブル設計:
   - `pull_requests(id, title, author_id, status, base_branch, head_branch, created_at, merged_at)`
   - `pr_comments(id, pr_id, user_id, body, file_path, line_number, comment_type, resolved_at, resolved_by)`
   - `pr_reviews(id, pr_id, reviewer_id, verdict, reviewed_at)` — 最新の verdict のみ有効
2. ステータス遷移: `state-machine-workflow-api.md` のパターンで `open→merged/closed`。
3. マージ可能判定:
   ```sql
   SELECT COUNT(*) = 0 AS can_merge FROM pr_reviews pr
   WHERE pr.pr_id=? AND pr.verdict != 'approved'
   AND pr.id IN (SELECT MAX(id) FROM pr_reviews WHERE pr_id=? GROUP BY reviewer_id)
   ```
4. 未解決コメント: `WHERE resolved_at IS NULL AND pr_id=?`
5. レビュー統計: `AVG(julianday(merged_at) - julianday(created_at)) * 86400` で平均マージ時間（秒）。

### ハマりポイント

- **最新の verdict のみ有効**: `SELECT MAX(id) GROUP BY reviewer_id` のサブクエリが複雑。
- **`requested_changes` 後のリセット**: 新しいコミットで `requested_changes` を無効化する
  ロジックを UseCase に実装したが、「コミット」の概念が今回の API にないため省略。
- **マージ後の後続処理**: マージ後に関連 Issue を自動クローズするような副作用の設計。

### 解決策 & 感想

QA の観点から品質の高い実装ができた。最新 verdict のサブクエリは少し複雑だった。

> 「最新の verdict だけ使うクエリ、MAX(id) GROUP BY のパターンは
>  howto に書いてあれば速かった。
>  実務でも同じパターンを SQL で書くことがある。」

### DX スコア: ⭐⭐⭐（3/5）

良好に完成。「最新レコードのみを使う」クエリパターンの howto が欲しい。

---

## Persona C — 伊藤 誠司（ベテラン・男性・42 歳）

### 背景

DevOps エンジニア 15 年。GitHub Actions と API の統合設計経験あり。

### 作業シナリオ

1. テーブル設計（監査重視）:
   - `pr_events(id, pr_id, actor_id, event_type, metadata_json, created_at)` — 全イベントのイベントログ
   - `pr_reviews(id, pr_id, reviewer_id, verdict, comment_count, updated_at)` — latest state
   - `pr_comments(id, pr_id, reviewer_id, body, file_path, line_number, is_inline, thread_id, resolved_at)`
2. PR ライフサイクルを全て `pr_events` に記録（イベントソーシング的）。
3. マージ可能判定: `required_approvals` をプロジェクト設定として管理。
   全レビュアーの最新 verdict が `approved` かつ `requested_changes` が 0 件かつ
   未解決コメントが 0 件。
4. `CODEOWNERS` 的な「このファイルを変更したらこの人のレビューが必須」は今回省略。
5. WebHook 通知は `pr_events` の INSERT をフックして `webhook-delivery-api.md` のパターンで配信。

### ハマりポイント

- **`pr_events` の活用**: イベントログから現在の状態を再構成するのが複雑になった。
  読み取り用に `pr_reviews` の current state テーブルを維持することで妥協。
- **`webhook-delivery-api.md` との統合**: `pr_events` の INSERT に Webhook 配信をフックする
  実装を UseCase に書いたが、同一トランザクションで Webhook 配信まで行うのは
  分離できていない。
- **コメントスレッド**: `thread_id` で同じファイル同じ行のコメントをグループ化する設計。

### 解決策 & 感想

高品質で完成。イベントログと現在状態の 2 テーブル管理は設計判断として正しかった。

> 「イベントログ + current state テーブルの 2 重管理は
>  event-sourcing howto の内容に近い。
>  webhook-delivery-api.md のパターンを活用できたが、
>  UseCase 内での Webhook 呼び出しが設計的に分離できていない。」

### DX スコア: ⭐⭐⭐⭐（4/5）

高品質完成。UseCase からの Webhook 分離パターンと「最新レコードのみ」クエリが欲しい。

---

## まとめ & NENE2 DX 評価

| ペルソナ | 完成度 | スコア | 主なフリクション |
|---------|--------|--------|----------------|
| 村田（新卒） | ○ N:M レビュー設計を習得 | 3/5 | N:M 承認状態管理 |
| 佐野（ロースキル） | ○ 良好に完成 | 3/5 | 「最新レコードのみ」クエリパターン |
| 伊藤（ベテラン） | ◎ 高品質完成 | 4/5 | UseCase から Webhook の設計分離 |

**共通のフリクション**:
1. **「最新レコードのみを使う」クエリパターン** — `SELECT MAX(id) GROUP BY key` の実例 howto。
2. **イベントログ + current state の 2 重管理** — event-sourcing howto の拡張として説明すると良い。
3. **UseCase から副作用（Webhook/通知）の設計分離** — 同期 vs 非同期の境界設計。
