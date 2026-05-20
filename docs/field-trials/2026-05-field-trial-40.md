# Field Trial 40 — Approval Workflow / State Machine API (flowlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/flowlog/`
**NENE2 version**: 1.5.17
**Theme**: State machine enum, valid transition enforcement, 422 on invalid transitions, `PostStatus` backed enum, optional body for action endpoints

## Overview

Built a content moderation API with a four-state workflow:
`draft → submitted → approved | rejected`

Each post tracks its `status` column (SQLite `CHECK` constraint). State transitions are enforced by a `PostStatus` PHP enum with a `canTransitionTo()` method. Invalid transitions throw `InvalidTransitionException → 422`. The `reject` action accepts an optional `reason` field in the request body.

## Endpoints Implemented

- `POST /posts` — create (title, body, author), status starts at `draft`
- `GET /posts` — list, paginated, optional `?status=` filter
- `GET /posts/{id}` — show with current status
- `POST /posts/{id}/submit` — draft → submitted
- `POST /posts/{id}/approve` — submitted → approved
- `POST /posts/{id}/reject` — submitted → rejected (optional `reason` body)

## Test Results

22 tests, 40 assertions — pass after 1 fix (optional body handling).

---

## Frictions Found

### Friction 1 — `JsonRequestBodyParser::parse()` throws 400 for empty body on action endpoints [LOW]

**Symptom**: `POST /posts/{id}/reject` (no request body) returns 400 instead of executing the transition.

**Root cause**: `JsonRequestBodyParser::parse()` throws `JsonBodyParseException` (→ 400 Bad Request) when the request body is empty. This is documented and correct for endpoints where a body is required. However, the `reject` action accepts an optional `reason` — if no body is sent, the reason should default to `null`.

**Pattern**:

```php
// WRONG — throws 400 when body is absent
$body   = JsonRequestBodyParser::parse($request);
$reason = isset($body['reason']) ? $body['reason'] : null;

// CORRECT — check for empty body first
$raw    = (string) $request->getBody();
$reason = null;
if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null;
}
```

**NENE2 impact**: Low — the behaviour is correct (body is required by default). The pattern for optional bodies is straightforward once discovered. Should add a note to `docs/howto/add-custom-route.md` or the PATCH howto about optional body handling.

---

## Patterns Validated

### PHP Backed Enum for state machine

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
            self::Rejected  => false,
        };
    }
}
```

Backed enums hydrate cleanly from SQLite TEXT with `PostStatus::from((string) $row['status'])`. `tryFrom()` returns `null` for invalid values.

### SQLite CHECK constraint for status column

```sql
status TEXT NOT NULL DEFAULT 'draft'
       CHECK(status IN ('draft', 'submitted', 'approved', 'rejected'))
```

The CHECK constraint provides a database-level safety net, but the application layer enforces transitions first and provides better error messages.

### Status filter with `PostStatus::tryFrom()`

```php
$statusStr = QueryStringParser::string($request, 'status');
if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([...]);
    }
    // filter by status
}
```

`tryFrom()` + `null` check converts the 422 path cleanly without a try-catch.

### InvalidTransitionException → 422 via DomainExceptionHandlerInterface

Using `status: 422` (not 409) for invalid state transitions is intentional — 422 Unprocessable Content means the server understands the request format but cannot process it due to semantic errors (invalid transition). 409 Conflict is reserved for resource-level conflicts (e.g., optimistic locking).

---

## NENE2 Changes Required

| # | Change | Priority |
|---|--------|----------|
| 1 | Add optional body handling note to action endpoint howto | Low |

No version bump needed.
