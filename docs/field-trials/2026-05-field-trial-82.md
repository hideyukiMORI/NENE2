# Field Trial 82 — Product Rating System (ratinglog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/ratinglog/`
**NENE2 version:** 1.5.23
**Theme:** Item ratings with upsert, aggregated summary, and per-rater CRUD

---

## What was built

A product rating API where users rate items (1–5 stars) with optional review text.
One rating per (item, rater) pair; re-submitting updates the existing rating.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| PUT | `/items/{itemId}/ratings/{raterId}` | Create or update a rating |
| GET | `/items/{itemId}/ratings` | List all ratings for an item |
| GET | `/items/{itemId}/ratings/summary` | Avg score, count, score distribution |
| GET | `/items/{itemId}/ratings/{raterId}` | Get a specific rating |
| DELETE | `/items/{itemId}/ratings/{raterId}` | Remove a rating |

### Schema

```sql
CREATE TABLE IF NOT EXISTS ratings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id     TEXT    NOT NULL,
    rater_id    TEXT    NOT NULL,
    score       INTEGER NOT NULL CHECK(score BETWEEN 1 AND 5),
    review      TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

---

## Frictions found

### 1. `DatabaseConstraintException` required for clean upsert pattern

**Severity:** Medium (high before v1.5.23)

The upsert pattern — try INSERT, catch UNIQUE violation, UPDATE instead — requires a way to distinguish constraint violations from other DB errors.

Before v1.5.23, `PdoDatabaseQueryExecutor` wrapped all `PDOException`s in `DatabaseConnectionException`, so callers had to dig into `getPrevious()` to inspect the raw `PDOException` message:

```php
// Workaround needed before v1.5.23
} catch (DatabaseConnectionException $e) {
    $prev = $e->getPrevious();
    if ($prev instanceof \PDOException && str_contains($prev->getMessage(), 'UNIQUE constraint failed')) {
        // update instead
    }
    throw $e;
}
```

This is fragile (message string matching), breaks type safety, and leaks the PDO layer into domain code.

**Fix applied in v1.5.23:** `DatabaseConstraintException extends DatabaseConnectionException` — thrown automatically by the executor when SQLSTATE starts with `23` or SQLite message strings match. The upsert pattern becomes:

```php
try {
    $id = $this->db->insert('INSERT INTO ratings (item_id, rater_id, score, ...) VALUES (?, ?, ?, ...)', [...]);
} catch (DatabaseConstraintException) {
    // rater already rated this item — update instead
    $this->db->execute('UPDATE ratings SET score = ?, review = ?, updated_at = ? WHERE item_id = ? AND rater_id = ?', [...]);
    ...
}
```

Clean, type-safe, no message string inspection. The extension of `DatabaseConnectionException` ensures existing `catch (DatabaseConnectionException)` blocks remain unaffected.

---

### 2. Route ordering: static suffix vs parameterized segment

**Severity:** Low (resolved by framework auto-sort)

The summary endpoint shares a prefix with the per-rater endpoint:

```
GET /items/{itemId}/ratings/summary   → 1 dynamic segment ({itemId})
GET /items/{itemId}/ratings/{raterId} → 2 dynamic segments
```

NENE2's auto-sort (routes with fewer parameters match first) ensures `summary` always wins without manual ordering. This worked correctly out of the box.

---

### 3. CHECK constraint in schema not tested directly

**Severity:** Low

The schema enforces `CHECK(score BETWEEN 1 AND 5)` at the DB level, but the application validates the range before hitting the DB (returning 422). The CHECK constraint is a safety net that never fires in normal operation.

If a CHECK constraint *were* to fire (e.g., validation bypassed), it would now be caught as `DatabaseConstraintException` rather than a generic `DatabaseConnectionException`, which is an improvement for observability.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 14 tests, 29 assertions — OK |
| PHPStan level 8 | No errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- Copied composer.lock from `leaderboardlog/` to bootstrap; ran `composer update hideyukimori/nene2` to get v1.5.23.
- `upsert()` uses `DatabaseConstraintException` (new in v1.5.23) — this FT served as the first consumer validation of that API.
- Summary aggregation uses a single SQL query (`GROUP BY score`) to build the distribution map, with a loop to fill missing score levels (1–5) with zero counts.
