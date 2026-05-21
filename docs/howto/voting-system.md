# Voting System (Upvote / Downvote)

Allow users to upvote or downvote items. Each user can cast at most one vote per item. Voting in the same direction twice toggles the vote off. Voting in the opposite direction switches the vote.

## Overview

A voting system involves:
- **Cast vote**: upvote or downvote an item
- **Toggle**: casting the same direction twice removes the vote
- **Switch**: casting the opposite direction replaces the current vote
- **Score**: upvotes − downvotes, returned with every vote response
- **Current vote**: retrieve a user's current vote for an item (for UI highlighting)

## Database Schema

```sql
CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

The `UNIQUE (user_id, item_id)` constraint enforces one vote per user per item at the database level. `CHECK (direction IN ('up', 'down'))` prevents invalid values even if application-level validation is bypassed.

## Direction as Enum

Use a backed enum to prevent invalid direction values reaching the repository:

```php
enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
```

Parse with `VoteDirection::tryFrom($dirStr)` — returns `null` for invalid input, enabling clean 422 handling without a match/switch.

## Toggle and Switch Logic

All three cases (toggle off, switch direction, new vote) are handled in the repository:

```php
public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
{
    $current = $this->getCurrentVote($userId, $itemId);

    if ($current === $direction) {
        // same direction → toggle off
        $this->executor->execute(
            'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );
        return null;
    }

    if ($current !== null) {
        // different direction → switch
        $this->executor->execute(
            'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
            [$direction->value, $now, $userId, $itemId],
        );
    } else {
        // no existing vote → insert
        $this->executor->execute(
            'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $direction->value, $now],
        );
    }

    return $direction;
}
```

The return value `?VoteDirection` lets the handler know whether the vote is now set (`'up'`/`'down'`) or removed (`null`).

## Returning Score with Every Vote

Include the updated score in the vote response so clients can refresh counters without a separate GET:

```php
$result = $this->repo->castVote($userId, $itemId, $direction, $now);
$score  = $this->repo->getScore($itemId);

return $this->responseFactory->create([
    'user_id' => $userId,
    'item_id' => $itemId,
    'vote'    => $result !== null ? $result->value : null,
    'score'   => $score->toArray(),
]);
```

## Score Computation

Separate COUNT queries per direction are simpler and more readable than a single GROUP BY:

```php
public function getScore(int $itemId): ItemScore
{
    $upRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
        [$itemId],
    );
    $downRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
        [$itemId],
    );
    ...
}
```

`score = upvotes - downvotes`. Zero is the initial state before any votes are cast.

## User Vote State

A separate endpoint lets the UI show which direction the current user voted (for button highlighting):

```php
// GET /items/{itemId}/vote/{userId}
$current = $this->repo->getCurrentVote($userId, $itemId);
return ['vote' => $current !== null ? $current->value : null];
```

Returns `null` when the user has not voted (or toggled their vote off).

## Security Properties

| Property | Implementation |
|---|---|
| One vote per user per item | `UNIQUE (user_id, item_id)` DB constraint |
| Invalid direction rejected | `CHECK (direction IN ('up', 'down'))` + `VoteDirection::tryFrom()` |
| Unknown user/item | Returns 404 — no leaking of resource existence |
| Toggle safety | Checks current vote before DELETE/UPDATE |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/users` | Create a user |
| `POST` | `/items` | Create an item |
| `POST` | `/items/{itemId}/vote` | Cast, switch, or toggle a vote |
| `GET` | `/items/{itemId}/score` | Get upvotes, downvotes, and score |
| `GET` | `/items/{itemId}/vote/{userId}` | Get a user's current vote for an item |
