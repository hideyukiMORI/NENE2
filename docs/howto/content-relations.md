---
title: "Content Relations — Typed M:N Self-Referential Links"
category: product
tags: [relations, self-referential, many-to-many, inverse, typed-links]
difficulty: intermediate
related: [article-relations-api, content-collection]
---

# Content Relations — Typed M:N Self-Referential Links

Link articles (or any resource) to each other using a **join table with a
`relation_type` column**. Support asymmetric types (sequel ↔ prequel) with
automatic inverse insertion, and symmetric types (related, reference) with
the same inverse logic.

**Reference implementation:** `FT173 relatedlog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## When to Use This Pattern

| Use this when… | Consider alternatives when… |
|---|---|
| Resources link to each other with typed edges | You only need untyped "related" links |
| Asymmetric edges are needed (A is a sequel of B) | A simple tagging system is sufficient |
| Bidirectional queries must stay fast | Graph traversal across many hops is required |
| Relation type drives UI behavior ("See sequels") | — |

---

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
    article_id    INTEGER NOT NULL,
    related_id    INTEGER NOT NULL,
    relation_type TEXT    NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at    TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)      -- self-relation prevented at DB level
);
```

### Design Notes

- The `UNIQUE (article_id, related_id, relation_type)` constraint prevents
  duplicate edges of the same type. The same pair can have **multiple** types
  (e.g., A → B as both `related` and `reference`).
- `CHECK (article_id != related_id)` prevents self-loops at the database level.
- **Both directions are stored**: adding `A → B (sequel)` also inserts `B → A (prequel)`.
  This makes per-article queries trivial (`WHERE article_id = ?`) with no joins.

---

## Relation Types

```php
enum RelationType: string
{
    case Related   = 'related';    // symmetric: A related B ↔ B related A
    case Sequel    = 'sequel';     // asymmetric: A sequel→B ↔ B prequel→A
    case Prequel   = 'prequel';    // asymmetric: inverse of sequel
    case Reference = 'reference';  // symmetric: bidirectional citation

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel  => self::Prequel,
            self::Prequel => self::Sequel,
            default       => $this,  // related, reference are self-inverse
        };
    }
}
```

---

## Core Operation: Add Relation with Automatic Inverse

```php
public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
{
    // 1. Validate both articles exist
    // 2. Check for duplicate (UNIQUE constraint would also catch this)
    $existing = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    if ($existing !== null) {
        throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
    }

    // 3. Insert forward relation
    $id = $this->db->insert(
        'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
        [$articleId, $relatedId, $type->value, $now],
    );

    // 4. Insert inverse (if not already there)
    $inverse = $type->inverse();
    $inverseExists = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    if ($inverseExists === null) {
        $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$relatedId, $articleId, $inverse->value, $now],
        );
    }

    return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
}
```

### Remove Relation (cascade inverse)

```php
public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
{
    $deleted = $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    // Remove inverse
    $inverse = $type->inverse();
    $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    return $deleted > 0;
}
```

---

## Endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/articles` | Create an article |
| `GET` | `/articles/{id}` | Get article with embedded related stubs |
| `POST` | `/articles/{id}/relations` | Add a relation (+ auto-inserts inverse) |
| `GET` | `/articles/{id}/relations` | List relations (`?type=sequel` to filter) |
| `DELETE` | `/articles/{id}/relations/{relatedId}` | Remove relation (`?type=sequel` required) |

---

## Response Shapes

### GET /articles/{id} — with embedded relations

```json
{
  "data": { "id": 1, "title": "Part 1", ... },
  "relations": [
    {
      "relation": { "id": 1, "article_id": 1, "related_id": 2, "relation_type": "sequel", ... },
      "related":  { "id": 2, "title": "Part 2", ... }
    }
  ]
}
```

### POST /articles/{id}/relations — request

```json
{
  "related_id": 2,
  "relation_type": "sequel"
}
```

### DELETE /articles/{id}/relations/{relatedId}

```
DELETE /articles/1/relations/2?type=sequel
```

The `type` query param is **required** — a pair can have multiple relation types
simultaneously, so the type disambiguates which edge to remove.

---

## Domain Layer Structure

```
src/Article/
├── Article.php
├── ArticleRelation.php
├── ArticleRepository.php       # addRelation / removeRelation / listRelations / findWithRelations
├── RelationType.php            # enum with inverse()
├── ArticleNotFoundException.php
└── RelationAlreadyExistsException.php
```

---

## Edge Cases

| Scenario | Behavior |
|---|---|
| Self-relation (`article_id == related_id`) | 422 — checked in handler before DB |
| Duplicate type between same pair | 409 Conflict |
| Same pair with different type | 201 — valid, stored as separate rows |
| Remove non-existent relation | 404 |
| Remove without `type` param | 422 |
| Missing articles | 404 for each invalid ID |

---

## See Also

- [Tagging System (M:N)](./tagging-system.md) — resource-to-tag M:N without typed edges
- [Threaded Comments](./threaded-comments.md) — self-referential `parent_id`
- [Hierarchical Data](./hierarchical-data.md) — materialized path tree
- [User Follow System](./user-follow-system.md) — directed M:N between users
