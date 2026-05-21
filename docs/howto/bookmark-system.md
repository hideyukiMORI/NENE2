# Bookmark System

Allow users to save items into named collections. Bookmarking is idempotent — bookmarking the same item twice returns the existing bookmark without error.

## Overview

A bookmark system involves:
- **Add bookmark** — save an item to a user's collection (idempotent)
- **Remove bookmark** — delete a saved bookmark (404 if not found)
- **List bookmarks** — all bookmarks for a user, optionally filtered by collection
- **Count bookmarks** — lightweight badge counter
- **Get bookmark** — check whether a specific item is bookmarked

## Database Schema

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

`UNIQUE (user_id, item_id)` enforces one bookmark per user per item. The `collection` field groups bookmarks into named categories with `'default'` as the fallback.

## Idempotent Add

Check for an existing bookmark before inserting. On conflict (race condition), catch `DatabaseConstraintException` and return the existing record:

```php
public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
{
    $existing = $this->find($userId, $itemId);

    if ($existing !== null) {
        return $existing;  // already bookmarked — not an error
    }

    try {
        $this->executor->execute(
            'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $collection, $now],
        );
    } catch (DatabaseConstraintException) {
        // Race condition — another request won; return the existing bookmark
        $found = $this->find($userId, $itemId);
        if ($found !== null) {
            return $found;
        }
    }

    $id = (int) $this->executor->lastInsertId();
    return new Bookmark($id, $userId, $itemId, $collection, $now);
}
```

The check-then-insert pattern handles the common case efficiently. The `DatabaseConstraintException` catch handles the race condition under concurrent requests.

## Collection Filtering

Use an optional `collection` query parameter to filter bookmarks:

```php
// GET /users/{userId}/bookmarks?collection=reading
$collection = isset($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

`null` collection returns all bookmarks; a non-empty string filters to that collection.

## Remove Returns 204 vs 404

- `204 No Content` — bookmark existed and was deleted
- `404 Not Found` — bookmark did not exist

```php
$removed = $this->repo->remove($userId, $itemId);

if (!$removed) {
    return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
}

return $this->responseFactory->createEmpty(204);
```

`execute()` returns the affected row count — zero means no bookmark was found.

## MySQL Schema

MySQL requires explicit `ENGINE=InnoDB` and `AUTO_INCREMENT` syntax:

```sql
CREATE TABLE bookmarks (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    item_id    INT          NOT NULL,
    collection VARCHAR(100) NOT NULL DEFAULT 'default',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

For MySQL integration tests, `SET FOREIGN_KEY_CHECKS = 0` before dropping tables to avoid FK dependency order issues.

## MySQL Integration Test Pattern

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    ...
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
    $this->pdo->exec('DROP TABLE IF EXISTS items');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schema = (string) file_get_contents('.../database/schema.mysql.sql');
    $this->pdo->exec($schema);
}

protected function tearDown(): void
{
    if ($this->mysqlEnabled && $this->pdo !== null) {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

## Security Properties

| Property | Implementation |
|---|---|
| One bookmark per user per item | `UNIQUE (user_id, item_id)` DB constraint |
| Race condition on add | `DatabaseConstraintException` catch → return existing |
| User isolation | All queries filter by `user_id` |
| Remove non-existent | Returns 404 (not silent) |

## Route Summary

| Method | Path | Description |
|---|---|---|
| `POST` | `/users` | Create a user |
| `POST` | `/items` | Create an item |
| `POST` | `/users/{userId}/bookmarks` | Add bookmark (idempotent) |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Remove bookmark (204 or 404) |
| `GET` | `/users/{userId}/bookmarks` | List bookmarks (`?collection=` filter) |
| `GET` | `/users/{userId}/bookmarks/count` | Total bookmark count |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Get single bookmark status |
