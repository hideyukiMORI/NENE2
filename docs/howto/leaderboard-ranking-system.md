# How to Build a Leaderboard (Ranking System) with NENE2

This guide walks through building a leaderboard where users submit scores, see rankings, and check their own rank. Only the best score per user per leaderboard is kept.

**Field Trial**: FT141  
**NENE2 version**: ^1.5  
**Covered topics**: best-score UPDATE pattern, rank calculation with COUNT(*), score ownership check, query parameter clamping, vulnerability assessment

---

## What we're building

- `POST /leaderboards` — create a leaderboard
- `POST /leaderboards/{id}/scores` — submit a score (kept only if it's a new personal best)
- `GET /leaderboards/{id}/rankings` — top N rankings (descending score, `?limit=N`)
- `GET /leaderboards/{id}/rankings/me` — caller's own rank and score
- `DELETE /leaderboards/{id}/scores/{userId}` — delete own score (owner only)

---

## Database schema

```sql
CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
```

`UNIQUE (leaderboard_id, user_id)` — one score row per user per leaderboard; updates replace it.

---

## Best-score UPDATE pattern

```php
public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
{
    $existing = $this->findScore($leaderboardId, $userId);

    if ($existing === null) {
        $this->executor->execute(
            'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
            [$leaderboardId, $userId, $score, $now],
        );
        return true;
    }

    if ($score > $existing['score']) {
        $this->executor->execute(
            'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
            [$score, $now, $leaderboardId, $userId],
        );
        return true;
    }

    return false;  // Not a new personal best
}
```

Returns `true` when the score is a new personal best (useful for UI feedback), `false` when ignored.

---

## Rank calculation with COUNT(*)

Instead of a window function (`RANK()` is not available in all SQLite versions), count how many scores are higher:

```php
public function getUserRank(int $leaderboardId, int $userId): ?int
{
    $score = $this->findScore($leaderboardId, $userId);

    if ($score === null) {
        return null;
    }

    $row   = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
        [$leaderboardId, $score['score']],
    );
    $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

    return $ahead + 1;
}
```

If 0 users have a higher score, rank is 1. If 5 users are higher, rank is 6. Ties get the same rank.

---

## Score ownership check (IDOR prevention)

```php
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
}
```

Always check the caller's identity against the target user before DELETE. Without this check, any authenticated user could delete any score.

---

## Query parameter clamping

```php
$limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}
```

Cap the limit to prevent `?limit=99999` from scanning the entire table.

---

## Vulnerability assessment (FT141)

| ID | Attack | Expected | Result |
|----|--------|----------|--------|
| VULN-A | IDOR: delete another user's score | 403 | Pass |
| VULN-B | Submit score for another user | 200 (allowed) | Pass |
| VULN-C | SQL injection in leaderboard name | 201 (verbatim) | Pass |
| VULN-D | Missing X-User-Id on /rankings/me | 400 | Pass |
| VULN-E | Non-numeric X-User-Id | not 200 | Pass |
| VULN-F | Negative leaderboard ID | not 200 | Pass |
| VULN-G | PHP_INT_MAX as score | 200 (valid int) | Pass |
| VULN-H | Float score (type confusion) | 422 | Pass |
| VULN-I | String score (type confusion) | 422 | Pass |
| VULN-J | Missing X-User-Id on DELETE | 400 | Pass |
| VULN-K | user_id=0 in score submission | 422 | Pass |
| VULN-L | `?limit=99999` (large limit) | 200 + clamped | Pass |

All 12 vulnerability tests pass. No vulnerabilities found.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Storing all submitted scores instead of best | `findScore()` check before INSERT; UPDATE if higher |
| Using RANK() which may not exist in SQLite | `COUNT(*) WHERE score > ?` gives equivalent rank |
| IDOR on score DELETE | Check `$actorId !== $userId` → 403 |
| Unclamped limit parameter causes table scan | Clamp `limit` to 1–100 range |
| Float/string score bypasses `is_int()` | `!is_int($score)` rejects floats and strings in PHP 8 JSON decode |
