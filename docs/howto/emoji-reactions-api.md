# How-to: Emoji Reactions API

> **FT reference**: FT306 (`NENE2-FT/emojilog`) — Emoji reactions: UNIQUE(post_id, user_id, emoji) allows same emoji by multiple users but prevents one user reacting with the same emoji twice, mb_strlen max 8 characters, urldecode() for emoji in DELETE path, user_reactions shows the current actor's reactions, reactions ordered by count DESC, 18 tests / 28 assertions PASS.

This guide shows how to implement an emoji reaction system where multiple users can react to a post with any emoji, but each user can only use a given emoji once per post.

## Schema

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

`UNIQUE(post_id, user_id, emoji)` allows:
- Same emoji by multiple users: Alice and Bob can both react with `👍`
- Different emojis by the same user: Alice can use both `👍` and `❤️`

But prevents:
- Same user + same emoji twice: Alice cannot use `👍` on the same post twice

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/posts/{id}/reactions` | `X-User-Id` (optional) | Get reaction counts + actor's reactions |
| `POST` | `/posts/{id}/reactions` | `X-User-Id` | Add reaction |
| `DELETE` | `/posts/{id}/reactions/{emoji}` | `X-User-Id` | Remove reaction |

## Add Reaction — Strict Validation

```php
if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
    return $this->responseFactory->create(['error' => 'emoji is required'], 422);
}
$emoji = trim($body['emoji']);
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}

$added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));
if (!$added) {
    return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
}
```

- `is_string()` check rejects non-string types
- `trim()` before empty check prevents whitespace-only emoji
- `mb_strlen()` — not `strlen()` — for correct multibyte character count
- Duplicate add → 409 Conflict (not 422)

## Remove Reaction — URL Decode for Emoji in Path

```php
$emoji = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';
if ($emoji === '') {
    return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
}
```

Emoji characters in URL path segments must be URL-encoded by clients. `urldecode()` restores the original emoji for DB lookup. Example: `DELETE /posts/1/reactions/%F0%9F%91%8D` → looks up `👍`.

## Reaction Counts Response

```php
// Group by emoji, count, order by count DESC
$counts = $this->repository->getReactionCounts($postId);

// If actor provided, show which emojis they've used
$userReactions = [];
if ($actorId !== null) {
    $userReactions = $this->repository->getUserReactions($postId, $actorId);
}

return $this->responseFactory->create([
    'post_id'        => $postId,
    'reactions'      => $counts,        // [{emoji, count}, ...] ordered by count DESC
    'user_reactions' => $userReactions, // ['👍', '❤️', ...] for the current actor
]);
```

`user_reactions` is empty when no `X-User-Id` header is provided — this field shows the current viewer's reactions to help frontends highlight their active reactions.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| `UNIQUE(post_id, user_id)` (no emoji column) | One user can only ever use one emoji per post |
| `strlen()` for emoji length check | Multi-byte emoji like `🎉` (4 bytes) would be counted wrong |
| No `urldecode()` on path emoji | `👍` as `%F0%9F%91%8D` never matches stored `👍` |
| Return 404 for duplicate reaction | Hides the 409 semantic — duplicate reactions are conflicts, not missing resources |
| No emoji length limit | Arbitrary-length strings stored as emoji column |
| Empty `user_reactions` when no actor, but still include key | Omit or return `[]` — both are fine, but document the behavior |
| `trim()` after empty check | Whitespace-only `"  "` emoji passes as valid |
