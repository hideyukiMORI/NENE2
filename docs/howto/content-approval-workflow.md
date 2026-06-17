---
title: "How-to: Content Approval Workflow"
category: product
tags: [approval, workflow, state-machine, content, moderation]
difficulty: intermediate
related: [approval-workflow, content-draft-lifecycle, step-workflow-approval]
---

# How-to: Content Approval Workflow

> **FT reference**: FT248 (`NENE2-FT/flowlog`) — Content Approval Workflow API
> **ATK**: FT248 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a post publication lifecycle where a `PostStatus` `BackedEnum` owns
the transition graph via `canTransitionTo()`, invalid transitions throw
`InvalidTransitionException → 409`, and rejection carries an optional reason. Includes
a full cracker-mindset attack assessment.

---

## Routes

| Method | Path                       | Description                                              |
|--------|----------------------------|----------------------------------------------------------|
| `POST` | `/posts`                   | Create a post (always starts as `draft`)                 |
| `GET`  | `/posts`                   | List posts (paginated, filterable by status)             |
| `GET`  | `/posts/{id}`              | Get a single post                                        |
| `POST` | `/posts/{id}/submit`       | Transition: `draft → submitted`                          |
| `POST` | `/posts/{id}/approve`      | Transition: `submitted → approved`                       |
| `POST` | `/posts/{id}/reject`       | Transition: `submitted → rejected` (optional reason)     |

> **Static action routes before parameterized**: `/posts/{id}/submit`, `/approve`,
> `/reject` are registered before `/posts/{id}` so literal sub-paths are not captured
> by the parameterized segment.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL DEFAULT '',
    author        TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'draft'
                           CHECK(status IN ('draft', 'submitted', 'approved', 'rejected')),
    reject_reason TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`status` has a DB-level `CHECK` constraint as a safety net; the application validates
via `PostStatus::canTransitionTo()` before any write. `reject_reason` is nullable —
only set on rejection.

---

## `PostStatus` BackedEnum with `canTransitionTo()`

The state transition graph is owned by the enum itself:

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Submitted,
            self::Submitted => $target === self::Approved || $target === self::Rejected,
            self::Approved,
            self::Rejected  => false,  // terminal states
        };
    }
}
```

The transition graph:
```
draft → submitted → approved (terminal)
                 → rejected  (terminal)
```

`Approved` and `Rejected` are terminal states — no further transitions are allowed.
Attempting to approve an already-approved post throws `InvalidTransitionException`.

---

## Repository transition method

```php
public function transition(int $id, PostStatus $targetStatus, string $now, ?string $rejectReason = null): Post
{
    $post = $this->findById($id);

    if (!$post->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($post->status, $targetStatus);
    }

    $this->executor->execute(
        'UPDATE posts SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $rejectReason, $now, $id],
    );

    return new Post($id, $post->title, $post->body, $post->author, $targetStatus, $rejectReason, $post->createdAt, $now);
}
```

The `transition()` method is shared by submit, approve, and reject — each handler
calls it with a different `$targetStatus`. `reject_reason` is `null` for approve/submit,
and optionally provided for reject.

---

## Status filter with `PostStatus::tryFrom()`

```php
$statusStr = QueryStringParser::string($request, 'status');

if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([
            new ValidationError('status', "Invalid status '{$statusStr}'. Valid values: draft, submitted, approved, rejected.", 'invalid'),
        ]);
    }
    $items = $this->repository->findByStatus($status, $pagination->limit, $pagination->offset);
}
```

`BackedEnum::tryFrom()` returns `null` for unknown string values rather than throwing.
The explicit `null` check produces a structured `422` with a readable error message
listing valid values.

---

## Rejection with optional reason

`POST /posts/{id}/reject` accepts an optional `reason` field:

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

An empty body `{}` or a missing `reason` field both result in `null`. A whitespace-only
reason string is also normalized to `null` via `trim()`. The reason is stored in the
nullable `reject_reason` column.

---

## ATK — Cracker-mindset attack test (FT248)

### ATK-01 — No authentication: anyone can approve or reject any post

**Attack**: Approve or reject a post without any credentials.

```bash
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/reject
```

**Observed**: Both succeed with `200 OK`. Any caller can push any post through any
allowed transition.

**Verdict**: **EXPOSED** — add authentication and role-based authorization. Only
designated reviewers should be able to approve/reject. Submitting should require
the post's author to be authenticated.

---

### ATK-02 — Invalid state transition: approve a draft

**Attack**: Try to approve a post that is still in `draft` status.

```bash
curl -X POST http://localhost:8200/posts/1/approve
# post 1 is in draft
```

**Observed**: `canTransitionTo(Approved)` returns `false` for `Draft` → `InvalidTransitionException`
→ `409 Conflict` with from/to context in the response.

**Verdict**: **BLOCKED** — enum-owned transition graph prevents illegal state jumps.

---

### ATK-03 — Double approval: approve an already-approved post

**Attack**: Approve a post a second time.

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/approve  # second approve
```

**Observed**: Third request: `canTransitionTo(Approved)` from `Approved` → `false`
→ `409 Conflict`. The post remains in `Approved` state.

**Verdict**: **BLOCKED** — `Approved` is a terminal state; the enum explicitly returns
`false` for all transitions from terminal states.

---

### ATK-04 — SQL injection via title or body

**Attack**: Embed SQL metacharacters.

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**Observed**: Values are bound via parameterized `?` placeholders. The injection payload
is stored as literal text.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection.

---

### ATK-05 — Invalid status filter value

**Attack**: Pass an unknown status to the list endpoint.

```
GET /posts?status=hacked
GET /posts?status=published
```

**Observed**: `PostStatus::tryFrom('hacked')` returns `null` → `ValidationException`
→ `422 Unprocessable Entity` with the list of valid statuses.

**Verdict**: **BLOCKED** — `BackedEnum::tryFrom()` + explicit null check rejects
unknown status values.

---

### ATK-06 — Author impersonation

**Attack**: Create a post claiming to be a privileged author.

```json
{"title": "Official announcement", "author": "admin"}
```

**Observed**: `201 Created` — the `author` field is taken verbatim from the request body
without verification. Any string is accepted.

**Verdict**: **EXPOSED** — `author` is user-supplied with no cryptographic binding.
In production, derive `author` from the authenticated session/token, never from
the request body.

---

### ATK-07 — Mass assignment: inject `status` on create

**Attack**: Set `status` to `approved` directly during creation.

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**Observed**: `createPost()` ignores any `status` field in the body — it always
inserts `PostStatus::Draft->value`. The extra key is silently discarded.

**Verdict**: **BLOCKED** — the controller builds the INSERT with a hardcoded
`PostStatus::Draft->value` value; no body field can override it.

---

### ATK-08 — XSS payload in title, body, or author

**Attack**: Store a script tag.

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**Observed**: Content is stored as-is and returned verbatim in JSON. The API does not
HTML-encode output.

**Verdict**: **ACCEPTED BY DESIGN** — JSON APIs return raw content. The rendering
layer must sanitize before inserting into HTML.

---

### ATK-09 — Non-numeric post ID

**Attack**: Use a string or float as `{id}`.

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**Observed**: `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → no row → `PostNotFoundException` → `404 Not Found`.
- `1.5` → `findById(1)` → if post 1 exists, its transition is triggered.

**Verdict**: **PARTIALLY BLOCKED** — non-numeric strings map to 404. Float strings
are silently truncated. Add `ctype_digit()` for strict ID validation.

---

### ATK-10 — Empty title or empty author

**Attack**: Submit with blank fields.

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**Observed**: `trim($body['title']) === ''` and `trim($body['author']) === ''`
checks fire → `ValidationException` → `422`.

**Verdict**: **BLOCKED** — trim + empty-string checks cover both empty and
whitespace-only values.

---

### ATK-11 — Reject without providing a reason

**Attack**: Reject with an empty body or no `reason` field.

```bash
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject -d '{}'
curl -X POST http://localhost:8200/posts/1/reject -d '{"reason": ""}'
```

**Observed**: All three cases produce `null` for `reject_reason`. Rejection without a
reason is accepted — the column is nullable.

**Verdict**: **ACCEPTED BY DESIGN** — `reject_reason` is optional. For production
workflows requiring a mandatory rejection reason, add `if ($reason === null) → 422`.

---

### ATK-12 — Reject a rejected post (double rejection)

**Attack**: Try to reject a post that is already rejected.

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject  # second reject
```

**Observed**: `canTransitionTo(Rejected)` from `Rejected` → `false` → `409 Conflict`.

**Verdict**: **BLOCKED** — `Rejected` is a terminal state; the enum explicitly returns
`false` for all transitions from terminal states.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | No authentication on approve/reject | EXPOSED |
| ATK-02 | Invalid transition (approve draft) | BLOCKED |
| ATK-03 | Double approval | BLOCKED |
| ATK-04 | SQL injection via title/body | BLOCKED |
| ATK-05 | Invalid status filter value | BLOCKED |
| ATK-06 | Author impersonation | EXPOSED |
| ATK-07 | Mass assignment of status on create | BLOCKED |
| ATK-08 | XSS payload in content | ACCEPTED BY DESIGN |
| ATK-09 | Non-numeric post ID | PARTIALLY BLOCKED |
| ATK-10 | Empty title or empty author | BLOCKED |
| ATK-11 | Reject without reason (optional) | ACCEPTED BY DESIGN |
| ATK-12 | Double rejection | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01** — Add authentication and role-based authorization (reviewer role for approve/reject)
2. **ATK-06** — Derive `author` from verified identity, never from request body
3. **ATK-09** — Add `ctype_digit()` guard for ID path parameters

---

## Related howtos

- [`state-machine-audit-log.md`](state-machine-audit-log.md) — state transition with audit history and InvalidTransitionException
- [`approval-workflow.md`](approval-workflow.md) — approval request with multiple approvers
- [`step-workflow-approval.md`](step-workflow-approval.md) — multi-step workflow with ordered steps
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — draft/publish lifecycle patterns
