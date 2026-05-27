# 操作指南：基于步骤的审批工作流

> **FT 参考**：FT247（`NENE2-FT/stepflowlog`）— 步骤工作流审批 API

演示一个两级工作流系统：可复用的工作流定义持有有序步骤列表，工作流运行实例则通过审批/拒绝操作逐步推进定义中的步骤。每次操作都记录在审计历史日志中。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/workflows` | 定义新工作流 |
| `GET` | `/workflows/{id}` | 获取工作流及其步骤 |
| `POST` | `/workflows/{id}/steps` | 向工作流添加步骤（自动排序） |
| `POST` | `/runs` | 启动工作流运行（若工作流无步骤则失败） |
| `GET` | `/runs/{id}` | 获取运行状态及操作历史 |
| `POST` | `/runs/{id}/approve` | 审批当前步骤（推进至下一步骤或完成） |
| `POST` | `/runs/{id}/reject` | 拒绝当前步骤（以拒绝状态终止运行） |

---

## 数据库结构

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

`UNIQUE(workflow_id, step_order)` 防止同一工作流内出现重复的排序值。
`current_step_id` 可为 `NULL`——`NULL` 表示运行已 `completed`（完成）或 `rejected`（拒绝），即无活跃步骤。`action` 在 DB 层有 `CHECK` 约束，只允许 `approve`/`reject`。

---

## 步骤自动排序

添加步骤时，控制器自动计算下一个 `step_order`：

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

`step_order` 从 `1` 开始，每添加一个新步骤递增 `1`。`UNIQUE` 约束防止两个步骤共享相同排序值。步骤始终按顺序返回：

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## 启动运行：初始化第一步

运行以工作流的第一个步骤作为 `current_step_id` 进行初始化：

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow has no steps'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

当工作流没有步骤时返回 `409 Conflict`——无法在无步骤的工作流上推进运行。第一个步骤（最小 `step_order`）成为活跃步骤。

---

## `approve`：推进至下一步或完成

`POST /runs/{id}/approve` 检查当前状态，记录操作，然后通过 `step_order` 查找下一步骤：

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

`findNextStep` 获取具有下一个 `step_order` 的步骤：

```php
public function findNextStep(int $workflowId, int $currentOrder): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
        [$workflowId, $currentOrder],
    );
}
```

`step_order > current` + `ORDER BY step_order ASC LIMIT 1` 查找紧接着的下一步骤。若不存在下一步骤（最后一步），`findNextStep` 返回 `null` → 运行标记为 `completed`，`current_step_id = null`。

---

## `reject`：终止运行

`POST /runs/{id}/reject` 记录操作并将运行标记为 `rejected`：

```php
$this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
$this->repo->updateRun($id, 'rejected', null, $this->now());
```

拒绝时 `current_step_id` 设为 `null`——不再有活跃步骤。运行进入终态：后续的 `approve`/`reject` 调用因 `status !== 'in_progress'` 而返回 `409`。

---

## 操作历史：通过 JOIN 附带步骤名称

运行响应包含完整的操作历史：

```php
$run     = $this->repo->findRun($id);
$actions = $this->repo->findActions($id);
return $this->json->create(array_merge($run, ['history' => $actions]));
```

操作通过 `JOIN` 获取，为每行附加步骤名称：

```php
$this->db->fetchAll(
    'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
     JOIN workflow_steps ws ON wa.step_id = ws.id
     WHERE wa.run_id = ? ORDER BY wa.id ASC',
    [$runId],
);
```

`ORDER BY wa.id ASC` 保留审计跟踪的时间顺序插入顺序。

---

## 运行状态机

```
             POST /runs
                 │
                 ▼
           in_progress  ──approve（最后一步）──► completed
                 │
           approve（非最后一步）
                 │
                 ▼
           in_progress（下一步骤）
                 │
              reject
                 │
                 ▼
             rejected
```

`completed` 和 `rejected` 是终态——不允许进一步的状态转换。对终态运行的任何 `approve`/`reject` 都返回 `409 Conflict`。

---

## `findRun` 通过 `LEFT JOIN` 附带 `current_step_name`

运行通过 `LEFT JOIN` 获取，包含当前步骤的名称：

```php
$this->db->fetchOne(
    'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
     FROM workflow_runs wr
     LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
     WHERE wr.id = ?',
    [$id],
);
```

使用 `LEFT JOIN`（而非 `INNER JOIN`）——当 `current_step_id` 为 `null`（已完成/已拒绝的运行）时，`ws.*` 列为 `null`，而不会导致行消失。

---

## 相关指南

- [`approval-workflow.md`](approval-workflow.md) — 带 pending/approved/rejected 状态的审批模式
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — 状态转换记录与 InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — 顺序多步骤表单/流程模式
- [`audit-trail.md`](audit-trail.md) — 仅追加事件记录模式
