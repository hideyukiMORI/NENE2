---
title: "How-to: Article Relations API"
category: product
tags: [relations, many-to-many, self-referential, inverse, content]
difficulty: intermediate
related: [content-relations, article-versioning-api]
---

# How-to: Article Relations API

> **FT reference**: FT334 (`NENE2-FT/relatedlog`) — Typed article-to-article relations with automatic inverse creation, symmetric and asymmetric relation types, filter-by-type, and embedded relation stubs in GET responses, 17 tests / 40+ assertions PASS.

This guide shows how to model typed relationships between content items — `related`, `sequel`, `prequel`, `reference` — with automatic inverse management so that every relation stays consistent in both directions.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id    INTEGER NOT NULL REFERENCES articles(id),
    related_id    INTEGER NOT NULL REFERENCES articles(id),
    relation_type TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(article_id, related_id, relation_type)
);
```

`UNIQUE(article_id, related_id, relation_type)` prevents duplicate relation edges for the same type. Different types between the same pair are allowed.

## Relation Types & Inverses

| Type submitted | Auto-created inverse |
|---|---|
| `related` | `related` (symmetric) |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference` (symmetric) |

When A→B is `sequel`, the server atomically inserts B→A as `prequel`. Deleting A→B also deletes B→A.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/articles` | Create article |
| `GET`  | `/articles/{id}` | Get article with embedded relations |
| `POST` | `/articles/{id}/relations` | Add a relation |
| `GET`  | `/articles/{id}/relations` | List relations (optional ?type=) |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | Remove a relation (and its inverse) |

## Create Article

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// Missing title
POST /articles  {"body": "No title"}
→ 422

// Missing body
POST /articles  {"title": "No body"}
→ 422
```

## GET Article with Embedded Relations

```php
GET /articles/1
→ 200
{
  "data": {"id": 1, "title": "Intro", ...},
  "relations": [
    {
      "relation": {"relation_type": "sequel"},
      "related":  {"id": 2, "title": "Follow-up"}
    }
  ]
}

// No relations yet
GET /articles/1
→ 200  {"data": {...}, "relations": []}

GET /articles/9999
→ 404
```

## Add a Relation

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "sequel"}
→ 201  {"relation_type": "sequel", "article_id": 1, "related_id": 2}

// Inverse is auto-inserted: article 2 now has a "prequel" relation pointing to 1
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### Symmetric relation

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B also gets a "related" relation to A automatically
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### Error Cases

```php
// Unknown related_id
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// Duplicate (same pair + same type already exists)
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// Self-relation
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// Invalid relation type
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### Multiple Types Between Same Pair

The same pair can have multiple different relation types:

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## List Relations

```php
// All relations
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// Filter by type
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// Unknown article
GET /articles/9999/relations
→ 404
```

## Delete Relation

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// Inverse is also removed automatically
GET /articles/2/relations
→ 200  {"data": []}  // no longer has a "related" back to 1

// Not found
DELETE /articles/1/relations/2?type=related
→ 404

// Missing type query param
DELETE /articles/1/relations/2
→ 422
```

## Implementation — Atomic Inverse Management

```php
private function addRelation(int $articleId, int $relatedId, string $type): void
{
    $this->db->beginTransaction();

    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,   // related, reference → symmetric
    };

    $this->repo->insert($articleId, $relatedId, $type);
    $this->repo->insert($relatedId, $articleId, $inverse);

    $this->db->commit();
}

private function removeRelation(int $articleId, int $relatedId, string $type): void
{
    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,
    };

    $this->db->beginTransaction();
    $this->repo->delete($articleId, $relatedId, $type);
    $this->repo->delete($relatedId, $articleId, $inverse);
    $this->db->commit();
}
```

Wrap both inserts/deletes in a transaction — if one fails, neither is committed.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Insert relation without checking article existence | FK violation or silent 0-row insert; always 404 on unknown IDs |
| No transaction around forward + inverse insert | Partial failure leaves asymmetric data (A→B exists but B→A does not) |
| No `UNIQUE(article_id, related_id, relation_type)` | Duplicate edges inflate list counts |
| Allow self-relations | Cycles in relation traversal; `sequel` of itself has no meaning |
| Hardcode symmetric assumption for all types | `sequel`→`sequel` (wrong) instead of `prequel` |
| Delete only the forward edge | Inverse orphan remains; B still "sees" A as a prequel after A is deleted |
