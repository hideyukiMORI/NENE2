---
title: "How-to: Game Score & Leaderboard API"
category: product
tags: [leaderboard, scores, ranking, bulk-insert, pagination]
difficulty: intermediate
related: [leaderboard-ranking-api, leaderboard-scores, leaderboard-ranking]
ft: FT259
---

# How-to: Game Score & Leaderboard API

> **FT reference**: FT259 (`NENE2-FT/scorelog`) — Game score submission with bulk insert (max 100, all-or-nothing), per-player leaderboard with `best_score` aggregation and `play_count`, negative score prevention, pagination, 20 tests PASS.

This guide shows how to build a game scoring system: record individual play scores, bulk-import results, and compute ranked leaderboards per game.

## Schema

```sql
CREATE TABLE scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT    NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT    NOT NULL,      -- ISO 8601 date: YYYY-MM-DD
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_scores_game      ON scores (game);
CREATE INDEX idx_scores_player    ON scores (player);
CREATE INDEX idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` prevents negative scores at the DB level. Indexes on `game` and `player` support filtered list and leaderboard queries.

## Endpoints

| Method | Path                | Description                          |
|--------|---------------------|--------------------------------------|
| `POST` | `/scores`           | Submit a single score                |
| `POST` | `/scores/bulk`      | Bulk submit up to 100 scores         |
| `GET`  | `/scores`           | List scores (filter + paginate)      |
| `GET`  | `/scores/leaderboard` | Per-player leaderboard for a game  |
| `GET`  | `/scores/{id}`      | Get single score                     |
| `DELETE` | `/scores/{id}`    | Delete score                         |

## Submit a Score

```php
POST /scores
{
  "player":    "Alice",
  "game":      "tetris",
  "score":     1500,
  "played_at": "2026-01-15"
}

→ 201
{
  "id": 1,
  "player": "Alice",
  "game": "tetris",
  "score": 1500,
  "played_at": "2026-01-15",
  "created_at": "..."
}
```

Multiple scores per player per game are allowed — each play is a separate record.

### Validation

```php
POST /scores  {"game": "tetris", "score": 100, "played_at": "2026-01-15"}
→ 422  // player is required

POST /scores  {"player": "Alice", "game": "tetris", "score": -1, "played_at": "2026-01-15"}
→ 422  // score must be >= 0

POST /scores  {"player": "Alice", "game": "tetris", "score": 100, "played_at": "15/01/2026"}
→ 422  // played_at must be YYYY-MM-DD format

POST /scores  {"player": "Alice", "game": "tetris", "score": 0, "played_at": "2026-01-15"}
→ 201  // score = 0 is valid
```

## List Scores

```php
// All scores
GET /scores
→ 200  {"items": [...], "total": 10}

// Filter by game
GET /scores?game=tetris
→ 200  {"items": [/* only tetris scores */], "total": 3}

// Filter by player
GET /scores?player=Alice
→ 200  {"items": [/* only Alice's scores */], "total": 2}

// Pagination
GET /scores?limit=2&offset=1
→ 200  {"items": [/* 2 items, starting from index 1 */], "total": 5}
```

## Bulk Submit

```php
POST /scores/bulk
{
  "scores": [
    {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
    {"player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16"},
    {"player": "Carol", "game": "snake",  "score": 500,  "played_at": "2026-01-15"}
  ]
}

→ 201
{
  "created": 3,
  "scores": [
    {"id": 1, "player": "Alice", ...},
    {"id": 2, "player": "Bob",   ...},
    {"id": 3, "player": "Carol", ...}
  ]
}
```

### Bulk Validation Rules

```php
// Empty array
POST /scores/bulk  {"scores": []}
→ 422  // at least 1 entry required

// Any invalid entry fails the whole batch
POST /scores/bulk
{"scores": [
  {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
  {"player": "",      "game": "tetris", "score": 500,  "played_at": "2026-01-15"}
]}
→ 422  // "player" cannot be empty — no records are inserted

// Over 100 entries
POST /scores/bulk  {"scores": [...101 entries...]}
→ 422  // max 100 entries per bulk request
```

**All-or-nothing**: validate all entries before inserting any. Use a DB transaction to ensure atomicity.

### Bulk Implementation

```php
public function bulkSubmit(array $entries): array
{
    // Validate all entries first
    foreach ($entries as $i => $entry) {
        $this->validate($entry, "scores[{$i}]");
    }

    // Insert all in a transaction
    $this->db->beginTransaction();
    try {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $this->repo->insert($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
        }
        $this->db->commit();
        return $this->repo->findByIds($ids);
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

## Leaderboard

```php
GET /scores/leaderboard?game=tetris

→ 200
{
  "game":    "tetris",
  "top":     10,
  "entries": [
    {"rank": 1, "player": "Alice", "best_score": 3000, "play_count": 2},
    {"rank": 2, "player": "Bob",   "best_score": 2000, "play_count": 1},
    {"rank": 3, "player": "Carol", "best_score": 500,  "play_count": 1}
  ]
}
```

Each player appears **once** — with their **best** (highest) score across all plays, plus a `play_count`.

### Top-N Limit

```php
GET /scores/leaderboard?game=tetris&top=3
→ 200  {"entries": [...3 players...], "top": 3}

GET /scores/leaderboard?game=tetris&top=0
→ 422  // top must be >= 1

GET /scores/leaderboard          // missing game
→ 422
```

### Leaderboard SQL

```sql
SELECT
    player,
    MAX(score)   AS best_score,
    COUNT(*)     AS play_count,
    RANK() OVER (ORDER BY MAX(score) DESC) AS rank
FROM scores
WHERE game = ?
GROUP BY player
ORDER BY best_score DESC
LIMIT ?
```

`RANK() OVER (ORDER BY MAX(score) DESC)` assigns the same rank to tied players (gaps in subsequent ranks). If you prefer no gaps, use `DENSE_RANK()`.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow negative scores at application layer only | `CHECK(score >= 0)` DB constraint is the final guard; application validation can be bypassed |
| Insert bulk entries one by one without transaction | Partial failure leaves half the batch in DB; impossible to distinguish committed from uncommitted |
| Validate bulk entries inside the insert loop | First N entries are inserted before validation fails; partial data in DB |
| Use `score = MAX(score)` without GROUP BY | Aggregates entire table without player grouping; wrong leaderboard results |
| Return all players in leaderboard without LIMIT | Full table scan and sort with no cap; DoS risk for large score tables |
| Compute `best_score` by fetching all scores in PHP | O(N) per player; use SQL `MAX()` aggregation |
