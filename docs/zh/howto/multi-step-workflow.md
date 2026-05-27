# 操作指南：添加多步骤工作流

对顺序审批流程进行建模，每个步骤必须通过审批才能推进到下一步。

## 数据库结构

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

## 路由

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/workflows` | 定义工作流 |
| `GET` | `/workflows/{id}` | 获取工作流及其步骤 |
| `POST` | `/workflows/{id}/steps` | 追加步骤（自动排序） |
| `POST` | `/runs` | 启动新的运行（从步骤 1 开始） |
| `GET` | `/runs/{id}` | 获取运行状态和完整动作历史 |
| `POST` | `/runs/{id}/approve` | 审批当前步骤 |
| `POST` | `/runs/{id}/reject` | 拒绝 → 终止运行 |

## 状态机

```
in_progress --approve（还有更多步骤）--> in_progress（下一步）
in_progress --approve（最后一步）-----> completed
in_progress --reject（任意步骤）------> rejected
```

已完成和已拒绝的运行在进一步 approve/reject 时返回 409。

## 步骤自动排序

仅追加：每个新步骤获得 `max(step_order) + 1`：

```php
$existingSteps = $this->repo->findSteps($workflowId);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    $maxOrder = max($maxOrder, (int) $s['step_order']);
}
$stepOrder = $maxOrder + 1;
$this->repo->addStep($workflowId, $name, $stepOrder);
```

## 审批时推进或完成

```php
// 先记录动作，再转换状态
$this->repo->recordAction($runId, $currentStepId, 'approve', $actor, $comment, $now);

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($runId, 'in_progress', (int) $nextStep['id'], $now);
} else {
    $this->repo->updateRun($runId, 'completed', null, $now);
}
```

使用单条 SQL 查询查找下一步骤：

```sql
SELECT * FROM workflow_steps
WHERE workflow_id = ? AND step_order > ?
ORDER BY step_order ASC LIMIT 1
```

## 守护已完成/已拒绝的运行

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}
```

## 历史记录联查

在 `GET /runs/{id}` 响应中返回带步骤名称的完整动作历史：

```sql
SELECT wa.*, ws.name AS step_name
FROM workflow_actions wa
JOIN workflow_steps ws ON wa.step_id = ws.id
WHERE wa.run_id = ?
ORDER BY wa.id ASC
```

## 关键设计决策

- **仅追加步骤**：`step_order` 单调递增；创建后不允许重新排序。
- **拒绝立即终止**：任何步骤的拒绝都会结束运行（不允许部分审批）。
- **已完成/已拒绝运行的 `current_step_id = NULL`**——使用 `status` 区分两者。
- **启动运行需要至少一个步骤**：如果工作流没有步骤则返回 409。
