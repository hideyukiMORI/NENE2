---
title: "How-to: Scheduled Publish Article"
category: product
tags: [content-scheduling, state-machine, draft, publish, lifecycle]
difficulty: intermediate
related: [content-draft-lifecycle, state-machine-audit-log, content-scheduling]
---

# How-to: Scheduled Publish Article

> **FT reference**: FT330 (`NENE2-FT/pubschedulelog`) — Article draft/schedule/publish/archive lifecycle, owner-only draft access, public published articles, scheduled publish trigger, 34 tests / 95 assertions PASS.

This guide shows how to build an article management system with deferred publication: authors write drafts, schedule them for a future time, and a background job (or API call) transitions them to published.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601, NULL unless scheduled
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Status Transitions

```
draft ──publish──► published ──archive──► archived
  │
  └──schedule──► scheduled ──(time passes)──► published
  │                  │
  │               unschedule
  │                  │
  └──────────────────┘
```

Allowed transitions only — invalid transitions return 409.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST`  | `/articles` | Create draft (`X-User-Id` required) |
| `GET`   | `/articles/{id}` | Get (draft: owner only; published: public) |
| `PUT`   | `/articles/{id}` | Update draft (`X-User-Id` required) |
| `POST`  | `/articles/{id}/publish` | Publish immediately |
| `POST`  | `/articles/{id}/schedule` | Schedule for future time |
| `POST`  | `/articles/{id}/unschedule` | Return to draft |
| `POST`  | `/articles/{id}/archive` | Archive published article |
| `GET`   | `/articles` | List (with `?status=` filter) |
| `POST`  | `/publish-due` | Trigger scheduled articles past publish_at |

## Create Draft

```php
POST /articles  X-User-Id: 1
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// No auth → 401
```

## Visibility Rules

```php
// Draft: owner only
GET /articles/1  X-User-Id: 1  → 200   // author sees own draft
GET /articles/1  X-User-Id: 2  → 404   // other user cannot see draft
GET /articles/1               → 404   // no auth, draft hidden

// Published: anyone
GET /articles/1               → 200   // public
```

## Publish & Archive

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// Can't archive a draft
POST /articles/1/archive  X-User-Id: 1  → 409
```

## Schedule

```php
// Schedule for 1 hour from now
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// Past time → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// Unschedule → back to draft
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## Trigger Scheduled Articles

A cron job or admin endpoint transitions all scheduled articles with `publish_at <= now`:

```php
POST /publish-due
→ 200  {"published_count": 3}
```

## List Articles

```php
GET /articles?status=published      → 200  // public, no auth needed
GET /articles?status=draft  X-User-Id: 1  → 200  // only own drafts
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Show draft to unauthenticated user | Leaks unpublished content |
| Allow scheduling in the past | Article would publish "immediately" via the trigger job, bypassing review |
| Use wall-clock now() in test for schedule trigger | Tests become time-dependent; use force-insert with a past `publish_at` in tests |
| Hard-delete on archive | Lose audit trail; use status field |
| Allow transition from archived → published | Brings back removed content; require explicit re-publish |
