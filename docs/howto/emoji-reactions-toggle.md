---
title: "How-to: Emoji Reactions with Toggle and Grouped Counts"
category: product
tags: [emoji, reactions, toggle, concurrency, unique-constraint]
difficulty: intermediate
related: [emoji-reactions-api, emoji-reaction-system, upvote-downvote-api]
---

# How-to: Emoji Reactions with Toggle and Grouped Counts

> **FT reference**: FT263 (`NENE2-FT/reactionlog`) — Emoji reactions: toggle (add/remove), grouped counts, per-user reaction list

Demonstrates a reaction API where each user can react to any target (post, comment, etc.) with
any emoji or reaction type. A single `PUT` endpoint toggles the reaction: adds it if not present,
removes it if already present. Grouped counts per reaction type are returned in a summary query.
A composite `UNIQUE` constraint enforces one-reaction-per-user-per-type, and
`DatabaseConstraintException` handles concurrent toggle races.

---

## Routes

| Method   | Path                                               | Description                              |
|----------|----------------------------------------------------|------------------------------------------|
| `PUT`    | `/reactions/{targetType}/{targetId}`               | Toggle a reaction (add or remove)        |
| `DELETE` | `/reactions/{targetType}/{targetId}/{reactionType}`| Explicitly remove a specific reaction    |
| `GET`    | `/reactions/{targetType}/{targetId}`               | Get reaction summary (grouped counts)    |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_target ON reactions (target_id, target_type);
CREATE INDEX IF NOT EXISTS idx_reactions_user   ON reactions (user_id);
```

`UNIQUE(target_id, target_type, reaction_type, user_id)` enforces one record per unique
(target, user, reaction) combination. An attempt to insert a duplicate raises a constraint
violation, which the application catches as `DatabaseConstraintException`.

`target_type` allows the same reaction system to serve multiple entity types (`post`, `comment`,
`message`) without separate tables.

---

## Toggle pattern

```php
public function toggle(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $existing = $this->db->fetchOne(
        'SELECT id FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );

    if ($existing !== null) {
        $this->db->execute('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        return false;   // reaction was removed
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        $this->db->execute(
            'INSERT INTO reactions (target_id, target_type, reaction_type, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$targetId, $targetType, $reactionType, $userId, $now],
        );
    } catch (DatabaseConstraintException) {
        // Race condition: concurrent toggle from same user — treat as removed
        return false;
    }

    return true;   // reaction was added
}
```

**Flow**:
1. `SELECT` to check if the reaction exists.
2. If found: `DELETE` → return `false` (removed).
3. If not found: `INSERT` → return `true` (added).
4. If the `INSERT` fails with a UNIQUE violation (`DatabaseConstraintException`): a concurrent
   request inserted the same row between our `SELECT` and `INSERT`. Treat this as "removed"
   (the concurrent toggle won) → return `false`.

**Why `SELECT` then `INSERT`?** An alternative is `INSERT OR IGNORE` and checking `changes() == 0`
to detect the case where the row already existed. The explicit `SELECT` approach makes the intent
clearer and produces a cleaner return value (added vs removed) without requiring a subsequent query.

---

## Controller: 201 on add, 200 on remove

```php
$added = $this->repo->toggle($targetId, $targetType, $reactionType, $userId);

return $this->json->create([
    'target_id'     => $targetId,
    'target_type'   => $targetType,
    'reaction_type' => $reactionType,
    'user_id'       => $userId,
    'added'         => $added,
], $added ? 201 : 200);
```

`201 Created` when the reaction is added; `200 OK` when it is removed. The `added` field
in the response body lets clients distinguish the two cases without checking the status code.

**Why `PUT` for toggle?** `PUT` is idempotent by HTTP semantics. A single-user toggle is
idempotent in effect (two identical `PUT`s return to the original state). Alternatively,
`POST` is acceptable for a non-idempotent toggle; the choice depends on team convention.

---

## Grouped counts summary

```php
public function summary(string $targetId, string $targetType, ?string $userId): ReactionSummary
{
    $rows = $this->db->fetchAll(
        'SELECT reaction_type, COUNT(*) AS cnt
           FROM reactions
          WHERE target_id = ? AND target_type = ?
          GROUP BY reaction_type
          ORDER BY cnt DESC',
        [$targetId, $targetType],
    );

    $counts = [];
    $total  = 0;
    foreach ($rows as $row) {
        $counts[(string) $row['reaction_type']] = (int) $row['cnt'];
        $total += (int) $row['cnt'];
    }

    $userReactions = [];
    if ($userId !== null) {
        $userRows = $this->db->fetchAll(
            'SELECT reaction_type FROM reactions WHERE target_id = ? AND target_type = ? AND user_id = ? ORDER BY created_at ASC',
            [$targetId, $targetType, $userId],
        );
        $userReactions = array_map(fn (array $r) => (string) $r['reaction_type'], $userRows);
    }

    return new ReactionSummary($targetId, $targetType, $counts, $total, $userReactions);
}
```

Two queries:
1. Grouped counts: `GROUP BY reaction_type ORDER BY cnt DESC` — most popular first.
2. Per-user reactions (if `$userId` is provided): which reaction types this user has applied.

`ORDER BY cnt DESC` puts the most-used reactions first, matching typical display priority.

---

## Example summary response

**Request**: `GET /reactions/post/42?user_id=alice`

```json
{
  "target_id": "42",
  "target_type": "post",
  "counts": {
    "👍": 15,
    "❤️": 8,
    "😂": 3
  },
  "total": 26,
  "user_reactions": ["👍"]
}
```

`counts` is a map from reaction type to count. `user_reactions` is the list of reactions
`alice` has applied. The client can highlight `👍` to indicate alice's active reaction.

---

## Explicit remove endpoint

```php
public function remove(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $count = $this->db->execute(
        'DELETE FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );
    return $count > 0;
}
```

`DELETE /reactions/{targetType}/{targetId}/{reactionType}` with `user_id` in the body removes
a specific reaction without toggle semantics. Useful when the client wants to remove a specific
reaction type regardless of current state.

Returns 404 if no matching reaction was found (`$count == 0`).

---

## Composite UNIQUE constraint as safety net

The `UNIQUE(target_id, target_type, reaction_type, user_id)` constraint:
- **Primary enforcement**: prevents duplicate reactions at the DB level.
- **Secondary benefit**: catches race conditions that slip past the `SELECT` check.
- **Application logic**: `toggle()` catches `DatabaseConstraintException` and treats it as a removal.

Without the constraint, a race between two concurrent `PUT` requests from the same user
would insert two identical rows. The constraint + exception handler keeps the invariant
(one row per user per reaction type) even under concurrency.

---

## Design notes

| Decision | Choice | Rationale |
|---|---|---|
| Toggle endpoint | `PUT` | Semantically appropriate; idempotent |
| Reaction identity | 4-column composite key | No separate reaction type table needed |
| `target_type` | PATH parameter | Allows one endpoint to serve multiple entity types |
| `user_id` in request body | Required field | Avoids requiring auth middleware for this FT |
| `user_id` in summary | Query parameter | Optional — summary is public; per-user detail is opt-in |

---

## Related howtos

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — M:N join table with INSERT OR IGNORE for tag deduplication
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — composite unique keys as DB-level safety nets
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomic operations when multiple writes must succeed or fail together
