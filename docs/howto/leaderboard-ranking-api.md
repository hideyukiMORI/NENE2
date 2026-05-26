# How-to: Leaderboard API with Ranking and Bulk Score Submission

> **FT reference**: FT259 (`NENE2-FT/scorelog`) — Leaderboard API with `RANK() OVER` window function, per-player best score aggregation, and bulk submission

Demonstrates a game score API with a leaderboard endpoint. Each player may have multiple
score entries per game; the leaderboard shows only the best score per player, ranked with
`RANK() OVER (ORDER BY MAX(score) DESC)`. Bulk submission accepts up to 100 entries in a
single request, with all-or-nothing validation (any invalid entry rejects the whole batch).

---

## Routes

| Method   | Path                   | Description                                           |
|----------|------------------------|-------------------------------------------------------|
| `POST`   | `/scores`              | Submit a single score                                 |
| `POST`   | `/scores/bulk`         | Bulk submit up to 100 scores                          |
| `GET`    | `/scores/leaderboard`  | Get top-N players for a game (ranked)                 |
| `GET`    | `/scores`              | List scores (filterable by game/player, paginated)    |
| `GET`    | `/scores/{id}`         | Get a single score entry                              |
| `DELETE` | `/scores/{id}`         | Delete a score entry                                  |

> **Route order**: `/scores/bulk` and `/scores/leaderboard` must be registered before `/scores/{id}`
> so the literal segments are not captured as path parameters.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT NOT NULL,
    game       TEXT NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_scores_game      ON scores (game);
CREATE INDEX IF NOT EXISTS idx_scores_player    ON scores (player);
CREATE INDEX IF NOT EXISTS idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` is a DB-level safety net for non-negative scores. Multiple rows per
`(player, game)` pair are allowed — each play session is a separate entry. The leaderboard
aggregates them with `MAX(score)`.

Separate indexes on `game`, `player`, and `played_at` support the most common filter patterns
without a compound index.

---

## Leaderboard: `RANK() OVER` window function

```php
public function leaderboard(string $game, int $topN): array
{
    $rows = $this->executor->fetchAll(
        'SELECT player, MAX(score) AS best_score, COUNT(*) AS play_count,
                RANK() OVER (ORDER BY MAX(score) DESC) AS rank
           FROM scores
          WHERE game = ?
          GROUP BY player
          ORDER BY best_score DESC
          LIMIT ?',
        [$game, $topN],
    );

    return array_map(fn (array $row) => new LeaderboardEntry(
        rank:      (int) $row['rank'],
        player:    (string) $row['player'],
        bestScore: (int) $row['best_score'],
        playCount: (int) $row['play_count'],
    ), $rows);
}
```

**Key techniques**:

- `GROUP BY player`: one row per player.
- `MAX(score) AS best_score`: each player's personal best across all their entries.
- `COUNT(*) AS play_count`: total games played (all entries, not just the best).
- `RANK() OVER (ORDER BY MAX(score) DESC)`: SQL window function for ranking. Players with
  equal best scores receive the same rank (e.g., two players at 1000 both get rank 1; the
  next player gets rank 3, not 2). This is `RANK()`, not `ROW_NUMBER()`.
- `ORDER BY best_score DESC LIMIT ?`: sort and paginate the final result set.

**`RANK()` vs `ROW_NUMBER()` vs `DENSE_RANK()`**:

| Function | Tie behaviour | Ranks after a tie |
|---|---|---|
| `ROW_NUMBER()` | Arbitrary order within ties | Sequential (1, 2, 3, 4) |
| `RANK()` | Same rank for ties | Gap after tie (1, 1, 3, 4) |
| `DENSE_RANK()` | Same rank for ties | No gap (1, 1, 2, 3) |

`RANK()` is conventional for leaderboards: shared first place means rank 2 is skipped.

---

## Bulk submission: all-or-nothing validation

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // Top-level structural validation
    if (!isset($body['scores']) || !is_array($body['scores'])) {
        throw new ValidationException([...]);
    }
    if (count($body['scores']) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    $allErrors = [];
    $entries   = [];

    foreach ($body['scores'] as $i => $entry) {
        $entryErrors = $this->validateScoreEntry($entry, "scores[{$i}].");
        if ($entryErrors !== []) {
            $allErrors = [...$allErrors, ...$entryErrors];
        } else {
            $entries[] = [/* normalized entry */];
        }
    }

    if ($allErrors !== []) {
        throw new ValidationException($allErrors);   // reject the whole batch
    }

    $scores = $this->repository->bulkSubmit($entries, $now);
    return $this->json->create(['created' => count($scores), 'scores' => ...], 201);
}
```

**All-or-nothing vs partial-success**: Unlike the bulk-create pattern in
[`bulk-operations-partial-success.md`](bulk-operations-partial-success.md), this bulk submit
rejects the entire batch if any entry is invalid. This is appropriate here because:
- Score submissions are typically from a single game session — partial writes would be inconsistent.
- The caller should fix their data and resubmit, not guess which entries made it.

**Per-entry field prefix**: `validateScoreEntry($entry, "scores[{$i}].")` adds `scores[0].player`,
`scores[0].game`, etc. to error field paths. Clients can map each error back to the specific
element and field that failed.

---

## Per-entry validation with shared helper

```php
private function validateScoreEntry(array $body, string $prefix = ''): array
{
    $errors = [];

    if (!isset($body['player']) || !is_string($body['player']) || $body['player'] === '') {
        $errors[] = new ValidationError($prefix . 'player', 'player is required.', 'required');
    }
    if (!isset($body['game']) || !is_string($body['game']) || $body['game'] === '') {
        $errors[] = new ValidationError($prefix . 'game', 'game is required.', 'required');
    }
    if (!isset($body['score']) || !is_int($body['score'])) {
        $errors[] = new ValidationError($prefix . 'score', 'score is required (integer).', 'required');
    } elseif ($body['score'] < 0) {
        $errors[] = new ValidationError($prefix . 'score', 'score must be 0 or greater.', 'out_of_range');
    }
    if (!isset($body['played_at']) || !is_string($body['played_at']) || $body['played_at'] === '') {
        $errors[] = new ValidationError($prefix . 'played_at', 'played_at is required (YYYY-MM-DD).', 'required');
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['played_at']) !== 1) {
        $errors[] = new ValidationError($prefix . 'played_at', 'played_at must be in YYYY-MM-DD format.', 'invalid_format');
    }

    return $errors;
}
```

The same `validateScoreEntry()` is used for single (`POST /scores`) and bulk (`POST /scores/bulk`)
submission. The `$prefix` parameter namespaces error field paths for bulk entries.

`played_at` accepts `YYYY-MM-DD` (ISO date) — the time of the play session, separate from
`created_at` (when the score was recorded).

---

## Dynamic query building for filtering

```php
private function buildBaseQuery(?string $game, ?string $player): array
{
    $sql    = 'SELECT id, player, game, score, played_at, created_at FROM scores WHERE 1=1';
    $params = [];

    if ($game !== null) {
        $sql     .= ' AND game = ?';
        $params[] = $game;
    }
    if ($player !== null) {
        $sql     .= ' AND player = ?';
        $params[] = $player;
    }

    return [$sql, $params];
}
```

`WHERE 1=1` is a no-op that allows clean appending of optional `AND` clauses without
conditional logic for the first clause. The same base query is used for both `findAll()`
(with `ORDER BY` and `LIMIT`/`OFFSET`) and `count()` (wrapped in `SELECT COUNT(*) FROM (...) sub`).

---

## Custom exception + handler pattern

```php
class ScoreNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Score {$id} not found.");
    }
}

class ScoreNotFoundExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        \Throwable $e,
        ServerRequestInterface $request,
        RequestHandlerInterface $next,
    ): ResponseInterface {
        if (!$e instanceof ScoreNotFoundException) {
            return $next->handle($request);  // pass to next handler
        }
        return $this->problems->create($request, 'not-found', $e->getMessage(), 404, '');
    }
}
```

The repository throws `ScoreNotFoundException` rather than returning `null`. An exception handler
in the middleware stack converts it to a 404 Problem Details response. This approach:
- Eliminates null checks in the controller (`findById` returns `Score`, never `null`).
- Centralises HTTP status mapping outside the controller.
- Keeps the repository's return type non-nullable, which is easier for static analysis.

**Trade-off**: `null`-return style is simpler for one-off endpoints. Exception-handler style
scales better when many endpoints share the same not-found mapping.

---

## Pagination

```php
$pagination = PaginationQueryParser::parse($request);  // parses ?limit=20&offset=0

$items = $this->repository->findAll($game, $player, $pagination->limit, $pagination->offset);
$total = $this->repository->count($game, $player);

return $this->json->create(
    (new PaginationResponse(
        items:  array_map($this->serializeScore(...), $items),
        limit:  $pagination->limit,
        offset: $pagination->offset,
        total:  $total,
    ))->toArray(),
);
```

`PaginationQueryParser` and `PaginationResponse` are NENE2 framework helpers. They parse
`?limit` and `?offset` query parameters and format the standard pagination envelope:

```json
{
  "items": [...],
  "limit": 20,
  "offset": 0,
  "total": 147
}
```

---

## Example: leaderboard response

**Request**: `GET /scores/leaderboard?game=tetris&top=3`

```json
{
  "game": "tetris",
  "top": 3,
  "entries": [
    {"rank": 1, "player": "alice", "best_score": 98500, "play_count": 12},
    {"rank": 2, "player": "bob",   "best_score": 87200, "play_count": 8},
    {"rank": 2, "player": "carol", "best_score": 87200, "play_count": 5}
  ]
}
```

Bob and Carol share rank 2 (equal best scores). The next rank would be 4.

---

## Related howtos

- [`bulk-operations-partial-success.md`](bulk-operations-partial-success.md) — partial-success bulk semantics (207 Multi-Status)
- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — dynamic filtering with AND/OR modes
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — full-text search as an alternative to exact match filtering
