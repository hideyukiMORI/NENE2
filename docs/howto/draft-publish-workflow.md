---
title: "How-to: Draft → Publish → Archive Workflow"
category: product
tags: [state-machine, draft, publish, archive, content-lifecycle]
difficulty: intermediate
related: [content-scheduling, content-draft-lifecycle, content-versioning]
---

# How-to: Draft → Publish → Archive Workflow

> **FT reference**: FT305 (`NENE2-FT/draftlog`) — Article lifecycle state machine: draft→published→archived one-way transitions, author-only write access, non-authors see only published articles (drafts return 404), cannot edit published articles, published list excludes drafts and archived, 20 tests / 28 assertions PASS.

This guide shows how to implement a content lifecycle where articles start as drafts, are published to become visible, and can be archived to remove them from public listings.

## Schema

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

`CHECK (status IN (...))` ensures only known states are stored. `published_at` and `archived_at` timestamps record when transitions occurred.

## State Machine

```
draft ──(POST /publish)──▶ published ──(POST /archive)──▶ archived
```

| Transition | Precondition | Error if violated |
|---|---|---|
| draft → published | status must be `'draft'` | 422 |
| published → archived | status must be `'published'` | 422 |
| published → draft | ❌ not allowed | — |
| archived → anything | ❌ not allowed | — |

```php
// Publish handler
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}

// Archive handler
if ($article['status'] !== 'published') {
    return $this->responseFactory->create(['error' => 'only published articles can be archived'], 422);
}
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/articles` | `X-User-Id` | Create article (starts as draft) |
| `GET` | `/articles` | — | List published articles only |
| `GET` | `/articles/{id}` | `X-User-Id` | Get article (visibility check) |
| `PUT` | `/articles/{id}` | `X-User-Id` (author) | Update draft (only if draft) |
| `POST` | `/articles/{id}/publish` | `X-User-Id` (author) | Publish |
| `POST` | `/articles/{id}/archive` | `X-User-Id` (author) | Archive |

## New Articles Start as Draft

```php
$id = $this->repo->create($actorId, $title, $body);
return $this->responseFactory->create(['id' => $id, 'status' => 'draft'], 201);
```

The `status` is always `'draft'` on creation regardless of any body field. The client cannot choose the initial status.

## Visibility — Non-Authors See Only Published

```php
// Non-authors can only see published articles
if ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'not found'], 404);
}
```

Unpublished articles (draft or archived) return 404 to non-authors. This prevents:
- Other users reading unpublished drafts
- Revealing whether an article was archived

## Cannot Edit Published Articles

```php
// Update handler — only drafts are editable
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be edited'], 422);
}
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Once published, the article content is frozen. The author must unpublish (which is not supported here) to edit — in this design, publish is a one-way gate.

## List Endpoint — Published Only

```php
// Repository: SELECT WHERE status = 'published' ORDER BY published_at DESC
$articles = $this->repo->listPublished();
```

The list endpoint filters to `status = 'published'` only. Drafts and archived articles never appear in the public listing.

## Author-Only Actions

All write operations (update, publish, archive) check that the actor is the article's author:

```php
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow status in create body | Client starts article as `'published'` bypassing review workflow |
| Return 403 for non-author draft GET | Reveals the article exists; use 404 to hide unpublished content |
| Allow editing published articles | Retroactively changes live content; violates reader trust |
| Allow archive → published transition | Archived articles reappear unexpectedly |
| List drafts in public listing | Unpublished content is exposed before ready |
| No `CHECK (status IN (...))` | Direct DB inserts can set arbitrary status strings |
| archived articles return 200 to non-authors | Tells non-authors that content existed and was archived |
