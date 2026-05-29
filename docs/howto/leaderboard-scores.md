---
title: "How-to: Leaderboard & Score Tracking API"
category: product
tags: [leaderboard, scores, ranking, aggregation]
difficulty: intermediate
related: [leaderboard-ranking-api, leaderboard-ranking, game-score-leaderboard-api]
ft: FT206
---

# How-to: Leaderboard & Score Tracking API

This guide shows how to build a leaderboard system with score submission, top-N rankings using
best-per-user aggregation, and personal score history using NENE2.
Pattern demonstrated by the **leaderboardlog** field trial (FT206).

## Features

- Score submission per user per game (`X-User-Id` header)
- Top-N leaderboard: best score per user ranked descending (`MAX(score) GROUP BY user_id`)
- Personal score history for any user/game combination
- Personal best score query
- Configurable limit (clamped 1–100)

## Schema

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_scores_game ON scores (game, score DESC);
CREATE INDEX IF NOT EXISTS idx_scores_user ON scores (user_id, game);
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/scores` | User | Submit a score |
| `GET` | `/leaderboard?game=<game>` | Public | Top-N leaderboard |
| `GET` | `/scores/{userId}?game=<game>` | Public | User's score history |

## Score Submission

```php
$game  = trim((string) ($body['game'] ?? ''));
$score = $body['score'] ?? null;

if ($game === '' || strlen($game) > 64) {
    return $this->problem(422, 'validation-failed', 'game required (max 64 chars).');
}
if (!is_int($score) || $score < 0) {
    return $this->problem(422, 'validation-failed', 'score must be a non-negative integer.');
}
```

Key points:
- `is_int($score)` — strict check; rejects floats (`1.5`) and strings from JSON
- Game name capped at 64 chars — prevents oversized game name DoS
- Score non-negative — prevents negative score injection

Returns the updated personal best on 201:

```json
{ "message": "Score recorded.", "best_score": 9800 }
```

## Top-N Ranking Query

Best-per-user ranking with dense rank assignment in PHP:

```php
public function leaderboard(string $game, int $limit): array
{
    $stmt = $this->pdo->prepare(
        'SELECT user_id, MAX(score) AS best_score
         FROM scores
         WHERE game = :game
         GROUP BY user_id
         ORDER BY best_score DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':game', $game, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

- `MAX(score) GROUP BY user_id` — one row per user, their personal best
- `ORDER BY best_score DESC` — highest scorer first
- `PDO::PARAM_INT` binding for LIMIT — SQL injection safe

Example response:

```json
{
  "leaderboard": [
    { "user_id": 42, "best_score": 9800, "rank": 1 },
    { "user_id": 7,  "best_score": 7200, "rank": 2 }
  ]
}
```

## Limit Clamping

```php
$limit = ctype_digit($limitRaw) ? (int) $limitRaw : 10;
if ($limit < 1 || $limit > 100) {
    $limit = 10;
}
```

Invalid or out-of-range limits silently default to 10 — never trust client-supplied integers for LIMIT.

## Validation Patterns

| Input | Check | Reason |
|-------|-------|--------|
| `score` | `is_int($score) && $score >= 0` | Rejects floats, strings, negatives |
| `game` | `strlen($game) <= 64` | Prevents oversized input |
| `limit` | `ctype_digit()` + range clamp | ReDoS-safe, bounded |
| `userId` (path) | `ctype_digit()` + `> 0` | Validates before DB query |
