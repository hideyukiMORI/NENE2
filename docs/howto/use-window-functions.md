---
title: "Use SQLite Window Functions"
category: database
tags: [window-functions, sqlite, ranking, running-total, lag-lead]
difficulty: intermediate
related: [use-transactions, leaderboard-ranking, add-database-endpoint]
---

# Use SQLite Window Functions

Window functions compute a value across a set of rows *related to the current row* without collapsing them into a single group (the way `GROUP BY` does). They are the right tool for **ranking**, **running totals**, and **period-over-period comparison** — three patterns that are awkward and slow to do in PHP after the fact.

SQLite supports window functions since **3.25.0** (2018). NENE2 ships with PHP's bundled SQLite, which is well past that; MySQL 8.0+ and PostgreSQL also support the same syntax, so these queries port across the three adapters NENE2 targets.

You run them through `DatabaseQueryExecutorInterface::fetchAll()` like any other read query — there is no special framework support to wire up.

**Prerequisite**: You have a repository backed by `DatabaseQueryExecutorInterface`. See [Add a database-backed endpoint](add-database-endpoint.md).

---

## 1. The anatomy of a window

```sql
ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC)
```

- `PARTITION BY game` — restart the window for each game (omit to treat all rows as one window).
- `ORDER BY points DESC` — order *within* the partition; this defines what "first" and "previous" mean.

When several columns reuse the same window, name it once with a `WINDOW` clause:

```sql
SELECT player, game, points,
       ROW_NUMBER()  OVER w AS rn,
       RANK()        OVER w AS rnk,
       DENSE_RANK()  OVER w AS drnk
FROM scores
WINDOW w AS (PARTITION BY game ORDER BY points DESC)
ORDER BY game, points DESC;
```

---

## 2. Ranking: `ROW_NUMBER` vs `RANK` vs `DENSE_RANK`

The three differ only in how they treat ties. Given two players tied at 150 in `chess`:

| player | game  | points | `ROW_NUMBER` | `RANK` | `DENSE_RANK` |
|--------|-------|--------|--------------|--------|--------------|
| b      | chess | 150    | 1            | 1      | 1            |
| c      | chess | 150    | 2            | 1      | 1            |
| a      | chess | 100    | 3            | 3      | 2            |

- **`ROW_NUMBER`** — always unique (1, 2, 3). Use it for stable pagination cursors or "pick exactly one per group".
- **`RANK`** — ties share a rank, then it skips (1, 1, 3). Use it for leaderboards where "joint 1st" is meaningful.
- **`DENSE_RANK`** — ties share a rank, no gap (1, 1, 2). Use it for "tier" / grade buckets.

> Pick the rank function deliberately. A leaderboard that shows two "rank 2" players and no "rank 1" is almost always a `RANK`/`ROW_NUMBER` mix-up.

In a repository method:

```php
/**
 * @return list<array{player: string, game: string, points: int, rank: int}>
 */
public function topRankedByGame(string $game): array
{
    return $this->executor->fetchAll(
        'SELECT player, game, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores
         WHERE game = :game
         ORDER BY points DESC',
        ['game' => $game],
    );
}
```

---

## 3. Running total: an aggregate as a window

Any aggregate (`SUM`, `AVG`, `COUNT`, …) becomes a *running* aggregate when given an `OVER (...)` clause and a frame:

```sql
SELECT created_at, points,
       SUM(points) OVER (ORDER BY created_at ROWS UNBOUNDED PRECEDING) AS running_total
FROM scores
ORDER BY created_at;
```

| created_at | points | running_total |
|------------|--------|---------------|
| 2026-01-01 | 100    | 100           |
| 2026-01-02 | 150    | 250           |
| 2026-01-03 | 150    | 400           |
| 2026-01-04 | 90     | 490           |

`ROWS UNBOUNDED PRECEDING` means "every row from the start of the partition up to the current row". Without an explicit frame, the default (`RANGE UNBOUNDED PRECEDING`) sums **all rows that tie on the `ORDER BY` value** into the same step — a subtle source of wrong totals when timestamps collide. Be explicit with `ROWS` when you want a true row-by-row running total.

---

## 4. Period-over-period: `LAG` and `LEAD`

`LAG` reads a column from the *previous* row in the window; `LEAD` reads the *next* one. This computes a delta without a self-join:

```sql
SELECT created_at, points,
       points - LAG(points) OVER (ORDER BY created_at) AS delta
FROM scores
ORDER BY created_at;
```

| created_at | points | delta |
|------------|--------|-------|
| 2026-01-01 | 100    | *null* |
| 2026-01-02 | 150    | 50    |
| 2026-01-03 | 150    | 0     |
| 2026-01-04 | 90     | −60   |

The first row's `delta` is `NULL` because there is no previous row. Supply a default to avoid null-handling downstream: `LAG(points, 1, 0)` returns `0` instead of `NULL` for the first row. Map `NULL` to a typed value in your DTO rather than leaking it into the JSON response.

---

## 5. Filtering on a window result

You **cannot** put a window function in a `WHERE` clause — windows are evaluated *after* `WHERE`. Wrap the query in a subquery (or CTE) and filter on the alias:

```sql
WITH ranked AS (
    SELECT player, game, points,
           ROW_NUMBER() OVER (PARTITION BY game ORDER BY points DESC) AS rn
    FROM scores
)
SELECT player, game, points
FROM ranked
WHERE rn <= 3            -- top 3 per game
ORDER BY game, points DESC;
```

This "top-N-per-group" shape is the most common real-world use; reach for it instead of `N` separate `LIMIT` queries.

---

## 6. Returning it as a typed response

Keep the SQL in the repository and map to a readonly DTO before it reaches the controller — do not pass the raw `array` across the boundary:

```php
final readonly class GameRanking
{
    public function __construct(
        public int $rank,
        public string $player,
        public int $points,
    ) {}
}
```

```php
/** @return list<GameRanking> */
public function topRankedByGame(string $game): array
{
    $rows = $this->executor->fetchAll(
        'SELECT player, points,
                RANK() OVER (PARTITION BY game ORDER BY points DESC) AS rank
         FROM scores WHERE game = :game ORDER BY points DESC',
        ['game' => $game],
    );

    return array_map(
        static fn (array $r): GameRanking => new GameRanking(
            rank: (int) $r['rank'],
            player: (string) $r['player'],
            points: (int) $r['points'],
        ),
        $rows,
    );
}
```

SQLite returns all column values as strings over PDO, so cast (`(int)`, `(float)`) inside the mapper — the window function result (`rank`, `running_total`) is no exception.

---

## Gotchas

- **`WHERE` cannot see window aliases** — filter in an outer query/CTE (§5).
- **Default frame is `RANGE`, not `ROWS`** — be explicit with `ROWS UNBOUNDED PRECEDING` for running totals (§3).
- **`LAG`/`LEAD` return `NULL` at the edges** — pass a default or map to a typed value (§4).
- **Portability** — the syntax above is standard and runs on SQLite 3.25+, MySQL 8.0+, and PostgreSQL. If you target older MySQL (5.7), window functions are unavailable; fall back to a self-join or compute in PHP.
- **Index the `ORDER BY` columns** — a window's `PARTITION BY` / `ORDER BY` benefits from the same indexes as a regular sort.

---

## Related

- [Use database transactions](use-transactions.md) — atomic multi-step writes
- [Leaderboard ranking](leaderboard-ranking.md) — a product recipe built on ranking
- [Add a database-backed endpoint](add-database-endpoint.md) — repository + executor wiring
