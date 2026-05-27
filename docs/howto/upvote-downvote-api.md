# How-to: Upvote / Downvote API

> **FT reference**: FT347 (`NENE2-FT/votelog`) — Per-user upvote/downvote with toggle-off (same direction twice removes vote), direction change (up→down atomically), score aggregation (upvotes − downvotes), UNIQUE(user_id, item_id) constraint, 15 tests PASS.

This guide shows how to implement a Reddit/Stack Overflow–style voting system: each user can cast one vote per item, toggle it off by re-voting in the same direction, or change direction.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (item_id)  REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` enforces one vote per user per item. `CHECK(direction IN ('up', 'down'))` rejects any other value at the DB level.

## Endpoints

| Method | Path                          | Description               |
|--------|-------------------------------|---------------------------|
| `POST` | `/items/{id}/vote`            | Cast, toggle, or change vote |
| `GET`  | `/items/{id}/score`           | Get item score            |
| `GET`  | `/items/{id}/vote/{userId}`   | Get user's current vote   |

## Cast a Vote

```php
POST /items/1/vote
{"user_id": 42, "direction": "up"}

→ 200
{
  "vote": "up",
  "score": {
    "upvotes": 1,
    "downvotes": 0,
    "score": 1
  }
}
```

```php
POST /items/1/vote
{"user_id": 43, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 1, "downvotes": 1, "score": 0}}
```

## Toggle Off (Same Direction Twice)

Voting in the **same direction** a second time removes the vote:

```php
// First vote
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"score": 1}}

// Second vote in same direction → toggle off
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": null, "score": {"score": 0}}
```

`vote: null` means the user has no active vote on this item.

## Change Direction

Voting in the **opposite direction** flips the existing vote atomically:

```php
// Start with upvote
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"upvotes": 1, "downvotes": 0, "score": 1}}

// Change to downvote
POST /items/1/vote  {"user_id": 42, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 0, "downvotes": 1, "score": -1}}
```

## Get Score

```php
GET /items/1/score

→ 200
{
  "upvotes": 2,
  "downvotes": 1,
  "score": 1         // upvotes − downvotes
}
```

Score for an item with no votes:

```php
GET /items/1/score   // no votes yet
→ 200  {"upvotes": 0, "downvotes": 0, "score": 0}
```

## Get User Vote State

```php
// No vote cast yet
GET /items/1/vote/42
→ 200  {"vote": null}

// After upvote
GET /items/1/vote/42
→ 200  {"vote": "up"}

// After toggle-off
GET /items/1/vote/42
→ 200  {"vote": null}
```

## Implementation

### Vote Handler Logic

```php
public function vote(int $itemId, int $userId, string $direction): array
{
    $item = $this->repo->findItem($itemId);
    if ($item === null) {
        throw new ItemNotFoundException($itemId);
    }

    $existing = $this->repo->findVote($userId, $itemId);

    if ($existing !== null && $existing['direction'] === $direction) {
        // Same direction → toggle off
        $this->repo->deleteVote($userId, $itemId);
        $activeDirection = null;
    } elseif ($existing !== null) {
        // Different direction → update in place
        $this->repo->updateVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    } else {
        // New vote
        $this->repo->insertVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    }

    $score = $this->repo->getScore($itemId);

    return [
        'vote'  => $activeDirection,
        'score' => $score,
    ];
}
```

### Score Aggregation SQL

```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE 0 END), 0) AS upvotes,
    COALESCE(SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END), 0) AS downvotes,
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE -1 END), 0) AS score
FROM votes
WHERE item_id = ?
```

`COALESCE(..., 0)` ensures zero values when no votes exist (SUM of empty set returns NULL).

### Vote UPSERT Pattern

```sql
-- Insert new vote
INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)

-- Update direction (UNIQUE constraint prevents duplicate)
UPDATE votes SET direction = ? WHERE user_id = ? AND item_id = ?

-- Delete (toggle off)
DELETE FROM votes WHERE user_id = ? AND item_id = ?
```

## Validation

```php
// Invalid direction
POST /items/1/vote  {"user_id": 42, "direction": "sideways"}
→ 422  // direction must be 'up' or 'down'

// Item does not exist
POST /items/9999/vote  {"user_id": 42, "direction": "up"}
→ 404
```

## Multiple Users

```php
// Three users vote on the same item
POST /items/1/vote  {"user_id": 1, "direction": "up"}
POST /items/1/vote  {"user_id": 2, "direction": "up"}
POST /items/1/vote  {"user_id": 3, "direction": "down"}

GET /items/1/score
→ 200  {"upvotes": 2, "downvotes": 1, "score": 1}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE(user_id, item_id)` | Users can vote multiple times, inflating scores |
| `INSERT OR REPLACE` for direction change | Generates a new `id` and `created_at`; loses vote history; breaks audit trails |
| Return 409 on toggle-off | Toggle-off is expected behaviour, not an error; return the new (null) vote state |
| Compute score in application by fetching all votes | O(N) per request; use SQL aggregation with a single query |
| Allow `direction: null` to remove vote via body | Ambiguous; use the toggle pattern (same direction twice) or a separate DELETE endpoint |
| Skip `COALESCE` in score aggregation | `SUM()` returns `NULL` when no rows match; `null − null` crashes or returns wrong type |
