# Field Trial 81 — State Machine Workflow with Transition Validation and History (workflowlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.23
**Project**: `/home/xi/docker/NENE2-FT/workflowlog/`
**Theme**: Multi-step state machine workflow with explicit allowed-transitions map, transition history (append-only), and current state + `allowed_next` in responses.

---

## What was built

A workflow instance API where instances of named workflows track their current state. State transitions are validated against a static definition map. Every successful transition is recorded in an append-only history table.

### Domain

- `WorkflowDefinition` — static allowed-transitions map (`order` workflow: draft → submitted → approved → fulfilled; cancellation and rejection states)
- `WorkflowInstance` — `workflow`, `currentState`, `context` (arbitrary JSON), `allowedNext` (computed from definition)
- `WorkflowTransition` — `fromState`, `toState`, `actor`, `note`, `occurredAt` (append-only)

### Schema

```sql
CREATE TABLE IF NOT EXISTS instances (
    id, workflow TEXT, current_state TEXT, context TEXT DEFAULT '{}',
    created_at, updated_at
);
CREATE TABLE IF NOT EXISTS transitions (
    id, instance_id REFERENCES instances(id),
    from_state, to_state, actor, note, occurred_at
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/workflows/{workflow}/instances` | Create new instance (starts in initial state) |
| GET | `/workflows/{workflow}/instances` | List instances (optional `?state=X`) |
| GET | `/workflows/{workflow}/instances/{id}` | Get instance with full transition history |
| POST | `/workflows/{workflow}/instances/{id}/transition` | Perform state transition |

### Key design decisions

**Static definition as the source of truth**: `WorkflowDefinition` is a hardcoded class (not DB-driven). For this FT, the "order" workflow is the only supported workflow. `isValidWorkflow()` returns true only for known workflows.

**`allowed_next` in every response**: Derived from the definition map at serialization time (`toArray()`). Clients can always see valid next states without a separate API call.

**409 for invalid transitions**: `transition()` returns null when `!in_array($toState, $allowed, true)`. The route handler maps this to 409.

**Terminal states**: `fulfilled`, `cancelled`, `rejected` have empty `allowed_next` — no further transitions possible. The general 409 logic handles this automatically.

**`context` is arbitrary JSON**: Stored as TEXT, decoded on hydration. Useful for attaching order-specific metadata.

### Test results

```
OK (13 tests, 30 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No NENE2 frictions encountered. One PHPStan type annotation fix required:

- `@var array<string, list<string>>` was wrong for a nested `array<string, array<string, list<string>>>` structure. Caught immediately by PHPStan level 8. This is a PHP/PHPDoc issue, not a NENE2 friction.

---

## Summary

State machine workflow with transition validation and append-only history works cleanly with NENE2 v1.5.23. No framework changes needed.
