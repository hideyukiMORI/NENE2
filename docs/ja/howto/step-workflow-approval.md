# ハウツー: 承認付きステップベースワークフロー

> **FT リファレンス**: FT247 (`NENE2-FT/stepflowlog`) — ステップワークフロー承認 API

再利用可能なワークフロー定義が順序付きのステップリストを持ち、ワークフロー実行がその定義のインスタンスとして approve/reject アクションを通じてステップを進む 2 レベルのワークフローシステムを実演します。各アクションは監査履歴ログに記録されます。

---

## ルート

| メソッド | パス | 説明 |
|--------|------|------|
| `POST` | `/workflows`              | 新しいワークフローを定義する                                         |
| `GET`  | `/workflows/{id}`         | ワークフローとそのステップを取得する                                  |
| `POST` | `/workflows/{id}/steps`   | ワークフローにステップを追加する（自動順序付け）                      |
| `POST` | `/runs`                   | ワークフローの実行を開始する（ステップがない場合は失敗）               |
| `GET`  | `/runs/{id}`              | アクション履歴付きの実行ステータスを取得する                          |
| `POST` | `/runs/{id}/approve`      | 現在のステップを承認する（次のステップに進むか完了）                  |
| `POST` | `/runs/{id}/reject`       | 現在のステップを拒否する（実行を rejected として終了する）            |

---

## スキーマ

```sql
CREATE TABLE IF NOT EXISTS workflows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_steps (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    step_order  INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);

CREATE TABLE IF NOT EXISTS workflow_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id     INTEGER NOT NULL REFERENCES workflows(id),
    title           TEXT    NOT NULL,
    status          TEXT    NOT NULL DEFAULT 'pending'
                        CHECK(status IN ('pending', 'in_progress', 'completed', 'rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_actions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id     INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id    INTEGER NOT NULL REFERENCES workflow_steps(id),
    action     TEXT    NOT NULL CHECK(action IN ('approve', 'reject')),
    actor      TEXT    NOT NULL,
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

`UNIQUE(workflow_id, step_order)` はワークフロー内での重複順序を防ぎます。
`current_step_id` は nullable です — `NULL` は実行が `completed` または `rejected`（アクティブなステップなし）であることを意味します。`action` は DB レベルで `approve`/`reject` の `CHECK` を持ちます。

---

## ステップ自動順序付け

ステップを追加する際、コントローラーは次の `step_order` を自動的に計算します:

```php
$existingSteps = $this->repo->findSteps($id);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    if ((int) $s['step_order'] > $maxOrder) {
        $maxOrder = (int) $s['step_order'];
    }
}
$stepOrder = $maxOrder + 1;
$stepId    = $this->repo->addStep($id, $name, $stepOrder);
```

`step_order` は `1` から始まり、新しいステップごとに `1` ずつ増加します。`UNIQUE` 制約により 2 つのステップが同じ順序を共有できません。ステップは常に順序通りに返されます:

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## 実行の開始: 最初のステップの初期化

実行はワークフローの最初のステップを `current_step_id` として初期化されます:

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow has no steps'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

ワークフローにステップがない場合は `409 Conflict` が返されます — ステップのないワークフローでは実行が進めません。最初のステップ（最小の `step_order`）がアクティブなステップになります。

---

## `approve`: 次のステップに進むか完了

`POST /runs/{id}/approve` は現在のステータスを確認し、アクションを記録してから `step_order` で次のステップを見つけます:

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}

$this->repo->recordAction($id, $currentStepId, 'approve', $actor, $comment, $this->now());

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($id, 'in_progress', (int) $nextStep['id'], $this->now());
} else {
    $this->repo->updateRun($id, 'completed', null, $this->now());
}
```

`findNextStep` は次の `step_order` を持つステップを取得します:

```php
public function findNextStep(int $workflowId, int $currentOrder): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
        [$workflowId, $currentOrder],
    );
}
```

`step_order > current` + `ORDER BY step_order ASC LIMIT 1` は直後のステップを見つけます。次のステップが存在しない場合（最後のステップ）、`findNextStep` は `null` を返し、実行は `current_step_id = null` で `completed` とマークされます。

---

## `reject`: 実行を終了

`POST /runs/{id}/reject` はアクションを記録し、実行を `rejected` とマークします:

```php
$this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
$this->repo->updateRun($id, 'rejected', null, $this->now());
```

拒否時に `current_step_id` は `null` に設定されます — アクティブなステップは残りません。実行は終端です: `status !== 'in_progress'` のため、それ以降の `approve`/`reject` 呼び出しは `409` を返します。

---

## アクション履歴: ステップ名との JOIN

実行レスポンスには完全なアクション履歴が含まれます:

```php
$run     = $this->repo->findRun($id);
$actions = $this->repo->findActions($id);
return $this->json->create(array_merge($run, ['history' => $actions]));
```

アクションはステップ名で各行を充実させるために `JOIN` を使って取得されます:

```php
$this->db->fetchAll(
    'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
     JOIN workflow_steps ws ON wa.step_id = ws.id
     WHERE wa.run_id = ? ORDER BY wa.id ASC',
    [$runId],
);
```

`ORDER BY wa.id ASC` は監査証跡の時系列挿入順序を保持します。

---

## 実行ステートマシン

```
             POST /runs
                 │
                 ▼
           in_progress  ──approve（最後のステップ）──► completed
                 │
           approve（最後のステップでない）
                 │
                 ▼
           in_progress（次のステップ）
                 │
              reject
                 │
                 ▼
             rejected
```

`completed` と `rejected` 状態は終端です — それ以降の状態トランジションは許可されません。終端の実行への `approve`/`reject` は `409 Conflict` を返します。

---

## `LEFT JOIN` を使った `current_step_name` 付きの `findRun`

実行は現在のステップ名を含むために `LEFT JOIN` で取得されます:

```php
$this->db->fetchOne(
    'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
     FROM workflow_runs wr
     LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
     WHERE wr.id = ?',
    [$id],
);
```

`LEFT JOIN`（`INNER JOIN` ではなく）— `current_step_id` が `null`（completed/rejected の実行）の場合、`ws.*` カラムは `null` になり、行が消えることはありません。

---

## 関連 howto

- [`approval-workflow.md`](approval-workflow.md) — pending/approved/rejected 状態を持つ承認パターン
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — 状態トランジション記録と InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — 順次マルチステップフォーム/プロセスパターン
- [`audit-trail.md`](audit-trail.md) — 追記専用イベント記録パターン
