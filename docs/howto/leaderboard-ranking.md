# How-to: Game Leaderboard & Ranking API

This guide demonstrates building a leaderboard API where players submit scores per game, and the system tracks personal bests and produces ranked leaderboards.

## Pattern Overview

- Players submit scores via `POST /scores` (identified by `X-User-Id` header).
- Each submission is stored; the personal best (highest score ever) is returned.
- `GET /leaderboard?game=<name>` returns top-N players ranked by their personal best.
- `GET /scores/{userId}?game=<name>` lists all raw scores for a specific player.
- Games are fully isolated — scores in "tetris" never affect "snake".

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
```

All attempts are stored (no upsert), which allows per-game score history. The personal best is derived with `MAX(score)` at query time.

## Score Submission

```php
public function submit(int $userId, string $game, int $score): void
{
    $this->pdo->prepare(
        'INSERT INTO scores (user_id, game, score, created_at) VALUES (:uid, :game, :score, :now)'
    )->execute([':uid' => $userId, ':game' => $game, ':score' => $score, ':now' => $this->now()]);
}

public function bestScore(int $userId, string $game): ?int
{
    $stmt = $this->pdo->prepare(
        'SELECT MAX(score) FROM scores WHERE user_id = :uid AND game = :game'
    );
    $stmt->execute([':uid' => $userId, ':game' => $game]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (int) $val : null;
}
```

The handler returns the personal best alongside the confirmation:

```json
{ "message": "Score recorded.", "best_score": 2000 }
```

## Leaderboard Query

Best score per user, ordered descending, with rank added in PHP:

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

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

Response:

```json
{
  "leaderboard": [
    { "user_id": 2, "best_score": 1000, "rank": 1 },
    { "user_id": 1, "best_score": 500,  "rank": 2 }
  ]
}
```

## Validation Rules

| Field | Rule |
|---|---|
| `X-User-Id` header | Required for POST; `ctype_digit`, 1–18 chars, value > 0 |
| `game` body/query | Required, non-empty, max 64 chars |
| `score` body | Integer ≥ 0 only (`is_int($score) && $score >= 0`) |
| `userId` path param | `ctype_digit`, max 18 chars, value > 0; else 404 |
| `limit` query | 1–100, default 10; invalid values silently clamped |

String scores (`"100"`) are rejected with 422 because `is_int()` returns false for strings even when the value is numeric.

Zero is a valid score — useful for games where a player may not score at all.

## Routes

```
POST   /scores              Submit a score (X-User-Id required)
GET    /leaderboard         Top-N ranked players (?game= required, ?limit= optional)
GET    /scores/{userId}     All scores for a player (?game= required)
```

## Game Isolation

The `game` column acts as a namespace. Always include `WHERE game = :game` in every query. A player who scores in "tetris" will never appear on the "snake" leaderboard.

## See Also

- FT206 source: `../NENE2-FT/leaderboardlog/`
- Related: `docs/howto/rate-limiting.md` (FT200), `docs/howto/coupon-redemption.md` (FT204)
