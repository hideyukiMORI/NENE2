# Field Trial 89 — Emoji Reactions (reactionlog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/reactionlog/`
**NENE2 version:** 1.5.23
**Theme:** Toggle reactions on arbitrary targets — grouped counts, per-user query, explicit remove

---

## What was built

A reaction system where users can react to any target (posts, comments, etc.) with typed reactions (like, heart, laugh, etc.). Toggle semantics: first PUT adds the reaction, second PUT removes it. Different reaction types from the same user are independent.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| PUT | `/reactions/{targetType}/{targetId}` | Toggle reaction; 201 if added, 200 if removed |
| GET | `/reactions/{targetType}/{targetId}` | Summary: counts by type, total, user's reactions |
| DELETE | `/reactions/{targetType}/{targetId}/{reactionType}` | Explicit remove; 404 if not found |

### Schema

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
```

---

## Frictions found

### 1. Toggle returns different HTTP status codes for add vs remove

**Severity:** Low (API design consideration)

PUT toggle returns `201` when adding and `200` when removing. This allows callers to distinguish the outcome without inspecting the response body. It is non-standard (PUT is typically idempotent and always returns the same status), but pragmatic for toggle APIs.

An alternative: always return `200` with `"added": true/false` in the body. This is more RESTfully pure but requires body inspection.

Neither NENE2 nor the PSR standards mandate a specific pattern — this is a pure API design choice left to the application.

---

### 2. `DELETE` on reaction requires body for `user_id`

**Severity:** Low (design friction)

The explicit remove endpoint (`DELETE /reactions/{targetType}/{targetId}/{reactionType}`) needs to know which user's reaction to remove. Passing `user_id` in the request body of a DELETE request is technically valid (RFC 7231 does not prohibit a body on DELETE) but some HTTP clients and middleware strip DELETE bodies.

Alternative approaches:
- Pass `user_id` as a query parameter: `DELETE /reactions/post/42/like?user_id=alice`
- Put `user_id` in the path: `DELETE /reactions/post/42/like/alice`

The body approach was used here for consistency with the PUT toggle endpoint. The query parameter approach might be safer for real clients.

---

### 3. First-clean PHPStan pass — no annotations needed

**Severity:** Low (positive finding)

FT89 is the first FT in this session where PHPStan level 8 passed without any annotation fixes. The key was using explicit PHPDoc on `ReactionSummary` constructor (`@param array<string, int> $counts`, `@param list<string> $userReactions`) from the start, and casting array map results inline with explicit variable annotations.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 13 tests, 23 assertions — OK |
| PHPStan level 8 | No errors (first run) |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- `target_type` allows reactions on heterogeneous entities (posts, comments, replies) with a single table.
- `summary()` returns empty `user_reactions: []` when no `?user_id=` is provided — no extra query.
- The UNIQUE constraint on `(target_id, target_type, reaction_type, user_id)` prevents double reactions and doubles as the toggle detection key.
