# マルチステップワークフローの追加方法

各ステップが次に進む前に承認される必要がある、順次承認フローをモデル化します。

## スキーマ

```sql
CREATE TABLE workflows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE TABLE workflow_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name TEXT NOT NULL, step_order INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);
CREATE TABLE workflow_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id),
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE workflow_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id INTEGER NOT NULL REFERENCES workflow_steps(id),
    action TEXT NOT NULL CHECK(action IN ('approve','reject')),
    actor TEXT NOT NULL, comment TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
```

## ルート

| メソッド | パス | 説明 |
|--------|------|-------------|
| `POST` | `/workflows` | ワークフローを定義する |
| `GET` | `/workflows/{id}` | ワークフローとステップを取得する |
| `POST` | `/workflows/{id}/steps` | ステップを追加する（自動順序） |
| `POST` | `/runs` | 新しいランを開始する（ステップ 1 から始まる） |
| `GET` | `/runs/{id}` | ランの状態と完全なアクション履歴を取得する |
| `POST` | `/runs/{id}/approve` | 現在のステップを承認する |
| `POST` | `/runs/{id}/reject` | 拒否 → ランを終了する |

## ステートマシン

```
in_progress --approve (ステップが残っている)--> in_progress（次のステップ）
in_progress --approve（最終ステップ）--> completed
in_progress --reject（任意のステップ）----> rejected
```

完了または拒否済みのランはさらなる approve/reject に 409 を返します。

## ステップの自動順序付け

追加のみ: 各新しいステップは `max(step_order) + 1` を取得します:

```php
$existingSteps = $this->repo->findSteps($workflowId);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    $maxOrder = max($maxOrder, (int) $s['step_order']);
}
$stepOrder = $maxOrder + 1;
$this->repo->addStep($workflowId, $name, $stepOrder);
```

## 承認時の進行または完了

```php
// 先にアクションを記録し、次に遷移
$this->repo->recordAction($runId, $currentStepId, 'approve', $actor, $comment, $now);

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($runId, 'in_progress', (int) $nextStep['id'], $now);
} else {
    $this->repo->updateRun($runId, 'completed', null, $now);
}
```

単一の SQL クエリで次のステップを見つけます:

```sql
SELECT * FROM workflow_steps
WHERE workflow_id = ? AND step_order > ?
ORDER BY step_order ASC LIMIT 1
```

## 完了/拒否済みのランをガードする

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}
```

## 履歴の JOIN

`GET /runs/{id}` レスポンスにステップ名を含む完全なアクション履歴を返します:

```sql
SELECT wa.*, ws.name AS step_name
FROM workflow_actions wa
JOIN workflow_steps ws ON wa.step_id = ws.id
WHERE wa.run_id = ?
ORDER BY wa.id ASC
```

## 主要な設計上の決定

- **追加のみのステップ**: `step_order` は単調増加; 作成後の並べ替えなし。
- **拒否は即座に終了**: 任意のステップの拒否がランを終了（部分的な承認なし）。
- **完了/拒否済みのランでは `current_step_id = NULL`** — 区別するために `status` を使ってください。
- **ランの開始には少なくとも 1 つのステップが必要**: ワークフローにステップがない場合は 409 を返します。
