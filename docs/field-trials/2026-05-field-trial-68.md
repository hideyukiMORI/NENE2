# Field Trial 68 — Approval Workflow with Multi-Step Transitions

**Date**: 2026-05-20
**Theme**: Multi-step approval workflow (draft → submitted → under_review → approved/rejected → rework loop) with reviewer identity, rejection notes, and invalid-transition 409 enforcement
**Project**: `/home/xi/docker/NENE2-FT/approvallog/`
**NENE2 version**: 1.5.22

---

## Summary

Implemented a request approval API covering a five-state lifecycle with branching: items can be approved or rejected from review, and rejected items can be returned to draft for rework. Validates that transitions not in the allowed list return 409 Conflict.

**Result**: 13 tests, 28 assertions — all pass. PHPStan level 8 clean. CS-Fixer clean. No NENE2 changes required.

---

## What Was Built

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/requests` | Create a draft request |
| `GET` | `/requests` | List requests (optional `?status=` filter) |
| `GET` | `/requests/{id}` | Get a single request |
| `POST` | `/requests/{id}/submit` | Transition: draft → submitted |
| `POST` | `/requests/{id}/review` | Transition: submitted → under_review (requires reviewer) |
| `POST` | `/requests/{id}/approve` | Transition: under_review → approved (requires reviewer + note) |
| `POST` | `/requests/{id}/reject` | Transition: under_review → rejected (requires reviewer + note) |
| `POST` | `/requests/{id}/rework` | Transition: rejected → draft (clears reviewer/note) |

### State Machine

```
draft → submitted → under_review → approved
                              ↘ rejected → draft (rework loop)
```

Invalid transitions return 409. Implemented as `allowedTransitions()` / `canTransitionTo()` on a string-backed `ApprovalStatus` enum (same pattern as FT61 `OrderStatus`).

### Rework Loop

Rejecting a request and returning it to draft clears `reviewer`, `review_note`, and `reviewed_at`, allowing a fresh submission. The full cycle can repeat.

---

## Frictions Encountered

### None

FT68 was friction-free with NENE2 1.5.22. Key patterns worked cleanly:

- State machine enum with `allowedTransitions()` (same as FT61) — no new friction
- Action endpoints with body params (`reviewer`, `note`) alongside path params (`{id}`) — `JsonRequestBodyParser::parse()` + `Router::PARAMETERS_ATTRIBUTE` extraction work correctly together
- `POST /requests/{id}/submit` sends an empty body (`(object)[]`) — no friction (handler doesn't call parse())

---

## Patterns Validated

### Pattern: Named Sub-Resource Action Routes

```php
$router->post('/requests/{id}/submit', $this->submit(...));
$router->post('/requests/{id}/review', $this->startReview(...));
$router->post('/requests/{id}/approve', $this->approve(...));
$router->post('/requests/{id}/reject', $this->reject(...));
$router->post('/requests/{id}/rework', $this->rework(...));
```

With NENE2 v1.5.22's static-route priority fix, all these routes coexist without conflict. Each action carries semantic intent in the URL, which is cleaner than `PATCH status=...`.

### Pattern: Shared Private Transition Helper

```php
private function transition(int $id, ApprovalStatus $target, callable $update, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);
    if ($req === null || !$req->status->canTransitionTo($target)) {
        return null;
    }
    $update($id, $now);
    return $this->findById($id);
}
```

Used for `submit()` and `rework()` where the guard is pure — no extra parameters. Actions needing extra context (`startReview`, `approve`, `reject`) inline the guard.

### Pattern: Rejection Requires Mandatory Note

```php
// reject() validates both reviewer AND note before calling repo
if ($reviewer === '' || $note === '') {
    $errors = [];
    ...
    return $this->problems->create($request, 'validation-failed', ..., 422, ...);
}
```

Approval doesn't require a note (optional feedback); rejection does (mandatory reason). This asymmetry is domain logic, not framework.

---

## No NENE2 Changes Required

All patterns implementable with NENE2 1.5.22 as-is.
