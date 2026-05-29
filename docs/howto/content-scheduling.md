---
title: "Content Scheduling — Time-Based Publish with Lifecycle States"
category: infrastructure
tags: [scheduling, state-machine, content, cron, publish]
difficulty: intermediate
related: [draft-publish-workflow, content-versioning, scheduled-publish-article]
---

# Content Scheduling — Time-Based Publish with Lifecycle States

Schedule content to publish at a future datetime using a `publish_at` column,
a status machine (`draft → scheduled → published → archived`), and a
**publish-due trigger** endpoint that a cron job calls to flip due articles.

**Reference implementation:** `FT172 pubschedulelog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Status Lifecycle

```
draft ──┬──► scheduled ──► published ──► archived
        │                               ▲
        └───────────────────────────────┘
        (also: scheduled → draft via unschedule)
```

| From | Allowed transitions |
|---|---|
| `draft` | `scheduled`, `published`, `archived` |
| `scheduled` | `published`, `draft`, `archived` |
| `published` | `archived` |
| `archived` | *(none)* |

---

## Schema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601; set when scheduled; NULL otherwise
    published_at TEXT,    -- set when actually published
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

---

## Endpoints

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/articles` | X-User-Id | Create a draft |
| `GET` | `/articles` | optional | List (`?status=published` is public; other statuses require auth + own articles only) |
| `GET` | `/articles/{id}` | optional | Get one article (published = public, draft/scheduled = owner only) |
| `PUT` | `/articles/{id}` | X-User-Id | Update title/body (draft or scheduled only) |
| `POST` | `/articles/{id}/schedule` | X-User-Id | Set `publish_at` → moves to `scheduled` |
| `POST` | `/articles/{id}/unschedule` | X-User-Id | Cancel scheduling → reverts to `draft` |
| `POST` | `/articles/{id}/publish` | X-User-Id | Publish immediately |
| `POST` | `/articles/{id}/archive` | X-User-Id | Archive |
| `POST` | `/articles/publish-due` | X-Admin-Key | Bulk-publish all due scheduled articles |

---

## Core Patterns

### Status Enum with Transition Guard

```php
enum ArticleStatus: string {
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
```

### Schedule: Future-Only Validation

```php
$ts = strtotime($publishAt);
if ($ts === false || $ts === -1) {
    throw new ArticleScheduleException('publish_at is not a valid datetime.');
}
if ($ts <= strtotime($now)) {
    throw new ArticleScheduleException('publish_at must be in the future.');
}
```

### Publish-Due Trigger (cron-safe, idempotent)

```php
public function publishDue(string $now): array
{
    $rows = $this->db->fetchAll(
        "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
        [ArticleStatus::Scheduled->value, $now],
    );

    $published = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
        $published[] = $id;
    }

    return $published;  // list<int>
}
```

Call this from a cron job every minute. Idempotent: re-running immediately finds
no new due articles since `publish_at` is cleared to `NULL` on publish.

### IDOR Prevention

Draft and scheduled articles are **owner-only** — return 404 (not 403) to
avoid leaking existence:

```php
if ($article->authorId !== $actorId) {
    throw new ArticleNotFoundException($id);  // 404, not 403
}
```

### Admin Key — Timing-Safe Comparison

```php
if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
    return $this->responseFactory->create(['error' => 'unauthorized'], 401);
}
```

Never use `!==` for secret comparisons — use `hash_equals()` to prevent
timing attacks.

---

## Security Notes

| Risk | Mitigation |
|---|---|
| Past `publish_at` injection | `strtotime($publishAt) <= strtotime($now)` → 422 |
| Cross-user state mutation | Ownership check before every transition; 404 not 403 |
| Author ID injection via body | `authorId` taken from `X-User-Id` header only |
| Status injection via body | `status` field in PUT body is ignored; transitions via dedicated action endpoints |
| Timing attack on admin key | `hash_equals()` instead of `!==` |
| Enumeration of unpublished articles | Public listing always filters by `status = published`; non-published requires auth + own articles only |
| Edit after publish | PUT rejects non-draft/scheduled articles with 422 |
| Double archive | Transition guard returns 409 for invalid transitions |

---

## Cron Integration

```bash
# /etc/cron.d/publish-due
* * * * * www-data curl -s -X POST https://api.example.com/articles/publish-due \
  -H "X-Admin-Key: $ADMIN_KEY"
```

For higher-volume workloads, move to a job queue (see
[job-queue.md](./job-queue.md)) and have the queue worker call `publishDue()`.

---

## See Also

- [Content Draft Lifecycle](./content-draft-lifecycle.md) — draft/active/archived without scheduling
- [Job Queue](./job-queue.md) — background processing for high-volume publish triggers
- [Soft Delete](./soft-delete.md) — complement to archiving
- [Audit Trail](./audit-trail.md) — recording who published what and when
