# Field Trial 28 — Leaderboard API (scorelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/scorelog/`
**NENE2 version**: 1.5.11
**Theme**: Bulk create endpoint, window function ranking (`RANK() OVER`), indexed validation errors for bulk payloads

## What was built

A game score/leaderboard API with:

- `GET /scores` — list with `?game=` and `?player=` filters, pagination
- `POST /scores` — submit a single score (player, game, score integer, played_at date)
- `POST /scores/bulk` — submit up to 100 scores in one request; each entry validated individually
- `GET /scores/leaderboard?game=tetris&top=10` — ranked leaderboard using `RANK() OVER (ORDER BY MAX(score) DESC)`
- `GET /scores/{id}` — fetch by ID
- `DELETE /scores/{id}` — delete

The leaderboard uses `GROUP BY player` + `RANK() OVER` window function — SQLite 3.25+ supports window functions.

27/27 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. Bulk endpoint validation: indexed field names (`scores[0].player`) — no framework helper (Medium)

**Description**: For bulk POST endpoints, validation errors need to identify which entry and which field failed (e.g., `scores[1].player`). `ValidationError` accepts any string as the field name, so this works, but the caller must prefix every field name manually. There is no per-item validation wrapper.

**Severity**: Medium

**Reproduction**:
```php
foreach ($entriesRaw as $i => $entry) {
    $entryErrors = $this->validateScoreEntry($entry, "scores[{$i}].");
    // ValidationError('scores[0].player', 'player is required.', 'required')
}
```

The result is correct but requires looping with prefix injection. A `ValidationException::forIndex(int $i, array $errors)` helper or a validator runner that supports indexed entries would reduce boilerplate.

**Potential fix**: Document this pattern in a "Bulk endpoint validation" section of `implement-patch-endpoint.md` or a new howto.

---

### 2. `RANK() OVER` window function — no ORM needed, SQLite 3.25+ supports it (Positive)

**Description**: SQLite 3.25+ (released 2018, included in PHP's bundled SQLite) supports window functions including `RANK()`. Using raw SQL with the executor works perfectly.

**Pattern used**:
```sql
SELECT player, MAX(score) AS best_score, COUNT(*) AS play_count,
       RANK() OVER (ORDER BY MAX(score) DESC) AS rank
  FROM scores
 WHERE game = ?
 GROUP BY player
 ORDER BY best_score DESC
 LIMIT ?
```

No friction — raw SQL executor handles this cleanly. This confirms NENE2's thin-SQL design works for analytics queries.

---

### 3. Bulk size limit validation — no `maxItems` constraint on arrays (Low)

**Description**: Limiting bulk payload size (e.g., max 100 items) requires manual `count()` check. There is no `@MaxItems` or similar constraint in the validation layer.

**Severity**: Low

**Pattern used**:
```php
if (count($entriesRaw) > 100) {
    throw new ValidationException([
        new ValidationError('scores', 'scores may contain at most 100 entries.', 'out_of_range'),
    ]);
}
```

Simple and explicit — not a significant friction, just boilerplate.

---

### 4. CS-Fixer: trailing whitespace in aligned array literals (Low)

**Description**: PHP-CS-Fixer (`@PSR12`) disallows extra spaces used for alignment. Code written with aligned columns for readability requires post-hoc `cs:fix` run.

**Example**:
```php
// Written:
$this->submitScore('Bob',   'tetris', 2000); // extra space before 'tetris'

// CS-Fixer normalises to:
$this->submitScore('Bob', 'tetris', 2000);
```

**Severity**: Low (tooling expectation, not a framework issue).

## Tests

27 tests, 52 assertions — all pass.

Coverage: list (empty, pagination, game filter, player filter), submit (201, zero score allowed, missing player, negative score, invalid date, multiple scores same player), bulk (201 with 3 entries, empty array, missing field, invalid entry, over-100 limit), leaderboard (ranked by best score with play_count, top-N limit, missing game, invalid top, empty game), show (200, 404), delete (204, 404), infrastructure (security headers + X-Request-Id, unknown route 404).
