# Soft Delete (Logical Deletion)

Soft delete keeps a record in the database but marks it as deleted by setting a `deleted_at` timestamp. This enables:
- Undo / restore functionality
- Audit trails (who deleted what, when)
- Referential integrity (records can still be referenced until purged)

## Schema

Add a `deleted_at` column that is `NULL` for active records and a timestamp for deleted records:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL          -- NULL = active, timestamp = deleted
);
```

## The Critical Rule: Always Filter deleted_at

**Every query that should return only active records must include `AND deleted_at IS NULL`.** Missing this filter is the most common mistake — the code works but deleted data leaks into API responses.

```php
// ❌ Missing filter — returns deleted records too
$rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

// ✅ Exclude deleted
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

This applies to every query: `findById`, `findAll`, `findByUser`, pagination queries, and JOIN targets.

## Entity

```php
final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

## Repository Pattern

Use an `$includeTrashed = false` flag. The default of `false` means callers must explicitly opt in to see deleted records, which prevents accidental leakage:

```php
final class ArticleRepository
{
    public function findById(int $id, bool $includeTrashed = false): ?Article
    {
        $sql = $includeTrashed
            ? 'SELECT * FROM articles WHERE id = ?'
            : 'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL';

        $row = $this->executor->fetchOne($sql, [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Article> */
    public function findActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY created_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Article> */
    public function findTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id): ?Article
    {
        $article = $this->findById($id); // active only
        if ($article === null) {
            return null;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute('UPDATE articles SET deleted_at = ? WHERE id = ?', [$now, $id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, $now);
    }

    public function restore(int $id): ?Article
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return null; // not found, or not in trash
        }
        $this->executor->execute('UPDATE articles SET deleted_at = NULL WHERE id = ?', [$id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, null);
    }

    /** Permanently delete — only allowed from trash. */
    public function purge(int $id): bool
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return false; // guard: must be in trash first
        }
        $this->executor->execute('DELETE FROM articles WHERE id = ?', [$id]);
        return true;
    }
}
```

### Use `insert()` for INSERT

When creating records, use `insert()` (not `execute()` + `lastInsertId()`):

```php
// ❌ Two calls
$this->executor->execute('INSERT INTO articles ...', [...]);
$id = $this->executor->lastInsertId();

// ✅ One call — returns the inserted row ID
$id = $this->executor->insert('INSERT INTO articles ...', [...]);
```

## Endpoints

A typical soft-delete API:

| Method | Path | Description |
|---|---|---|
| `POST` | `/articles` | Create |
| `GET` | `/articles` | Active records only |
| `GET` | `/articles/trash` | Deleted records only |
| `GET` | `/articles/{id}` | Get one (404 if deleted) |
| `DELETE` | `/articles/{id}` | Soft delete → 404 if already deleted |
| `POST` | `/articles/{id}/restore` | Restore → 404 if not in trash |
| `DELETE` | `/articles/{id}/purge` | Hard delete → 404 if not in trash |

**Note on REST semantics:** `DELETE /articles/{id}` behaves as a soft delete, not a permanent removal. If this surprises clients, document it clearly in the OpenAPI spec, or use `POST /articles/{id}/trash` for the soft-delete action.

## Always Include `deleted_at` in Responses

Include `deleted_at` in every response so clients can determine resource state without extra requests:

```php
return $this->json->create([
    'id'         => $article->id,
    'title'      => $article->title,
    'body'       => $article->body,
    'created_at' => $article->createdAt,
    'updated_at' => $article->updatedAt,
    'deleted_at' => $article->deletedAt, // null = active; timestamp = deleted
]);
```

## Foreign Keys and Soft Delete

When other tables reference a soft-deleted record:
- Soft deletion does not break foreign key constraints — the row still exists
- Hard delete (purge) can violate constraints if referencing rows exist
- Before purging, check for dependent records or cascade soft delete to dependents

## Code Review Checklist

- [ ] Every query for active records includes `AND deleted_at IS NULL`
- [ ] `findById()` default is `$includeTrashed = false` — callers explicitly opt in
- [ ] `purge()` guards against hard-deleting active records (`isDeleted()` check)
- [ ] `restore()` returns `null` (→ 404) when the record is not in trash
- [ ] JOIN queries on soft-deleted tables also filter `deleted_at IS NULL` on the joined table
- [ ] `deleted_at` is included in API responses so clients can determine state
- [ ] `DELETE /articles/{id}` behavior (soft vs hard) is documented in OpenAPI
- [ ] Tests cover: delete → 404 on GET, list excludes deleted, restore → visible again, purge → gone everywhere, double-delete → 404, purge active → 404
- [ ] `insert()` is used for INSERT (not `execute()` + `lastInsertId()`)
