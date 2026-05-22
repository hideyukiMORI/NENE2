# How to Add a Multi-step Workflow

Model sequential approval flows where each step must be approved before advancing to the next.

## Schema

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

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/workflows` | Define a workflow |
| `GET` | `/workflows/{id}` | Get workflow + steps |
| `POST` | `/workflows/{id}/steps` | Append a step (auto-ordered) |
| `POST` | `/runs` | Start a new run (begins at step 1) |
| `GET` | `/runs/{id}` | Get run state + full action history |
| `POST` | `/runs/{id}/approve` | Approve current step |
| `POST` | `/runs/{id}/reject` | Reject → terminates run |

## State Machine

```
in_progress --approve (more steps)--> in_progress (next step)
in_progress --approve (final step)--> completed
in_progress --reject (any step)-----> rejected
```

Completed and rejected runs return 409 on further approve/reject.

## Step Auto-ordering

Append-only: each new step gets `max(step_order) + 1`:

```php
$existingSteps = $this->repo->findSteps($workflowId);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    $maxOrder = max($maxOrder, (int) $s['step_order']);
}
$stepOrder = $maxOrder + 1;
$this->repo->addStep($workflowId, $name, $stepOrder);
```

## Advance-or-complete on Approve

```php
// Record action first, then transition
$this->repo->recordAction($runId, $currentStepId, 'approve', $actor, $comment, $now);

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($runId, 'in_progress', (int) $nextStep['id'], $now);
} else {
    $this->repo->updateRun($runId, 'completed', null, $now);
}
```

Find the next step with a single SQL query:

```sql
SELECT * FROM workflow_steps
WHERE workflow_id = ? AND step_order > ?
ORDER BY step_order ASC LIMIT 1
```

## Guard Completed/Rejected Runs

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}
```

## History Join

Return the full action history with step names in the `GET /runs/{id}` response:

```sql
SELECT wa.*, ws.name AS step_name
FROM workflow_actions wa
JOIN workflow_steps ws ON wa.step_id = ws.id
WHERE wa.run_id = ?
ORDER BY wa.id ASC
```

## Key Design Decisions

- **Append-only steps**: `step_order` is monotonic; no reordering after creation.
- **Reject terminates immediately**: any step rejection ends the run (no partial approval).
- **`current_step_id = NULL`** on completed/rejected runs — use `status` to distinguish.
- **Start run requires at least one step**: return 409 if workflow has no steps.
