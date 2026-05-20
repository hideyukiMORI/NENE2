# Optimistic Locking

Optimistic locking prevents the **lost-update problem** — when two concurrent writers both read the same record, make independent changes, and the second writer silently overwrites the first writer's changes.

Use optimistic locking when:
- Conflicts are infrequent (most updates succeed)
- You need non-blocking reads (no SELECT FOR UPDATE)
- The record has a `version` or `updated_at` field to track its state

## The Lost-Update Problem

Without locking:

```
time | Writer A              | Writer B
-----|----------------------|-------------------
  1  | GET /articles/1      | GET /articles/1
     | ← version: 1         | ← version: 1
  2  | [edits title]        | [edits body]
  3  | PATCH /articles/1    |
     | title = "A's title"  |
     | ← version: 1, 200 OK |
  4  |                      | PATCH /articles/1
     |                      | body = "B's body"
     |                      | ← version: 1, 200 OK  ← A's title LOST
```

Writer B overwrites Writer A's title change because neither checked for concurrent modification.

## Schema

Add a `version` column that increments on every update:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
```

## Repository Implementation

```php
/**
 * @throws ConflictException if another writer updated the record first
 * @throws \RuntimeException if the article does not exist
 */
public function update(int $id, string $title, string $body, int $expectedVersion): Article
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    // WHERE version = $expectedVersion is the optimistic lock check.
    // If another writer has already incremented version, this UPDATE matches 0 rows.
    $affected = $this->executor->execute(
        'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // 0 rows updated: either not found OR version conflict — distinguish them
        $current = $this->findById($id);
        if ($current === null) {
            throw new \RuntimeException("Article {$id} does not exist.");
        }
        throw new ConflictException($id, $expectedVersion);
    }

    return new Article(id: $id, title: $title, body: $body, version: $expectedVersion + 1, updatedAt: $now);
}
```

### Why `version = version + 1` in SQL (not in PHP)

```php
// ❌ Race condition: two writers both read version=1, both compute version=2
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ Atomic: the database increments — version is always correct
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

The `WHERE version = $expectedVersion` check is the guard; `version = version + 1` ensures the new value is exactly one more than what passed the guard.

## Controller Integration

The client must read the current `version` and send it back with every update:

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $id   = (int) Router::param($request, 'id');
    $body = json_decode((string) $request->getBody(), true);

    if (!is_array($body) || !is_int($body['version'] ?? null)) {
        return $this->problems->create($request, 'invalid-body', 'version (int) is required.', 400);
    }

    try {
        $article = $this->repo->update($id, $body['title'], $body['body'], $body['version']);
        return $this->json->create($this->serialize($article));
    } catch (ConflictException $e) {
        $current = $this->repo->findById($id);
        return $this->problems->create(
            $request,
            'conflict',
            'Optimistic lock conflict.',
            409,
            $e->getMessage(),
            $current !== null ? ['current_version' => $current->version] : [],
        );
    } catch (\RuntimeException) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }
}
```

## Client Flow

```
POST /articles            → 201 { id: 1, version: 1, ... }
GET /articles/1           → 200 { id: 1, version: 1, ... }

PATCH /articles/1         → 200 { id: 1, version: 2, ... }
  { title: "...", version: 1 }

PATCH /articles/1         → 409 { type: "conflict", current_version: 2 }
  { title: "...", version: 1 }   (stale version — conflict!)

PATCH /articles/1         → 200 { id: 1, version: 3, ... }
  { title: "...", version: 2 }   (re-fetch or use current_version from 409)
```

Including `current_version` in the 409 response lets the client retry without an extra GET.

## Response Payload

Always include `version` in every response so clients always have the latest value:

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← client needs this to send back
        'updated_at' => $article->updatedAt,
    ];
}
```

## Optimistic vs Pessimistic Locking

| | Optimistic | Pessimistic |
|---|---|---|
| Mechanism | `WHERE version = ?` + 0-rows check | `SELECT ... FOR UPDATE` |
| Read blocking | None | Blocks other readers |
| Conflict rate | Low (most updates succeed) | High contention OK |
| Retry cost | Client retries on 409 | Waits for lock release |
| SQLite support | ✅ | ❌ (not supported) |
| Best for | Infrequent conflicts, UX-driven retries | High contention, must-succeed operations |

## Code Review Checklist

- [ ] UPDATE includes `AND version = ?` in the WHERE clause
- [ ] `execute()` return value (affected rows) is checked — 0 means conflict or not found
- [ ] 0-rows case distinguishes "not found" from "version conflict" (extra `findById` on conflict path)
- [ ] `version = version + 1` is computed in SQL, not in PHP application code
- [ ] Every response payload includes `version` so the client always has the latest
- [ ] 409 response includes `current_version` for client retry without extra GET
- [ ] `version` in request body is validated as `int`, not `string` (`is_int()` check)
- [ ] Tests cover: successful update, successive updates, concurrent conflict, retry after conflict, 404, missing version
