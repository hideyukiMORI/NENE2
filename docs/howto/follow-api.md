# How-to: Follow / Unfollow API

> **FT reference**: FT314 (`NENE2-FT/followlog`) — Social follow graph: idempotent follow (POST 201 first time, 200 on repeat), self-follow prevention (422), unfollow (DELETE 204), followers/following counts via stats, paginated lists ordered by most-recent-first, is-following check, mutual follow support, 20 tests / 72 assertions PASS.

This guide shows how to build a social follow system where users can follow and unfollow each other, with follower/following counts and list endpoints.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL REFERENCES users(id),
    followee_id INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (follower_id, followee_id)
);
```

The `UNIQUE (follower_id, followee_id)` constraint enforces the idempotency of follow relationships at the DB level.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/users` | Create user |
| `POST` | `/users/{id}/follow` | Follow another user |
| `DELETE` | `/users/{id}/follow/{followeeId}` | Unfollow |
| `GET` | `/users/{id}/stats` | Get follower/following counts |
| `GET` | `/users/{id}/followers` | List followers (most recent first) |
| `GET` | `/users/{id}/following` | List following (most recent first) |
| `GET` | `/users/{id}/is-following/{targetId}` | Check if following |

## Idempotent Follow

```php
// First follow → 201 Created
POST /users/1/follow  {"followee_id": 2}
→ 201  {"following": true, "follower_id": 1, "followee_id": 2}

// Repeated follow with same pair → 200 OK (not 201, not 409)
POST /users/1/follow  {"followee_id": 2}
→ 200  {"following": true, "follower_id": 1, "followee_id": 2}
```

```php
// Handler logic
try {
    $this->repo->follow($followerId, $followeeId);
    return $json->ok($response, ['following' => true, ...], 201);
} catch (DuplicateFollowException $e) {
    return $json->ok($response, ['following' => true, ...], 200); // already following
}
```

## Self-Follow Prevention

```php
POST /users/1/follow  {"followee_id": 1}
→ 422 Unprocessable Entity
```

```php
if ($followerId === $followeeId) {
    throw new ValidationException([
        ['field' => 'followee_id', 'message' => 'Cannot follow yourself.', 'code' => 'self-follow'],
    ]);
}
```

## Unfollow

```php
DELETE /users/1/follow/2
→ 204 No Content   // successfully unfollowed

DELETE /users/1/follow/2  // when not following
→ 404 Not Found
```

Unfollow-then-refollow cycle works correctly: DELETE → POST returns 201 again.

## Stats

```php
GET /users/1/stats
→ 200
{
    "user_id": 1,
    "followers_count": 2,
    "following_count": 3
}
```

`followers_count` = how many users follow this user.  
`following_count` = how many users this user follows.

Unknown user → 404.

## Followers / Following Lists

```php
GET /users/1/followers
→ 200
{
    "items": [
        {"id": 3, "name": "Carol", "created_at": "..."},
        {"id": 2, "name": "Bob",   "created_at": "..."}
    ],
    "count": 2
}
```

- Ordered by `follows.id DESC` (most recent follower first).
- Same structure for `GET /users/{id}/following`.
- Unknown user → 404.

## Is-Following Check

```php
GET /users/1/is-following/2
→ 200  {"following": true}   // 1 follows 2

GET /users/1/is-following/2  // after unfollow
→ 200  {"following": false}
```

Returns `false` (not 404) when not following — the check itself is always valid.

## Mutual Follow

```php
POST /users/1/follow  {"followee_id": 2}
POST /users/2/follow  {"followee_id": 1}

GET /users/1/is-following/2  → {"following": true}
GET /users/2/is-following/1  → {"following": true}
```

Mutual follows are just two separate follow rows — no special table or logic needed.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return 409 for duplicate follow | Client retry logic breaks; idempotent operations should return 200, not error |
| Allow self-follow | Corrupts stats (`followers_count` inflated by self); feeds look wrong |
| No UNIQUE constraint on (follower_id, followee_id) | Race condition on concurrent follow clicks creates duplicate rows |
| DELETE non-existing follow returns 204 | Client can't distinguish "unfollowed" from "never followed"; use 404 |
| Order by name or ID instead of recency | Most recent followers/following lost in long list; UX expectation is "who followed me recently" |
| Shared follow counts across users | Follower counts bleed between unrelated users; always scope by user_id |
