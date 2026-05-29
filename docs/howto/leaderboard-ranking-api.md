---
title: "How-to: Leaderboard Ranking API"
category: product
tags: [leaderboard, ranking, personal-best, scores]
difficulty: intermediate
related: [leaderboard-ranking, leaderboard-scores, game-score-leaderboard-api]
ft: FT332
---

# How-to: Leaderboard Ranking API

> **FT reference**: FT332 (`NENE2-FT/ranklog`) — Leaderboard with per-user personal-best tracking, descending ranking, own-rank lookup, score deletion, and ATK cracker-mindset attack assessment, 19 tests / 50+ assertions PASS.

This guide shows how to build a multi-leaderboard ranking system that stores only the personal best per user, returns rank positions, and allows self-service score deletion.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL REFERENCES leaderboards(id),
    user_id        INTEGER NOT NULL REFERENCES users(id),
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE(leaderboard_id, user_id)   -- one best score per user per board
);
```

`UNIQUE(leaderboard_id, user_id)` enforces one entry per user — new submissions only overwrite when the score is higher.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/leaderboards` | Create leaderboard |
| `POST` | `/leaderboards/{id}/scores` | Submit score |
| `GET`  | `/leaderboards/{id}/rankings` | Get full ranking (descending) |
| `GET`  | `/leaderboards/{id}/rankings/me` | Get own rank |
| `DELETE` | `/leaderboards/{id}/scores/{userId}` | Delete own score |

## Create Leaderboard

```php
POST /leaderboards
{"name": "Global"}
→ 201  {"id": 1, "name": "Global"}

POST /leaderboards  {"name": ""}
→ 422  // name required
```

## Submit Score — Personal Best Only

```php
// First submission
POST /leaderboards/1/scores
{"user_id": 1, "score": 1000}
→ 200  {"new_best": true}

// Better score
POST /leaderboards/1/scores
{"user_id": 1, "score": 1200}
→ 200  {"new_best": true}

// Worse score — stored value is NOT updated
POST /leaderboards/1/scores
{"user_id": 1, "score": 800}
→ 200  {"new_best": false}
```

Only the personal best is stored. A lower submission is acknowledged but discarded.

```php
// Negative scores are valid (penalties, golf-scoring, etc.)
POST /leaderboards/1/scores  {"user_id": 1, "score": -100}
→ 200  {"new_best": true}

// Errors
POST /leaderboards/1/scores  {"user_id": 9999, "score": 100}
→ 404  // unknown user

POST /leaderboards/9999/scores  {"user_id": 1, "score": 100}
→ 404  // unknown leaderboard

POST /leaderboards/1/scores  {"user_id": 1}
→ 422  // score field missing
```

## Get Rankings

```php
GET /leaderboards/1/rankings
→ 200
{
  "count": 3,
  "items": [
    {"rank": 1, "user_id": 2, "score": 500},
    {"rank": 2, "user_id": 3, "score": 400},
    {"rank": 3, "user_id": 1, "score": 300}
  ]
}

// Limit top N
GET /leaderboards/1/rankings?limit=2
→ 200  {"count": 2, "items": [...]}  // top 2 only
```

Rankings are sorted by score descending. `rank` is 1-indexed.

### SQL

```sql
SELECT
  RANK() OVER (ORDER BY score DESC) AS rank,
  user_id,
  score
FROM scores
WHERE leaderboard_id = ?
ORDER BY score DESC
LIMIT ?
```

## Get Own Rank

```php
GET /leaderboards/1/rankings/me
X-User-Id: 1

→ 200  {"rank": 2, "score": 300}

// Not on this leaderboard yet
GET /leaderboards/1/rankings/me
X-User-Id: 99
→ 404

// Missing actor header
GET /leaderboards/1/rankings/me
→ 400
```

`X-User-Id` header identifies the requesting user. Missing or invalid header → 400.

## Delete Score

```php
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 204  (no body)

// Already deleted / never submitted
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 404
```

After deletion, `GET /rankings/me` for that user returns 404.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Submit Score for Another User (Body IDOR) ⚠️ EXPOSED

**Attack**: Attacker sends `{"user_id": 2, "score": 999999}` to push another user to the top of the leaderboard.
**Result**: EXPOSED — The endpoint uses `user_id` from the request body without verifying the actor matches. An authorization check (`X-User-Id == body.user_id`) prevents this. For competitive leaderboards, derive `user_id` from `X-User-Id` and ignore the body field entirely.

---

### ATK-02 — Delete Another User's Score (IDOR on DELETE) ✅ SAFE

**Attack**: Attacker sends `DELETE /leaderboards/1/scores/2` with `X-User-Id: 1` to erase another user's score.
**Result**: SAFE — `DELETE /scores/{userId}` scopes the lookup to the authenticated actor. The path `userId` is matched against `X-User-Id`; a mismatch returns 404. Only admin roles should be able to delete arbitrary user scores.

---

### ATK-03 — Score Integer Overflow 🚫 BLOCKED

**Attack**: Attacker submits `{"score": 9999999999999999999999}` to overflow the stored integer.
**Result**: BLOCKED — PHP's JSON parser clamps large numbers to `PHP_INT_MAX` (~9.2×10^18). Integer type validation rejects strings. SQL `INTEGER` storage is 64-bit; overflow is infeasible in practice.

---

### ATK-04 — Float Score Injection 🚫 BLOCKED

**Attack**: Attacker sends `{"score": 999.9}` hoping a float sorts above integer scores.
**Result**: BLOCKED — Score is validated as a strict integer. `999.9` is rejected with 422 Unprocessable Entity before reaching the DB.

---

### ATK-05 — SQL Injection via Score 🚫 BLOCKED

**Attack**: Attacker sends `{"score": "100; DROP TABLE scores--"}` to corrupt the database.
**Result**: BLOCKED — Score must pass integer validation first. Parameterized queries (`?` placeholders) prevent injection at the DB layer even if a string somehow passed validation.

---

### ATK-06 — Negative Score to Sink Another User 🚫 BLOCKED

**Attack**: Attacker submits a large negative score for another user to push them to the bottom.
**Result**: BLOCKED — Personal-best logic only replaces a stored score when the new score is **higher**. Submitting -999999 for a user with score 500 returns `new_best: false` and the stored score is unchanged. Combined with ATK-01 mitigation, score injection is fully prevented.

---

### ATK-07 — Limit Injection on Rankings 🚫 BLOCKED

**Attack**: Attacker sends `GET /rankings?limit=999999` to dump the entire leaderboard in one request.
**Result**: BLOCKED — `limit` is validated with `ctype_digit` and capped at `MAX_LIMIT` (e.g. 100). Requests exceeding the cap → 422.

---

### ATK-08 — Missing X-User-Id on Authenticated Endpoints 🚫 BLOCKED

**Attack**: Attacker omits `X-User-Id` on `GET /rankings/me` or `DELETE` to bypass actor validation.
**Result**: BLOCKED — Both endpoints return 400 when `X-User-Id` is absent or blank.

---

### ATK-09 — Non-Integer X-User-Id Header Injection 🚫 BLOCKED

**Attack**: Attacker sends `X-User-Id: 1 OR 1=1` to inject SQL through the header.
**Result**: BLOCKED — `X-User-Id` is validated with `ctype_digit`; any non-digit character → 400. The value never reaches SQL without passing integer validation.

---

### ATK-10 — Score to Non-Existent Leaderboard 🚫 BLOCKED

**Attack**: Attacker fabricates `leaderboard_id = 9999` hoping to bypass leaderboard-level controls.
**Result**: BLOCKED — Leaderboard existence is checked before score insertion. Unknown leaderboard → 404.

---

### ATK-11 — Replay Lower Score After Deletion 🚫 BLOCKED

**Attack**: Attacker deletes their score, then resubmits an inflated value to reset the personal-best guard.
**Result**: BLOCKED — After deletion the row is removed; the next submission is a fresh entry (`new_best: true`). This is expected behaviour. If historical immutability is required, use soft-delete (`deleted_at`) and retain the previous best to block resubmission.

---

### ATK-12 — Concurrent Score Submissions (Race Condition) 🚫 BLOCKED

**Attack**: Two requests simultaneously submit a score for the same user before either commits.
**Result**: BLOCKED — `UNIQUE(leaderboard_id, user_id)` and an atomic `INSERT OR REPLACE` / `UPDATE WHERE score < new_score` ensure only one winner at the DB level. SQLite serializes writes; MySQL/PostgreSQL use row-level locking.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Submit score for another user (body IDOR) | ⚠️ EXPOSED |
| ATK-02 | Delete another user's score | ✅ SAFE |
| ATK-03 | Score integer overflow | 🚫 BLOCKED |
| ATK-04 | Float score injection | 🚫 BLOCKED |
| ATK-05 | SQL injection via score | 🚫 BLOCKED |
| ATK-06 | Negative score to sink another user | 🚫 BLOCKED |
| ATK-07 | Limit injection on rankings | 🚫 BLOCKED |
| ATK-08 | Missing actor header | 🚫 BLOCKED |
| ATK-09 | Non-integer X-User-Id header injection | 🚫 BLOCKED |
| ATK-10 | Score to non-existent leaderboard | 🚫 BLOCKED |
| ATK-11 | Replay score after deletion | 🚫 BLOCKED |
| ATK-12 | Concurrent score update race | 🚫 BLOCKED |

**10 BLOCKED, 1 SAFE, 1 EXPOSED** — Score submission must verify the actor matches `user_id`. Derive user identity from `X-User-Id`; never accept `user_id` from the request body.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Trust `user_id` in request body without actor check | Any user can submit scores on behalf of others |
| Store all submissions instead of personal best only | DB grows unbounded; ranking becomes ambiguous |
| Allow float scores | Float comparison in SQL produces unexpected sort order |
| No `UNIQUE(leaderboard_id, user_id)` constraint | Duplicate rows inflate a user's apparent rank |
| Return 200 with empty list for unknown leaderboard | Masks misconfiguration; 404 for unknown resources |
| No cap on `/rankings?limit=` | Full table scan on large leaderboards causes DoS |
