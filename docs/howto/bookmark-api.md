---
title: "How-to: Bookmark API"
category: product
tags: [bookmarks, collections, deduplication, idor-prevention]
difficulty: beginner
related: [bookmark-system, url-bookmark-api, content-collection]
---

# How-to: Bookmark API

> **FT reference**: FT295 (`NENE2-FT/bookmarklog`) — Bookmark management: UNIQUE(user_id, item_id) prevents duplicate bookmarks, collection grouping with optional filter, user-scoped access (IDOR prevention), 409 on duplicate, 22 tests / 64 assertions PASS.

This guide shows how to build a bookmark API where users save items to named collections with deduplication and user-scoped access control.

## Schema

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` ensures each user can bookmark an item exactly once. The `collection` field groups bookmarks into named lists (default: `'default'`).

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/users/{userId}/bookmarks` | Add bookmark |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Remove bookmark |
| `GET` | `/users/{userId}/bookmarks` | List bookmarks (optionally filtered by collection) |
| `GET` | `/users/{userId}/bookmarks/count` | Count bookmarks |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Get specific bookmark |

## Route Registration Order

`/users/{userId}/bookmarks/count` must be registered **before** `/users/{userId}/bookmarks/{itemId}` to prevent `count` being captured as `{itemId}`:

```php
$router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
$router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));  // static before dynamic
$router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
```

## Adding a Bookmark

```php
private function addBookmark(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
    $collection = isset($body['collection']) && is_string($body['collection'])
        ? trim($body['collection']) : 'default';

    if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
        return $this->responseFactory->create(['error' => 'item not found'], 404);
    }

    if ($collection === '') {
        $collection = 'default';  // empty collection string → fall back to 'default'
    }

    $now      = date('Y-m-d H:i:s');
    $bookmark = $this->repo->add($userId, $itemId, $collection, $now);
    return $this->responseFactory->create($bookmark->toArray(), 201);
}
```

`item_id` requires `is_int()` — JSON string `"5"` is rejected. The `UNIQUE` constraint in the DB catches races; the repository should catch the constraint violation and return 409.

## Collection Filter on List

```php
$query      = $request->getQueryParams();
$collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

Without `?collection=`, all bookmarks are returned. With `?collection=favorites`, only that collection is returned. Empty collection query parameter is treated as "no filter."

## User Scoping — IDOR Prevention

Every endpoint validates `userId` against the DB before returning data:

```php
if ($userId <= 0 || !$this->repo->findUserById($userId)) {
    return $this->responseFactory->create(['error' => 'user not found'], 404);
}
```

Requesting `/users/999/bookmarks` as a different user returns 404 (not the other user's bookmarks). All queries are scoped to the path's `userId`.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE(user_id, item_id)` | User bookmarks same item multiple times; confusing duplicates |
| Return 200 on duplicate bookmark | Client cannot distinguish "added" from "already exists"; use 409 |
| Accept `item_id` as string from body | JSON type confusion: `"5"` ≠ `5`; use `is_int()` |
| Register `/{itemId}` before `/count` | `GET /users/1/bookmarks/count` resolves to `itemId = "count"` (wrong handler) |
| No user existence check | Non-existent userId returns empty list instead of 404 |
| No user scoping in queries | User A sees user B's bookmarks (IDOR) |
| No collection default | Missing `collection` field crashes or leaves `NULL` in DB |
