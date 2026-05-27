# How-to: State Machine Workflow API

> **FT reference**: FT349 (`NENE2-FT/workflowlog`) — State machine workflow instances with hardcoded transition map, `allowed_next` in responses, transition history log, terminal state enforcement, state filter on list, 13 tests PASS.

This guide shows how to build a workflow engine using a state machine: define allowed state transitions, create workflow instances, drive them through states with actor attribution, and log the full transition history.

## Schema

```sql
CREATE TABLE instances (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow      TEXT    NOT NULL,          -- e.g. "order"
    current_state TEXT    NOT NULL,
    context       TEXT    NOT NULL DEFAULT '{}',  -- JSON
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);

CREATE TABLE transitions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
    from_state  TEXT    NOT NULL,
    to_state    TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    occurred_at TEXT    NOT NULL
);
```

## Workflow Definition — "order"

```
draft ──┬──► submitted ──┬──► approved ──► fulfilled (terminal)
        │                ├──► rejected   (terminal)
        └──► cancelled   └──► cancelled  (terminal)
        (terminal)
```

| From State  | Allowed Next States          |
|-------------|------------------------------|
| `draft`     | `submitted`, `cancelled`     |
| `submitted` | `approved`, `cancelled`, `rejected` |
| `approved`  | `fulfilled`                  |
| `fulfilled` | _(terminal — none)_          |
| `cancelled` | _(terminal — none)_          |
| `rejected`  | _(terminal — none)_          |

## Endpoints

| Method | Path                                           | Description               |
|--------|------------------------------------------------|---------------------------|
| `POST` | `/workflows/{workflow}/instances`              | Create workflow instance  |
| `GET`  | `/workflows/{workflow}/instances`              | List instances            |
| `GET`  | `/workflows/{workflow}/instances/{id}`         | Get instance with history |
| `POST` | `/workflows/{workflow}/instances/{id}/transition` | Drive state transition |

## Create Instance

```php
POST /workflows/order/instances
{"context": {"order_ref": "ORD-001", "amount": 99.99}}

→ 201
{
  "id": 1,
  "workflow": "order",
  "current_state": "draft",
  "context": {"order_ref": "ORD-001", "amount": 99.99},
  "allowed_next": ["submitted", "cancelled"],  // ← next valid states
  "created_at": "...",
  "updated_at": "..."
}
```

`allowed_next` is computed from the transition map — always reflects the current state.

### Unknown Workflow → 404

```php
POST /workflows/unknown/instances  {}
→ 404  // workflow not defined
```

## List Instances

```php
// All instances of "order" workflow
GET /workflows/order/instances
→ 200  {"instances": [{...}, {...}]}

// Filter by current state
GET /workflows/order/instances?state=draft
→ 200  {"instances": [{...}]}  // only draft instances
```

## Get Instance (with History)

```php
GET /workflows/order/instances/1

→ 200
{
  "id": 1,
  "workflow": "order",
  "current_state": "approved",
  "context": {...},
  "allowed_next": ["fulfilled"],
  "history": [
    {
      "from_state": "draft",
      "to_state": "submitted",
      "actor": "alice",
      "occurred_at": "..."
    },
    {
      "from_state": "submitted",
      "to_state": "approved",
      "actor": "manager",
      "occurred_at": "..."
    }
  ],
  ...
}
```

`history` is always ordered chronologically (ASC by `occurred_at`). List endpoint omits `history` for performance.

## Drive Transitions

```php
// Valid transition
POST /workflows/order/instances/1/transition
{"to_state": "submitted", "actor": "alice"}

→ 200
{
  "current_state": "submitted",
  "allowed_next": ["approved", "cancelled", "rejected"],
  "history": [
    {"from_state": "draft", "to_state": "submitted", "actor": "alice", ...}
  ]
}
```

### Full Happy Path

```php
POST .../transition  {"to_state": "submitted", "actor": "alice"}    → submitted
POST .../transition  {"to_state": "approved",  "actor": "manager"}  → approved
POST .../transition  {"to_state": "fulfilled", "actor": "warehouse"} → fulfilled

// fulfilled is terminal
→ {"current_state": "fulfilled", "allowed_next": [], ...}
```

### Invalid Transition → 409

```php
// draft → approved (must go through submitted first)
POST .../transition  {"to_state": "approved", "actor": "alice"}
→ 409
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "detail": "Transition from 'draft' to 'approved' is not allowed"
}
```

### Terminal State → 409

```php
// cancelled is terminal — no transitions allowed
POST .../transition  {"to_state": "draft", "actor": "alice"}
→ 409  // "cancelled" has no allowed transitions
```

## Implementation

### WorkflowDefinition — Transition Map

```php
final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> */
    private static array $transitions = [
        'order' => [
            'draft'     => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'cancelled', 'rejected'],
            'approved'  => ['fulfilled'],
            'fulfilled' => [],     // terminal
            'cancelled' => [],     // terminal
            'rejected'  => [],     // terminal
        ],
    ];

    /** @return list<string> */
    public static function allowedTransitions(string $workflow, string $fromState): array
    {
        return self::$transitions[$workflow][$fromState] ?? [];
    }

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::$transitions[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return match ($workflow) {
            'order' => 'draft',
            default => throw new \InvalidArgumentException("Unknown workflow: {$workflow}"),
        };
    }
}
```

### Transition Handler

```php
public function transition(int $id, string $toState, string $actor): ?WorkflowInstance
{
    $instance = $this->repo->findByIdOrNull($id);
    if ($instance === null) {
        return null;  // → 404
    }

    $allowed = WorkflowDefinition::allowedTransitions(
        $instance->workflow,
        $instance->currentState,
    );

    if (!in_array($toState, $allowed, true)) {
        return false;  // → 409 invalid or terminal
    }

    // Atomic: update instance + insert transition log
    $this->db->execute(
        'UPDATE instances SET current_state = ?, updated_at = ? WHERE id = ?',
        [$toState, $now, $id],
    );
    $this->db->execute(
        'INSERT INTO transitions (instance_id, from_state, to_state, actor, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $instance->currentState, $toState, $actor, $now],
    );

    return $this->hydrateInstanceWithHistory($id);
}
```

`allowed_next` is always computed from the transition map, never stored — it stays consistent with `current_state`.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store `allowed_next` in DB | Stale data if transition map changes; always compute from current state |
| Allow free-form `to_state` without allowlist check | Attacker can set state to any value, bypassing workflow logic |
| Skip transition logging | No audit trail; cannot reconstruct workflow history or debug stuck instances |
| Return terminal states in `allowed_next` | Misleads callers; terminal states always have empty `allowed_next` |
| Return 404 for invalid transition | 404 hides the distinction between "instance not found" and "transition not allowed"; use 409 for the latter |
| No `workflow` field in instances table | Cannot distinguish instances of different workflow types; no cross-workflow query possible |
