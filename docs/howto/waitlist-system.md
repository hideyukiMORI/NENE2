---
title: "How-to: Waitlist System"
category: product
tags: [waitlist, state-machine, queue, approval]
difficulty: intermediate
related: [waitlist-management]
---

# How-to: Waitlist System

> **FT reference**: FT287 (`NENE2-FT/waitlistlog`) — Waitlist system: UNIQUE(user_id) one-entry constraint, waiting→approved/declined state machine, isTerminal() guard, /waitlist/me registered before /{id} to prevent route capture, X-Admin-Key authentication, queue position tracking, 39 tests / 98 assertions PASS.

This guide shows how to build a waitlist system where users join a queue and administrators approve or decline entries.

## Schema

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL UNIQUE,   -- one entry per user
    status     TEXT    NOT NULL DEFAULT 'waiting',  -- waiting | approved | declined
    note       TEXT,                               -- optional user-provided note (max 500 chars)
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`user_id UNIQUE` enforces one entry per user at the DB level — no application-layer check needed for race conditions.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/waitlist` | `X-User-Id` | Join the waitlist |
| `GET` | `/waitlist/me` | `X-User-Id` | Get own status + position |
| `DELETE` | `/waitlist/me` | `X-User-Id` | Leave the waitlist |
| `GET` | `/waitlist` | `X-Admin-Key` | Admin: list all entries |
| `POST` | `/waitlist/{id}/approve` | `X-Admin-Key` | Admin: approve entry |
| `POST` | `/waitlist/{id}/decline` | `X-Admin-Key` | Admin: decline entry |

## Route Registration Order

`/waitlist/me` must be registered **before** `/waitlist/{id}` to prevent the path parameter from capturing the literal string `"me"`:

```php
// CORRECT: static path before dynamic path
$this->router->get('/waitlist/me', $this->handleMe(...));
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));

// WRONG: {id} would capture "me"
$this->router->post('/waitlist/{id}/approve', $this->handleApprove(...));
$this->router->get('/waitlist/me', $this->handleMe(...));  // never reached
```

## Status Lifecycle

```
waiting ──────→ approved (terminal)
       └──────→ declined (terminal)
```

Once approved or declined, an entry cannot transition to another state. The `isTerminal()` method guards this:

```php
enum WaitlistStatus: string
{
    case Waiting  = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
```

## Joining with 409 on Duplicate

```php
$entry = $this->repository->join($userId, $note);

if ($entry === null) {
    return $this->responseFactory->create(['error' => 'Already on the waitlist.'], 409);
}
```

The repository returns `null` when `user_id` already exists (caught from `DatabaseConstraintException`). The response is 409 Conflict.

## Position Tracking

```php
$position = $this->repository->positionOf($entry);

// positionOf() counts entries with status='waiting' and id <= $entry->id
// SELECT COUNT(*) FROM waitlist_entries WHERE status = 'waiting' AND id <= ?
```

Position is the 1-based rank in the `waiting` queue. Approved/declined entries don't count. This gives users a meaningful place in line.

## Admin Transition with match

```php
private function handleTransition(int $id, WaitlistStatus $newStatus): ResponseInterface
{
    $result = $this->repository->transition($id, $newStatus);

    return match ($result) {
        'ok'               => $this->responseFactory->create(['status' => $newStatus->value]),
        'not_found'        => $this->responseFactory->create(['error' => 'Entry not found.'], 404),
        'already_terminal' => $this->responseFactory->create(['error' => 'Entry is already approved or declined.'], 409),
        default            => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
    };
}
```

`match` is exhaustive — the `default` case catches any unexpected return values from the repository.

## Leave (Only While Waiting)

```php
return match ($result) {
    'removed'     => $this->responseFactory->create(['removed' => true], 200),
    'not_found'   => $this->responseFactory->create(['error' => 'Not on the waitlist.'], 404),
    'not_waiting' => $this->responseFactory->create(['error' => 'Cannot leave — status is no longer waiting.'], 409),
    default       => $this->responseFactory->create(['error' => 'Unexpected error.'], 500),
};
```

Once approved or declined, a user cannot leave — their decision is recorded. This prevents gaming the system (approve then leave to avoid tracking).

## Admin Authentication

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false;  // fail-closed: no key configured → no admin access
    }
    return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` prevents timing attacks. Empty admin key always returns false (fail-closed).

## Note Validation

```php
private const int MAX_NOTE_LEN = 500;

private function resolveNote(mixed $raw): ?string
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return mb_strlen($raw) > self::MAX_NOTE_LEN ? mb_substr($raw, 0, self::MAX_NOTE_LEN) : $raw;
}
```

Notes are optional (null if absent/empty), max 500 characters, truncated (not rejected) if too long.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE(user_id)` constraint | Concurrent joins create duplicate entries; race condition |
| Register `/{id}` before `/me` | `/waitlist/me` becomes unreachable — matched by `{id}` capturing `"me"` |
| Allow transition from terminal state | Approved entry declined after access granted; state machine broken |
| Allow leave from terminal state | Approved user leaves; access grant becomes orphaned |
| Return position based on `id ASC` counting all entries | Counts approved/declined users; position number is misleading |
| Store admin key in DB | Key rotation requires DB update; use env var instead |
| Use `==` instead of `hash_equals()` for admin key | Timing attack reveals key one character at a time |
| No admin fail-closed | Empty key in env allows unauthenticated admin access |
| Reject note if over limit | UX: truncating is friendlier than rejecting for soft metadata like notes |
