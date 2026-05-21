# How to Build an Emoji Reaction System with NENE2

This guide walks through building a reaction system where users react to posts with emojis, with grouped counts and per-user reaction tracking.

**Field Trial**: FT143  
**NENE2 version**: ^1.5  
**Covered topics**: UNIQUE(post_id, user_id, emoji) constraint, GROUP BY emoji counts, per-user reaction tracking, emoji length validation, MySQL integration tests

---

## What we're building

- `POST /posts` — create a post
- `POST /posts/{id}/reactions` — add a reaction (emoji string, one per emoji per user)
- `DELETE /posts/{id}/reactions/{emoji}` — remove a reaction (own only)
- `GET /posts/{id}/reactions` — get reaction counts and current user's reactions

---

## Database schema

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (post_id, user_id, emoji)` — one row per emoji per user per post. The same user can react with different emojis (👍 and ❤️ = 2 rows). Multiple users can use the same emoji (each gets their own row).

---

## Duplicate reaction → 409

```php
public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $emoji, $now],
        );
        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

The handler returns 409 when `addReaction()` returns `false`. No separate existence check needed.

---

## Grouped reaction counts with GROUP BY

```sql
SELECT emoji, COUNT(*) as cnt
FROM reactions
WHERE post_id = ?
GROUP BY emoji
ORDER BY cnt DESC, emoji ASC
```

Sorted by count descending (most popular emoji first), then alphabetically as a tiebreaker. The result maps directly to a PHP `array<string, int>`:

```php
$counts = [];
foreach ($rows as $row) {
    $arr = (array) $row;
    if (isset($arr['emoji']) && is_string($arr['emoji'])) {
        $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }
}
```

---

## Per-user reactions (optional actor)

The `GET /reactions` endpoint accepts an optional `X-User-Id` header. When present, the response includes the list of emojis the caller has used:

```php
$actorId       = (int) $request->getHeaderLine('X-User-Id');
$userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];
```

This allows the UI to show which emojis the current user has already reacted with.

---

## Emoji validation

```php
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}
```

`mb_strlen` counts Unicode code points, not bytes. A single emoji like 🧑‍💻 (person: technologist) is 3 code points; an 8-char limit accommodates most emoji sequences. Adjust to your requirements.

---

## MySQL integration tests (FT143)

MySQL teardown order matters:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS reactions');
$this->pdo->exec('DROP TABLE IF EXISTS posts');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

MySQL schema uses `VARCHAR(32)` for emoji (not `TEXT`) to allow the column in a UNIQUE key without a prefix length. `VARCHAR(32)` stores up to 32 characters, which covers all emoji sequences.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Allowing duplicate emoji reactions | `UNIQUE (post_id, user_id, emoji)` + catch `DatabaseConstraintException` |
| Using `strlen()` for emoji length | Use `mb_strlen()` — emoji are multi-byte Unicode |
| Mutable count column gets out of sync | Count from `reactions` table with `GROUP BY emoji` |
| Missing MySQL emoji support | Use `utf8mb4` charset and `VARCHAR` (not `CHAR`) for emoji column |
| `is_array()` on `fetchAll` result is always true | Skip the check; `fetchAll` already returns `array<int, array<string, mixed>>` |
