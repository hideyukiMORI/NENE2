---
title: "How-to: Approval Workflow API"
category: product
tags: [workflow, approval, state-machine, multi-step]
difficulty: intermediate
related: [content-approval-workflow, step-workflow-approval, draft-publish-workflow]
---

# How-to: Approval Workflow API

> **FT reference**: FT68 (`NENE2-FT/approvallog`) — Approval Workflow API

Demonstrates a multi-step approval workflow where a request moves through defined
states (Draft → Submitted → UnderReview → Approved/Rejected). Invalid transitions
return 409 Conflict. The state machine is encoded directly in the `ApprovalStatus`
backed enum using an `allowedTransitions()` method.

---

## Workflow states

```
Draft ──submit──▶ Submitted ──review──▶ UnderReview
                                              │
                                    ┌─approve─┤─reject─┐
                                    ▼                   ▼
                                 Approved            Rejected
                                                        │
                                                    ─rework─▶ Draft
```

| State | Description |
|-------|-------------|
| `draft` | Created but not yet submitted |
| `submitted` | Awaiting review assignment |
| `under_review` | Reviewer assigned and reviewing |
| `approved` | Final approval given |
| `rejected` | Rejected with a mandatory reason |

A rejected request can be reworked (returned to `draft`) for revision and resubmission.
An approved request has no further transitions.

---

## Transition rules encoded in the enum

State transition rules live inside the enum — not in the repository or controller:

```php
enum ApprovalStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft       => [self::Submitted],
            self::Submitted   => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved    => [],
            self::Rejected    => [self::Draft],   // rework path
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`canTransitionTo()` is the single source of truth for whether a transition is valid.
Adding a new allowed transition means updating only this one method.

---

## Routes

| Method | Path                          | Description                            |
|--------|-------------------------------|----------------------------------------|
| `POST` | `/requests`                   | Create a draft request                 |
| `GET`  | `/requests`                   | List all requests (`?status=` filter)  |
| `GET`  | `/requests/{id}`              | Get a single request                   |
| `POST` | `/requests/{id}/submit`       | Draft → Submitted                      |
| `POST` | `/requests/{id}/review`       | Submitted → UnderReview (assigns reviewer) |
| `POST` | `/requests/{id}/approve`      | UnderReview → Approved                 |
| `POST` | `/requests/{id}/reject`       | UnderReview → Rejected (reason required) |
| `POST` | `/requests/{id}/rework`       | Rejected → Draft (clears reviewer/note) |

---

## Guarding transitions in the repository

The repository checks `canTransitionTo()` before running the UPDATE query:

```php
public function submit(int $id, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);

    if ($req === null || !$req->status->canTransitionTo(ApprovalStatus::Submitted)) {
        return null;   // caller maps null → 409 Conflict
    }

    $this->db->execute(
        "UPDATE requests SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [$now, $now, $id],
    );

    return $this->findById($id);
}
```

Returning `null` for both "not found" and "invalid transition" is a deliberate
simplification. In production, distinguish between 404 (not found) and 409 (found but
invalid transition) by returning a typed result or throwing domain exceptions.

The controller maps `null → 409 Conflict`:

```php
private function submit(ServerRequestInterface $request): ResponseInterface
{
    $id  = (int) ($params['id'] ?? 0);
    $req = $this->repo->submit($id, $now);

    if ($req === null) {
        return $this->problems->create(
            $request,
            'conflict',
            'Request not found or cannot be submitted from its current status.',
            409,
            '',
        );
    }

    return $this->json->create($req->toArray());
}
```

---

## Rejection requires a reason

The `reject` transition requires both `reviewer` and `note`:

```php
private function reject(ServerRequestInterface $request): ResponseInterface
{
    $reviewer = isset($body['reviewer']) && is_string($body['reviewer']) ? trim($body['reviewer']) : '';
    $note     = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';

    if ($reviewer === '' || $note === '') {
        $errors = [];
        if ($reviewer === '') {
            $errors[] = ['field' => 'reviewer', 'code' => 'required', 'message' => 'reviewer is required.'];
        }
        if ($note === '') {
            $errors[] = ['field' => 'note', 'code' => 'required', 'message' => 'note (rejection reason) is required.'];
        }

        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, compact('errors'));
    }
    // ...
}
```

Reject with no reason is rejected (422). Approve with no note is allowed — the `note`
field is optional for approvals.

---

## Rework: clearing the review state

When a rejected request is reworked, the reviewer and review note are cleared so the
next reviewer starts fresh:

```php
// Repository: rework (Rejected → Draft)
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

The `submitted_at` timestamp is preserved — it records when the request was first
submitted, not the current cycle.

---

## Schema

```sql
CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    submitter    TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    reviewer     TEXT,              -- NULL until review starts
    review_note  TEXT,             -- NULL until reviewed
    submitted_at TEXT,             -- NULL until submitted
    reviewed_at  TEXT,             -- NULL until approved/rejected
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

Nullable columns (`reviewer`, `review_note`, `submitted_at`, `reviewed_at`) are cleared
to `NULL` on rework, keeping the schema clean without adding a `rework_count` column.

> **Enhancement**: add a `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))`
> as a DB-level backstop to match the enum values.

---

## Status filter on list endpoint

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $params    = $request->getQueryParams();
    $statusRaw = isset($params['status']) && is_string($params['status']) ? $params['status'] : null;
    $status    = $statusRaw !== null ? ApprovalStatus::tryFrom($statusRaw) : null;

    if ($statusRaw !== null && $status === null) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'status', 'code' => 'invalid_value', 'message' => 'Invalid status value.']],
        ]);
    }

    $requests = $this->repo->listByStatus($status);
    // ...
}
```

`ApprovalStatus::tryFrom()` returns `null` for unknown status strings → 422. When
`$statusRaw === null` (no filter), all requests are returned.

---

## Adding a new transition

To add a `cancelled` state that can be reached from any non-terminal state:

1. Add `case Cancelled = 'cancelled';` to `ApprovalStatus`.
2. Update `allowedTransitions()` for `Draft`, `Submitted`, and `UnderReview` to
   include `self::Cancelled`.
3. Add `POST /requests/{id}/cancel` route and handler.
4. Write the DB UPDATE in the repository.
5. Update the schema `CHECK` constraint (if added).

The enum is the single source of truth — no other files need to change to add the
transition guard.

---

## Related howtos

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — draft → publish lifecycle (simpler state machine)
- [`media-watchlist.md`](media-watchlist.md) — backed enum validation with `tryFrom()`
- [`add-custom-route.md`](add-custom-route.md) — POST action endpoint pattern
- [`multi-step-workflow.md`](multi-step-workflow.md) — generic multi-step workflow patterns
