# How to Build a Content Draft Lifecycle (Draft → Published → Archived) with NENE2

This guide walks through building an article management system with a draft/publish/archive state machine, where only the author can transition states and only published articles are visible to readers.

**Field Trial**: FT142  
**NENE2 version**: ^1.5  
**Covered topics**: status machine with enum, transition guards, author ownership check, status-filtered public list, same-second sort stability

---

## What we're building

- `POST /articles` — create an article (always starts as `draft`)
- `GET /articles` — list published articles only
- `GET /articles/{id}` — get article (author sees any status; others see only `published`)
- `PUT /articles/{id}` — edit article (draft only, author only)
- `POST /articles/{id}/publish` — transition `draft → published` (author only)
- `POST /articles/{id}/archive` — transition `published → archived` (author only)

---

## Database schema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`published_at` and `archived_at` are nullable — they are set only on the corresponding transition.

---

## ArticleStatus enum with transition guards

```php
enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canPublish(): bool
    {
        return $this === self::Draft;
    }

    public function canArchive(): bool
    {
        return $this === self::Published;
    }
}
```

The handler reads the current status, calls the guard method, and returns 422 if the transition is invalid:

```php
$status = ArticleStatus::tryFrom($article['status']) ?? ArticleStatus::Draft;

if (!$status->canPublish()) {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}
```

Valid transitions:
- `draft → published` (via publish)
- `published → archived` (via archive)
- There is no transition back to draft.

---

## Author visibility — draft hidden from others

Non-authors cannot read drafts. Return 404 (not 403) to avoid leaking that the article exists:

```php
if ($article['status'] !== 'published' && $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'article not found'], 404);
}
```

Returning 403 would confirm the article exists. 404 is the correct choice for content that is not yet public.

---

## Same-second sort stability

When multiple articles are published within the same second, `ORDER BY published_at DESC` alone gives non-deterministic order. Add `id DESC` as a tiebreaker:

```sql
SELECT ... FROM articles WHERE status = 'published' ORDER BY published_at DESC, id DESC
```

Higher `id` means created later, so this effectively sorts by insertion order within the same second.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Returning 403 for non-author draft reads | Return 404 — prevents content existence leakage |
| Allowing `published → draft` re-open | `canEdit()` returns false unless `Draft`; no "unpublish" endpoint |
| Publishing an already-published article | `canPublish()` returns false for `Published` → 422 |
| Archiving a draft | `canArchive()` returns false unless `Published` → 422 |
| Non-deterministic list order at same timestamp | Add `id DESC` as secondary sort |
