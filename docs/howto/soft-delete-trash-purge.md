# How-to: Soft Delete, Trash Bin, and Permanent Purge

> **FT reference**: FT257 (`NENE2-FT/softdeletelog`) — Soft delete / trash bin / permanent purge pattern with `deleted_at` column

Demonstrates a three-stage lifecycle for records: active → soft-deleted (trash) → permanently purged.
Active lists exclude deleted records automatically. A dedicated trash endpoint lists only deleted records.
Restore returns a record from trash to active. Purge physically removes the record from the database
(only allowed while in trash).

---

## Routes

| Method   | Path                   | Description                                  |
|----------|------------------------|----------------------------------------------|
| `POST`   | `/notes`               | Create a note                                |
| `GET`    | `/notes`               | List active notes (excludes soft-deleted)    |
| `GET`    | `/notes/trash`         | List trashed notes only                      |
| `GET`    | `/notes/{id}`          | Get a single active note                     |
| `DELETE` | `/notes/{id}`          | Soft-delete a note (moves to trash)          |
| `POST`   | `/notes/{id}/restore`  | Restore from trash to active                 |
| `DELETE` | `/notes/{id}/purge`    | Permanently delete (only from trash)         |

> **Route order**: `/notes/trash` must be registered before `/notes/{id}` so the literal segment
> `trash` is not captured as a path parameter.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
```

`deleted_at TEXT NULL` is the soft-delete marker. When `NULL` the record is active; when set to an
ISO timestamp the record is in the trash. No separate `is_deleted` boolean is needed — the timestamp
also records _when_ the delete happened, which is useful for audit trails and TTL-based purge jobs.

---

## Domain object

```php
final readonly class Note
{
    public function __construct(
        public int     $id,
        public string  $title,
        public string  $body,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $deletedAt,     // null = active, non-null = in trash
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

`isDeleted()` encapsulates the null-check so callers do not need to know the implementation detail.

---

## Repository: the `includeTrashed` flag

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';

    $rows = $this->executor->fetchAll($sql, [$id]);
    return $rows === [] ? null : $this->hydrate($rows[0]);
}
```

The default (`includeTrashed: false`) applies the `deleted_at IS NULL` filter so callers get the
safe behaviour automatically. Only restore and purge need to see trashed records and pass
`includeTrashed: true` explicitly.

**Why not a separate `findByIdIncludingTrashed()` method?**

A named boolean parameter is self-documenting at the call site:
- `findById($id)` — clearly active-only
- `findById($id, includeTrashed: true)` — clearly trash-aware

A separate method would duplicate the hydration logic or require an internal shared helper.

---

## Listing: active vs trash

```php
public function listActive(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
        [],
    );
}

public function listTrashed(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        [],
    );
}
```

Active notes are sorted by creation time (newest first). Trashed notes are sorted by deletion time
(most recently deleted first), which is natural for a "recently deleted" UI.

---

## Soft delete

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // active-only lookup
    if ($note === null) {
        return null;   // not found OR already in trash → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = ? WHERE id = ?',
        [$now, $id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
}
```

`findById($id)` without `includeTrashed` means that calling `DELETE /notes/{id}` on an already-trashed
note returns `null` → 404. This prevents double-delete confusion: a client cannot tell from a 404
whether the note was active and missing, or already trashed.

---

## Restore

```php
public function restore(int $id): ?Note
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return null;   // not found OR already active → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = NULL WHERE id = ?',
        [$id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
}
```

`includeTrashed: true` is required here — the note IS deleted, so the default filter would hide it.
The `!$note->isDeleted()` guard rejects an active note: calling restore on an active note returns `null`
→ 404. This makes restore idempotent in the "already restored" path: a client that calls restore twice
gets 200 on the first call and 404 on the second.

---

## Purge (permanent delete)

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;   // not found OR still active → 404
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

`purge()` only works on trashed records (`isDeleted()` must be true). Calling `DELETE /notes/{id}/purge`
on an active note returns `false` → 404. This guards against accidentally destroying data via the wrong
endpoint — a client must explicitly soft-delete before it can purge.

---

## State machine

```
           POST /notes
               │
               ▼
           [active]  ←──────── POST /notes/{id}/restore ────────┐
               │                                                  │
    DELETE /notes/{id}                                           │
               │                                                  │
               ▼                                                  │
           [trash]  ────────────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [gone — physical DELETE]
```

`active → trash` is reversible. `trash → gone` is irreversible. There is no direct path from
`active → gone`: purge requires a prior soft-delete step.

---

## Controller: route registration order

```php
public function register(Router $router): void
{
    $router->post('/notes',              $this->create(...));
    $router->get('/notes',               $this->listActive(...));
    $router->get('/notes/trash',         $this->listTrashed(...));   // ← must come before {id}
    $router->get('/notes/{id}',          $this->get(...));
    $router->delete('/notes/{id}',       $this->softDelete(...));
    $router->post('/notes/{id}/restore', $this->restore(...));
    $router->delete('/notes/{id}/purge', $this->purge(...));
}
```

`/notes/trash` must be registered before `/notes/{id}`. If the order were reversed, a `GET /notes/trash`
request would match `{id}` with `id = "trash"`, fail the integer cast, and return 404 or 200 with an
empty body instead of the trash list.

---

## HTTP semantics

| Action        | Method   | Why                                                             |
|---------------|----------|-----------------------------------------------------------------|
| Soft delete   | `DELETE` | Client intends to remove the resource from their view          |
| Restore       | `POST`   | Not idempotent (second call returns 404); `POST` is appropriate |
| Purge         | `DELETE` | Client intends permanent removal                               |

`PATCH /notes/{id}` with `{"deleted_at": null}` is an alternative for restore, but `POST /restore`
is more explicit and avoids leaking the internal column name in the API contract.

---

## Design comparison

| Approach | Active filter | Deletedmarker | Restore | Purge |
|---|---|---|---|---|
| `deleted_at` timestamp | `WHERE deleted_at IS NULL` | Timestamp + audit trail | `SET deleted_at = NULL` | Physical `DELETE` |
| `is_deleted` boolean | `WHERE is_deleted = 0` | Boolean only | `SET is_deleted = 0` | Physical `DELETE` |
| Separate `deleted_notes` table | No filter needed | Move row to other table | Move row back | Delete from `deleted_notes` |

`deleted_at` is the most common pattern: one column, minimal schema change, and a built-in audit
timestamp at no extra cost.

---

## Related howtos

- [`article-versioning-api.md`](article-versioning-api.md) — version history for content (audit trail pattern)
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explicit DTO whitelisting to prevent field injection
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomic multi-write operations
