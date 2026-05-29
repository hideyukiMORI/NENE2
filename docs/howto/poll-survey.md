---
title: "How-to: Poll / Survey API"
category: product
tags: [poll, survey, voting, duplicate-prevention]
difficulty: intermediate
related: [voting-system, live-poll-system, feedback-collection]
---

# How-to: Poll / Survey API

This guide shows how to build a poll and survey system with duplicate vote prevention using NENE2.
Pattern demonstrated by the **polllog** field trial (FT217).

## Features

- Create polls with 2–20 options (admin only)
- Public and private polls (private: admin-only access)
- One vote per user per poll (enforced by UNIQUE constraint)
- Live result aggregation with per-option vote counts
- Total vote sum across all options

## Schema

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    label      TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id    INTEGER NOT NULL,
    option_id  INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),  -- One vote per user per poll
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_votes_poll ON votes (poll_id, option_id);
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/polls` | Admin | Create poll with options |
| `GET` | `/polls/{id}` | Public | Get poll (private → 404 for non-admin) |
| `POST` | `/polls/{id}/vote` | User | Cast vote |
| `GET` | `/polls/{id}/results` | Public | Get result counts per option |

## Option Validation

```php
private const int MIN_OPTIONS   = 2;
private const int MAX_OPTIONS   = 20;
private const int MAX_LABEL_LEN = 100;

foreach ($rawOptions as $idx => $label) {
    if (!is_string($label) || trim($label) === '') {
        return $this->problem(422, 'validation-failed', "options[{$idx}] must not be empty.");
    }
    if (strlen($label) > self::MAX_LABEL_LEN) {
        return $this->problem(422, 'validation-failed', "options[{$idx}] too long (max 100).");
    }
}
```

## Duplicate Vote Prevention

```php
/** @return 'ok'|'already_voted'|'invalid_option' */
public function vote(int $pollId, int $userId, int $optionId): string
{
    // Verify option belongs to poll (prevents cross-poll option injection)
    $stmt = $this->pdo->prepare(
        'SELECT id FROM poll_options WHERE id = :oid AND poll_id = :pid'
    );
    $stmt->execute([':oid' => $optionId, ':pid' => $pollId]);
    if ($stmt->fetch() === false) {
        return 'invalid_option'; // → 422
    }

    // Check for existing vote
    $stmt2 = $this->pdo->prepare(
        'SELECT id FROM votes WHERE poll_id = :pid AND user_id = :uid'
    );
    if ($stmt2->fetch() !== false) {
        return 'already_voted'; // → 409
    }

    // INSERT — UNIQUE(poll_id, user_id) constraint is a safety net
    $this->pdo->prepare('INSERT INTO votes ...')->execute([...]);
    return 'ok';
}
```

## Result Aggregation

Using `LEFT JOIN` ensures options with zero votes still appear in results:

```sql
SELECT o.id, o.label, o.sort_order,
       COUNT(v.id) AS votes
FROM poll_options o
LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
WHERE o.poll_id = :pid
GROUP BY o.id, o.label, o.sort_order
ORDER BY o.sort_order ASC, o.id ASC
```

```php
$results    = $this->repo->results($id);
$totalVotes = array_sum(array_column($results, 'votes'));

return $this->json([
    'poll_id'     => $id,
    'total_votes' => $totalVotes,
    'results'     => $results,
]);
```

## Private Poll Access Control

Private polls return 404 for non-admin users (existence hiding):

```php
// GET /polls/{id}
if (!(bool) $poll['is_public'] && !$this->isAdmin($req)) {
    return $this->problem(404, 'not-found', 'Poll not found.');
}
```

## Security Patterns

- **Admin fail-closed**: `if ($this->adminKey === '') return false;` before `hash_equals()`
- **`is_int()`**: Strict type check for `option_id` — rejects floats/strings
- **`ctype_digit()`**: ReDoS-safe integer validation for path IDs
- **Cross-poll option injection**: `WHERE id = :oid AND poll_id = :pid` prevents using option from different poll
- **`is_bool()`**: Strict check for `is_public` flag — rejects `1`/`0`/`"true"` etc.
