# How-to: Feedback Collection API

## Overview

A feedback system where users submit a score (1-5) and comment for a target entity. Admin can list all feedback; public stats endpoint shows aggregate averages.

**Reference implementation**: `../NENE2-FT/feedbacklog/`

## Schema

```sql
CREATE TABLE IF NOT EXISTS feedback (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    target     TEXT    NOT NULL,
    score      INTEGER NOT NULL,   -- 1-5
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, target)
);
```

## Routes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/feedback` | User | Submit feedback |
| `GET` | `/feedback` | Admin | List all feedback |
| `GET` | `/feedback/stats` | None | Aggregate stats |

## Duplicate Prevention

`UNIQUE (user_id, target)` enforces one-feedback-per-user-per-target at the DB level. Application-level check first:

```php
$stmt = $this->pdo->prepare('SELECT id FROM feedback WHERE user_id = :uid AND target = :tgt');
$stmt->execute([...]);
if ($stmt->fetch() !== false) return 'already_submitted';
```

## Score Validation

```php
if (!is_int($score) || $score < 1 || $score > 5) {
    return $this->problem(422, 'validation-failed', 'score must be an integer 1-5.');
}
```

## Stats Aggregation

```sql
SELECT COUNT(*) AS cnt, AVG(score) AS avg FROM feedback WHERE target = :tgt
```

Return `null` average when count is zero to avoid `NaN` in JSON.

## HTTP Status Codes

| Situation | Status |
|-----------|--------|
| Feedback submitted | 201 |
| Stats / list | 200 |
| No X-User-Id | 400 |
| Empty target / bad score | 422 |
| No admin key | 403 |
| Duplicate feedback | 409 |
