# Field Trial 76 — Leaderboard with Ranking and Percentile (leaderboardlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/leaderboardlog/`
**Theme**: Score leaderboard with per-board isolation, upsert pattern, dense ranking (tied scores share same rank), and user rank lookup.

---

## What was built

A leaderboard API where users submit scores to named boards. Each user has at most one score per board (upsert). Rankings use dense ranking — tied scores share the same rank number, and the next rank skips accordingly.

### Domain

- `Score` — `board`, `userId`, `score`, `metadata` (`array<string, mixed>`), `createdAt`, `rank`
- Rank computed via correlated subquery: `COUNT(*) + 1 WHERE score > user's score`
- Same score → same rank (dense ranking); scores below a tie group get rank = tied_count + 1

### Schema

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    board      TEXT NOT NULL DEFAULT 'global',
    user_id    TEXT NOT NULL,
    score      INTEGER NOT NULL,
    metadata   TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    UNIQUE(board, user_id)
);
```

`UNIQUE(board, user_id)` enforces one score per user per board; upsert is implemented with `INSERT ... ON CONFLICT DO UPDATE SET`.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| PUT | `/boards/{board}/scores/{userId}` | Upsert user score on a board |
| GET | `/boards/{board}/scores` | Ranked list for a board (optional `?limit=N`, default 10, max 100) |
| GET | `/boards/{board}/scores/{userId}` | Get a user's score with their current rank |

### Key design decisions

**Upsert with `ON CONFLICT DO UPDATE`**: SQLite's upsert syntax replaces a dedicated "update or insert" branch in application code. `created_at` is preserved on update (only `score` and `metadata` are overwritten).

**Dense ranking via correlated subquery**: The rank is computed as `(SELECT COUNT(*) FROM scores s2 WHERE s2.board = s.board AND s2.score > s.score) + 1`. This is a standard dense ranking: every user with the same score gets the same rank, and the next rank after a tie group equals (number of users with higher score) + 1.

**Tie-breaking in sorted output**: `ORDER BY score DESC, user_id ASC` gives a deterministic ordering within a rank tier. The rank field itself is the same for all tied users.

**Board isolation**: All queries are scoped to `board`. Different boards are fully independent even for the same `user_id`.

**Metadata as JSON in TEXT column**: `json_encode()` on write, `json_decode()` on hydrate — same pattern as prior FTs (auditlog, deadletterlog).

**`limit` cap at 100**: Query param clamped: `max(1, min(100, $limit))`. Default 10.

### Test results

```
OK (12 tests, 32 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.22 handled all patterns cleanly:

- Two path parameters (`{board}/{userId}`): ✅
- `PUT` method routing: ✅
- `?limit=N` query param: ✅ (read via `$request->getQueryParams()`)
- SQLite `ON CONFLICT DO UPDATE` upsert: ✅ (framework-agnostic — raw SQL)
- Correlated subquery for rank: ✅ (framework-agnostic — raw SQL)
- JSON metadata in TEXT column: ✅

---

## Summary

Leaderboard with dense ranking works cleanly with NENE2 v1.5.22. The rank computation (correlated subquery) and upsert (`ON CONFLICT DO UPDATE`) are both raw SQL idioms that the framework does not interfere with. No framework changes needed.
