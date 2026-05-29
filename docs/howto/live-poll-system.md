---
title: "How-to: Live Poll System"
category: product
tags: [poll, voting, deduplication, lifecycle]
difficulty: intermediate
related: [poll-survey, voting-system]
---

# How-to: Live Poll System

## Overview

This guide covers building a live poll system API with NENE2, including admin-gated poll creation, per-user vote deduplication, poll lifecycle management, and result aggregation.

**Reference implementation**: `../NENE2-FT/polllog/`

---

## Schema Design

```sql
CREATE TABLE IF NOT EXISTS polls (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    question   TEXT    NOT NULL,
    closed     INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS poll_options (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    label   TEXT    NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id   INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id   INTEGER NOT NULL,
    voted_at  TEXT    NOT NULL,
    UNIQUE (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
);
```

Key constraints:
- `UNIQUE (poll_id, user_id)` — prevents a user from voting more than once per poll.
- `ON DELETE CASCADE` — removes options and votes when a poll is deleted.

---

## Route Table

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/polls` | Admin | Create a poll with options |
| `GET` | `/polls` | None | List all polls |
| `GET` | `/polls/{id}` | None | Get poll with vote counts |
| `POST` | `/polls/{id}/vote` | User | Cast a vote |
| `POST` | `/polls/{id}/close` | Admin | Close a poll |

---

## Admin Authentication Pattern

Pass a shared secret in `X-Admin-Key` header. Use fail-closed logic:

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;          // fail-closed: no key configured → never admin
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

Return `403 Forbidden` when not admin:
```php
if (!$this->isAdmin($req)) {
    return $this->problem(403, 'forbidden', 'Admin key required.');
}
```

---

## Creating Polls with Options

Validate at least 2 options; insert in a transaction:

```php
public function create(string $question, array $options): array
{
    $now  = $this->now();
    $stmt = $this->pdo->prepare('INSERT INTO polls (question, closed, created_at) VALUES (?, 0, ?)');
    $stmt->execute([$question, $now]);
    $pollId = (int) $this->pdo->lastInsertId();

    $ins = $this->pdo->prepare('INSERT INTO poll_options (poll_id, label) VALUES (?, ?)');
    foreach ($options as $label) {
        $ins->execute([$pollId, $label]);
    }

    return $this->findById($pollId);
}
```

---

## Vote with Deduplication

Catch the UNIQUE constraint violation to detect duplicate votes:

```php
public function vote(int $pollId, int $optionId, int $userId): string
{
    $poll = $this->findById($pollId);
    if ($poll === null) return 'not_found';
    if ($poll['closed']) return 'poll_closed';

    // Verify option belongs to this poll
    $stmt = $this->pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $stmt->execute([$optionId, $pollId]);
    if ($stmt->fetch() === false) return 'invalid_option';

    try {
        $this->pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at) VALUES (?, ?, ?, ?)'
        )->execute([$pollId, $optionId, $userId, $this->now()]);
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) return 'already_voted';
        throw $e;
    }

    return 'ok';
}
```

---

## Aggregating Vote Counts

Use `LEFT JOIN` to include options with zero votes:

```sql
SELECT po.id, po.label, COUNT(pv.id) AS votes
FROM poll_options po
LEFT JOIN poll_votes pv ON pv.option_id = po.id
WHERE po.poll_id = :poll_id
GROUP BY po.id, po.label
ORDER BY po.id ASC
```

---

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Poll created | 201 |
| Vote cast | 201 |
| Poll found / closed | 200 |
| Poll not found | 404 |
| Invalid option ID | 422 |
| Missing question or < 2 options | 422 |
| Non-integer option_id | 422 |
| Already voted | 409 |
| Voting on closed poll | 409 |
| No admin key | 403 |
| No X-User-Id header | 400 |

---

## Validation Checklist

- `question`: non-empty string
- `options`: array of ≥ 2 non-empty strings
- `option_id`: must be `is_int()` (reject strings like `'1'`)
- `X-User-Id`: `ctype_digit()` + positive integer
- Poll must exist before voting or closing
- Option must belong to the target poll (cross-poll injection)

---

## Security Notes

- **Admin key fail-closed**: empty key means no-one is admin.
- **Use `hash_equals()`** to prevent timing attacks on the admin key comparison.
- **UNIQUE constraint** is the authoritative duplicate-vote guard — application-level check alone is not sufficient under concurrent load.
- **Option ownership check** prevents voting with an option from a different poll.
