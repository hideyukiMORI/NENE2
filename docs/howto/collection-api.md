---
title: "How-to: Collection API (User Curated Lists)"
category: product
tags: [collections, curated-lists, visibility, ordering, deduplication]
difficulty: intermediate
related: [bookmark-api, content-collection, content-pinning]
---

# How-to: Collection API (User Curated Lists)

> **FT reference**: FT299 (`NENE2-FT/collectionlog`) — User-curated article collections: is_public/private visibility (404 to non-owners for private), UNIQUE(collection_id, article_id) deduplication, position ordering, owner-only write access, 20 tests / 34 assertions PASS.

This guide shows how to build a user-curated collection API where users create named lists, add articles to them, and control public/private visibility.

## Schema

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=private, 1=public
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id    INTEGER NOT NULL,
    position      INTEGER NOT NULL,
    added_at      TEXT    NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id)    REFERENCES articles(id)
);
```

`UNIQUE(collection_id, article_id)` prevents the same article appearing twice in a collection. `position` enables ordered display.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/collections` | `X-User-Id` | Create collection |
| `GET` | `/collections/{id}` | `X-User-Id` | Get collection (visibility check) |
| `PUT` | `/collections/{id}` | `X-User-Id` (owner) | Update name/visibility |
| `DELETE` | `/collections/{id}` | `X-User-Id` (owner) | Delete collection |
| `POST` | `/collections/{id}/items` | `X-User-Id` (owner) | Add article |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id` (owner) | Remove article |

## Visibility — 404 for Private Collections

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

Non-owners trying to access a private collection receive 404 — not 403. This prevents disclosing the existence of private collections.

## Owner-Only Write Access

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Add, remove, update, and delete operations require the actor to be the collection owner. Unlike visibility, write-access failures return 403 (the collection's existence is already known at this point).

## UNIQUE(collection_id, article_id) — Deduplication

The DB constraint prevents the same article from appearing twice in a collection. The application checks for duplicates before inserting:

```php
// Repository checks findItem() before addItem()
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## is_public as Boolean Integer

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` is stored as INTEGER (0/1) in SQLite. On read: `(bool) $collection['is_public']`. On write: strict `=== true` check prevents string `"true"` from enabling public access.

## Response Shape

```php
private function formatCollection(array $collection, array $items): array
{
    return [
        'id'         => (int)    $collection['id'],
        'user_id'    => (int)    $collection['user_id'],
        'name'       => (string) $collection['name'],
        'is_public'  => (bool)   $collection['is_public'],
        'item_count' => count($items),
        'items'      => array_map(fn($item) => [
            'article_id' => (int)    $item['article_id'],
            'title'      => (string) $item['article_title'],
            'position'   => (int)    $item['position'],
            'added_at'   => (string) $item['added_at'],
        ], $items),
        'created_at' => (string) $collection['created_at'],
        'updated_at' => (string) $collection['updated_at'],
    ];
}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return 403 for private collection access | Reveals existence of the collection to non-owners (information disclosure) |
| Allow any user to add items to any collection | Non-owners inject content into others' collections |
| No `UNIQUE(collection_id, article_id)` | Same article added twice; confusing duplicate entries |
| Accept `"true"` string for `is_public` | Type confusion: any string is truthy in loose comparison |
| No position field | Items always appear in insertion order; no reordering possible |
| DELETE collection without ownership check | Any user deletes any collection |
| Expose `item_count` without including items | Reveals collection size even to non-owners of private collections |
