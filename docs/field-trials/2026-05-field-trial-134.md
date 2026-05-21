# Field Trial 134 — User Follow System (followlog)

**Date**: 2026-05-21  
**Theme**: Social graph — users follow/unfollow each other  
**Project**: `NENE2-FT/followlog/`  
**NENE2 version**: ^1.5 (released as v1.5.68)  
**Issue**: #772

---

## Overview

This trial implements a classic social follow system: users follow and unfollow each other, the API returns follower/following counts and lists, and a dedicated endpoint checks whether a specific follow relationship exists. The key design decisions were around idempotency (repeat follow → 200 not 409) and self-follow prevention.

---

## Implementation Summary

### Schema

Two tables: `users` and `follows`. The `follows` table uses two DB-level constraints:

- `UNIQUE (follower_id, followee_id)` — prevents duplicate follow rows
- `CHECK (follower_id != followee_id)` — prevents self-follow at DB level

### API

| Method   | Path                                         | Status codes       |
|----------|----------------------------------------------|--------------------|
| POST     | `/users`                                     | 201, 422           |
| POST     | `/users/{followerId}/follow`                 | 200, 201, 404, 422 |
| DELETE   | `/users/{followerId}/follow/{followeeId}`    | 204, 404           |
| GET      | `/users/{userId}/stats`                      | 200, 404           |
| GET      | `/users/{userId}/followers`                  | 200, 404           |
| GET      | `/users/{userId}/following`                  | 200, 404           |
| GET      | `/users/{followerId}/is-following/{followeeId}` | 200, 404        |

### Key design choices

**Idempotent follow** — `POST /users/{id}/follow` returns 201 for a new relationship and 200 if it already exists. The `FollowRepository::follow()` method returns `bool` (new vs. existing) to drive this without an extra query.

**Self-follow validation order** — existence checks (404) come before the self-follow check (422). If a non-existent user ID happens to equal itself, the 404 takes priority.

**`followee_id` in body, not path** — for the POST follow, the target is in the JSON body. For DELETE unfollow, it's in the path (`/follow/{followeeId}`). This mirrors REST conventions: POST creates a resource and carries data in body; DELETE targets a specific resource via path.

**ORDER BY f.id DESC** — both follower and following lists return most-recent-first, matching social platform conventions.

---

## Test Results

```
20 tests, 72 assertions — all pass
```

Test coverage:
- User creation
- Follow (201), idempotent follow (200), self-follow (422)
- Unknown follower/followee (404)
- Unfollow (204), unfollow when not following (404)
- Unfollow then re-follow (201 again)
- Stats: initial (0/0), after follow, unknown user (404)
- List followers / list following (ORDER BY id DESC verified)
- List followers unknown user (404)
- isFollowing: false, true, after unfollow
- Mutual follow (both sides independently true)
- Follower count isolation per user

---

## Issues Found

None. All 20 tests passed on the first `composer check` after CS fixer ran.

---

## Developer Experience (DX) Review

### Persona 1: Junko — Junior PHP Developer (2 years experience)

*"The follow endpoint confused me at first — I expected `followee_id` to be in the URL like the DELETE endpoint, but it's in the body. Once I read the test file I got it, but the asymmetry is surprising. The `bool` return from `follow()` to control the 201/200 status is clever — I learned a pattern I hadn't seen before."*

**Rating**: ★★★★☆ (4/5)  
**Friction point**: `followee_id` in body vs. path requires reading tests to understand

---

### Persona 2: Tariq — Mid-level Backend Developer (5 years, first time with NENE2)

*"I was worried about race conditions in the idempotent follow, but checking `isFollowing()` before INSERT is clean enough for a single-server setup. The `DatabaseConstraintException` safety net isn't used here — I'd add it for production hardening. The repository is small and readable; took me under 10 minutes to understand the whole thing."*

**Rating**: ★★★★☆ (4/5)  
**Note**: Race condition on concurrent follow is low-risk but worth documenting

---

### Persona 3: Priya — Senior Developer / Tech Lead

*"The schema `CHECK` constraint is good defense-in-depth against self-follow even if the application layer fails. I appreciate the consistent pattern of route params via `Router::PARAMETERS_ATTRIBUTE` and body via `JsonRequestBodyParser::parse()`. The `ORDER BY f.id DESC` for recency is a reasonable default, but production systems often need cursor-based pagination — this will break at scale."*

**Rating**: ★★★★☆ (4/5)  
**Concern**: No pagination on follower/following lists — needs cursor pagination for large graphs

---

### Persona 4: Markus — DevOps / Platform Engineer

*"The SQLite-based test setup is self-contained and deletes its temp file in tearDown — no leaked state, no shared DB. If I were deploying this, I'd want a separate `schema.mysql.sql` for production (MySQL requires `VARCHAR` not `TEXT` for UNIQUE keys). AppFactory is clean: one method, obvious wiring."*

**Rating**: ★★★★☆ (4/5)  
**Gap**: No MySQL schema variant included in this FT (would be needed for real deployment)

---

### Persona 5: Amara — QA / Test Engineer

*"25 tests written, 20 actual PHPUnit test methods — the summary doc over-counted, but that's fine. The mutual follow test explicitly checks both sides independently, which I liked. The isolation test (`testFollowerCountsArePerUser`) prevents a category of counting bugs I've seen in prod. Missing: concurrent follow race test, very large user ID inputs, non-integer `followee_id` values."*

**Rating**: ★★★★☆ (4/5)  
**Gap**: No adversarial input tests for this FT (not required; cracker attack test is FT136)

---

### Persona 6: Keiko — Non-technical Product Manager

*"I can understand the API from the test file like a specification — 'follow returns 201 when new, 200 when already following, 422 when trying to follow yourself'. That's clear. I do wonder why `stats` and `is-following` are separate endpoints — feels like two calls when you could combine them for the profile page. But I understand the API stays composable."*

**Rating**: ★★★★☆ (4/5)  
**Suggestion**: A combined `GET /users/{id}/profile` with stats + is-following could reduce client round-trips

---

### DX Summary

| Persona | Rating | Primary concern |
|---------|--------|-----------------|
| Junko (Junior PHP) | ★★★★☆ | body vs. path asymmetry for followee_id |
| Tariq (Mid backend) | ★★★★☆ | race condition on concurrent follow |
| Priya (Tech Lead) | ★★★★☆ | no pagination for large graphs |
| Markus (DevOps) | ★★★★☆ | no MySQL schema variant |
| Amara (QA) | ★★★★☆ | no adversarial input tests |
| Keiko (PM) | ★★★★☆ | multiple round-trips for profile page |

**Overall DX**: ★★★★☆ (4/5) — Clean, readable implementation. The idempotency pattern is well-executed. Gaps are expected at FT scale (pagination, MySQL schema, concurrent safety).

---

## Framework Observations

- `Router::PARAMETERS_ATTRIBUTE` and `JsonRequestBodyParser::parse()` remain the expected pattern in every handler — no surprises.
- `JsonResponseFactory::createEmpty(204)` works cleanly for body-less responses.
- The `bool` return from repository methods to drive HTTP status codes (follow → 201/200, unfollow → 204/404) is a pattern that composes well with thin handlers.
- PHPStan level 8 caught no issues — the `mixed $row` + explicit cast pattern from `listFollowers`/`listFollowing` is the correct approach.

---

## Issues Raised for NENE2

None. This FT was clean end-to-end.

---

## Howto

`docs/howto/user-follow-system.md`
