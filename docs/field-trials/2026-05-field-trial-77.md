# Field Trial 77 — Poll/Voting API with Aggregation and State Machine (polllog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/polllog/`
**Theme**: Poll/voting API with multi-option creation, one-vote-per-user enforcement, vote count aggregation, and open/closed state machine.

---

## What was built

A poll API where polls are created with multiple options. Each voter can vote exactly once per poll (enforced by `UNIQUE(poll_id, voter_id)`). Vote counts are aggregated per option. Polls can be closed, after which voting is rejected.

### Domain

- `Poll` — `title`, `question`, `status` (open/closed), `createdBy`, `closedAt`, `options`, `totalVotes`
- `PollOption` — `text`, `votes` (COUNT of votes for that option)
- `PollStatus` — string-backed enum: `open`, `closed`
- `total_votes` computed in `toArray()` as `array_sum()` of option vote counts

### Schema

```sql
CREATE TABLE IF NOT EXISTS polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL, question TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_by TEXT NOT NULL, created_at TEXT NOT NULL, closed_at TEXT
);
CREATE TABLE IF NOT EXISTS poll_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL REFERENCES polls(id),
    text TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS poll_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL REFERENCES polls(id),
    option_id INTEGER NOT NULL REFERENCES poll_options(id),
    voter_id TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE(poll_id, voter_id)
);
```

`UNIQUE(poll_id, voter_id)` enforces one vote per voter per poll at the DB level.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/polls` | Create poll with options (min 2 options required) |
| GET | `/polls` | List polls (optional `?status=open\|closed`) |
| GET | `/polls/{id}` | Get poll with vote counts per option |
| POST | `/polls/{id}/vote` | Vote (body: `option_id`, `voter_id`) |
| POST | `/polls/{id}/close` | Close poll (no further voting allowed) |

### Key design decisions

**Multi-row creation in one operation**: `create()` inserts the poll, then iterates options with individual `INSERT` statements. SQLite auto-increments `id`; `last_insert_rowid()` retrieves the poll ID.

**Duplicate vote via UNIQUE constraint**: Rather than a pre-check SELECT (which would be a TOCTOU race), the UNIQUE constraint is the guard. A caught `DatabaseConnectionException` with a `PDOException` cause containing "UNIQUE constraint failed" maps to a 409 response.

**Vote count aggregation**: `fetchOptions()` uses `LEFT JOIN poll_votes ON v.option_id = o.id ... COUNT(v.id) AS votes GROUP BY o.id` — single query per poll load; no N+1.

**State machine close is idempotent-via-404**: `close()` returns null when the poll is already closed, mapping to 404. Alternative would be 409, but 404 ("not found as an open poll") is cleaner for callers.

**`PollStatus::tryFrom()` for filter param**: `?status=open|closed` uses `PollStatus::tryFrom($statusRaw)` — unknown values produce `null` (no filter), which is the correct graceful handling.

### Test results

```
OK (17 tests, 34 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

### F-1 (Low) — `DatabaseQueryExecutorInterface::execute()` wraps `PDOException` in `DatabaseConnectionException`

**What happened**: Catching `\PDOException` in `SqlitePollRepository::vote()` to detect UNIQUE constraint violations silently failed — the exception was never caught, resulting in a 500 instead of 409.

**Root cause**: `PdoDatabaseQueryExecutor::statement()` wraps every `PDOException` in `Nene2\Database\DatabaseConnectionException`. User code cannot catch the original `PDOException` from calls to `execute()`.

**Workaround used**:
```php
} catch (DatabaseConnectionException $e) {
    $prev = $e->getPrevious();
    if ($prev instanceof \PDOException && str_contains($prev->getMessage(), 'UNIQUE constraint failed')) {
        return false;
    }
    throw $e;
}
```

**Impact**: Any user code that wants to handle specific DB errors (UNIQUE constraint, FK violation) must know to catch `DatabaseConnectionException` and inspect `getPrevious()`. This is non-obvious and not documented.

**Suggested fix**: Either (a) expose a dedicated method `executeAndCatch(string $sql, array $params): bool` for upsert/conflict scenarios, or (b) define and document when `DatabaseConnectionException` is thrown and how to detect constraint violations (howto or ADR note). Alternatively, add a specific `DatabaseConstraintException` for UNIQUE/FK violations.

---

## Summary

Poll/voting API with aggregation and state machine works with NENE2 v1.5.22. One friction found: catching UNIQUE constraint violations requires catching `DatabaseConnectionException` and inspecting the previous `PDOException`, not catching `PDOException` directly. This is undocumented and counter-intuitive.
