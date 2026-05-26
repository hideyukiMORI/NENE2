# How-to: Step-Based Workflow with Approval

> **FT reference**: FT247 (`NENE2-FT/stepflowlog`) — Step Workflow Approval API

Demonstrates a two-level workflow system where a reusable workflow definition
holds an ordered list of steps, and a workflow run is an instance of that definition
progressing through steps via approve/reject actions. Each action is recorded in an
audit history log.

---

## Routes

| Method | Path                      | Description                                                  |
|--------|---------------------------|--------------------------------------------------------------|
| `POST` | `/workflows`              | Define a new workflow                                        |
| `GET`  | `/workflows/{id}`         | Get workflow with its steps                                  |
| `POST` | `/workflows/{id}/steps`   | Add a step to a workflow (auto-ordered)                      |
| `POST` | `/runs`                   | Start a run of a workflow (fails if workflow has no steps)   |
| `GET`  | `/runs/{id}`              | Get run status with action history                           |
| `POST` | `/runs/{id}/approve`      | Approve current step (advances to next step or completes)    |
| `POST` | `/runs/{id}/reject`       | Reject current step (terminates run as rejected)             |

---

## Schema

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

`UNIQUE(workflow_id, step_order)` prevents duplicate ordering within a workflow.
`current_step_id` is nullable — `NULL` means the run is `completed` or `rejected`
(no active step). `action` has a DB-level `CHECK` for `approve`/`reject`.

---

## Step auto-ordering

When adding a step, the controller computes the next `step_order` automatically:

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

`step_order` starts at `1` and increments by `1` for each new step. The `UNIQUE`
constraint prevents two steps from sharing the same order. Steps are always returned
in order:

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## Starting a run: first step initialization

A run is initialized with the workflow's first step as `current_step_id`:

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow has no steps'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

`409 Conflict` is returned when the workflow has no steps — a run cannot progress
through a stepless workflow. The first step (lowest `step_order`) becomes the active step.

---

## `approve`: advance to next step or complete

`POST /runs/{id}/approve` checks the current status, records the action, then
finds the next step by `step_order`:

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

`findNextStep` fetches the step with the next `step_order`:

```php
public function findNextStep(int $workflowId, int $currentOrder): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
        [$workflowId, $currentOrder],
    );
}
```

`step_order > current` + `ORDER BY step_order ASC LIMIT 1` finds the immediately
following step. If no next step exists (last step), `findNextStep` returns `null`
→ the run is marked `completed` with `current_step_id = null`.

---

## `reject`: terminate run

`POST /runs/{id}/reject` records the action and marks the run `rejected`:

```php
$this->repo->recordAction($id, $currentStepId, 'reject', $actor, $comment, $this->now());
$this->repo->updateRun($id, 'rejected', null, $this->now());
```

`current_step_id` is set to `null` on rejection — no active step remains. The run
is terminal: further `approve`/`reject` calls return `409` because `status !== 'in_progress'`.

---

## Action history: JOIN with step name

The run response includes the full action history:

```php
$run     = $this->repo->findRun($id);
$actions = $this->repo->findActions($id);
return $this->json->create(array_merge($run, ['history' => $actions]));
```

Actions are fetched with a `JOIN` to enrich each row with the step name:

```php
$this->db->fetchAll(
    'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
     JOIN workflow_steps ws ON wa.step_id = ws.id
     WHERE wa.run_id = ? ORDER BY wa.id ASC',
    [$runId],
);
```

`ORDER BY wa.id ASC` preserves chronological insertion order for the audit trail.

---

## Run state machine

```
                 POST /runs
                     │
                     ▼
               in_progress  ──approve (last step)──► completed
                     │
               approve (not last step)
                     │
                     ▼
               in_progress (next step)
                     │
                  reject
                     │
                     ▼
                 rejected
```

States `completed` and `rejected` are terminal — no further state transitions are
allowed. Any `approve`/`reject` on a terminal run returns `409 Conflict`.

---

## `findRun` with `current_step_name` via `LEFT JOIN`

The run is fetched with a `LEFT JOIN` to include the current step's name:

```php
$this->db->fetchOne(
    'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
     FROM workflow_runs wr
     LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
     WHERE wr.id = ?',
    [$id],
);
```

`LEFT JOIN` (not `INNER JOIN`) — when `current_step_id` is `null` (completed/rejected
run), `ws.*` columns are `null` rather than causing the row to disappear.

---

## Related howtos

- [`approval-workflow.md`](approval-workflow.md) — approval pattern with pending/approved/rejected states
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — state transition recording and InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — sequential multi-step form/process pattern
- [`audit-trail.md`](audit-trail.md) — append-only event recording patterns
