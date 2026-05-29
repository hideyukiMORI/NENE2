---
title: "Slug Management — Unique URL Slugs with Collision Resolution and History"
category: product
tags: [slug, url, collision-resolution, redirect, history]
difficulty: intermediate
related: [slug-url-history, article-versioning-api, content-versioning]
---

# Slug Management — Unique URL Slugs with Collision Resolution and History

Generate URL-safe slugs from titles, resolve collisions automatically, and keep
a **slug history table** so that old slugs redirect to the canonical URL without
breaking inbound links.

**Reference implementation:** `FT174 sluglog` in
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,   -- current canonical slug
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

-- Old slugs kept for redirect support
CREATE TABLE slug_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL,
    old_slug    TEXT    NOT NULL UNIQUE,  -- redirect source; UNIQUE prevents duplicates
    replaced_at TEXT    NOT NULL,
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

---

## Slug Generation

```php
final class SlugHelper
{
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * @param callable(string): bool $exists  Returns true if slug is taken.
     */
    public static function makeUnique(string $base, callable $exists): string
    {
        if (!$exists($base)) {
            return $base;
        }
        $counter = 2;
        while ($exists("{$base}-{$counter}")) {
            $counter++;
        }
        return "{$base}-{$counter}";
    }
}
```

### Uniqueness Check — Include Both Tables

When checking whether a slug is "taken", check **both** `articles.slug` and
`slug_history.old_slug`. Otherwise a new article could claim a slug that is
still in active use as a redirect source:

```php
private function slugExists(string $slug): bool
{
    return $this->db->fetchOne('SELECT id FROM articles WHERE slug = ?', [$slug]) !== null
        || $this->db->fetchOne('SELECT id FROM slug_history WHERE old_slug = ?', [$slug]) !== null;
}
```

---

## Slug Lookup with Redirect Hint

```php
public function findBySlugWithRedirect(string $slug): ?array
{
    // 1. Check current slug column (200 OK)
    $article = $this->findBySlug($slug);
    if ($article !== null) {
        return ['found' => $article, 'redirect' => false];
    }

    // 2. Check slug history (301 Redirect hint)
    $row = $this->db->fetchOne(
        'SELECT article_id FROM slug_history WHERE old_slug = ?', [$slug],
    );
    if ($row === null) {
        return null;  // 404
    }

    $article = $this->findById((int) $row['article_id']);
    return $article !== null ? ['found' => $article, 'redirect' => true] : null;
}
```

The handler then returns HTTP 301 with `canonical_slug` and `data`:

```json
// GET /articles/by-slug/old-title  →  301
{
  "redirect": true,
  "canonical_slug": "new-title",
  "data": { "id": 1, "slug": "new-title", ... }
}
```

---

## Slug Update — Record History

When an article is renamed, move the old slug to `slug_history`:

```php
if ($newSlug !== $article->slug) {
    // Only insert if not already in history (idempotent)
    $alreadyIn = $this->db->fetchOne(
        'SELECT id FROM slug_history WHERE old_slug = ?', [$article->slug],
    );
    if ($alreadyIn === null) {
        $this->db->insert(
            'INSERT INTO slug_history (article_id, old_slug, replaced_at) VALUES (?, ?, ?)',
            [$id, $article->slug, $now],
        );
    }
}
```

### Collision Handling on Update

When computing the new slug for an updated article, exclude the article's own
**current** slug from the "exists" check — otherwise it would unnecessarily
increment to `-2`:

```php
$newSlug = SlugHelper::makeUnique(
    $newSlugBase,
    fn (string $s): bool => $s !== $article->slug && $this->slugExists($s),
);
```

---

## Endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/articles` | Create article — slug auto-derived from title |
| `GET` | `/articles/{id}` | Get by numeric ID |
| `GET` | `/articles/by-slug/{slug}` | Get by slug (200 current / 301 historical / 404) |
| `PUT` | `/articles/{id}` | Update title/body/slug; old slug → history |
| `GET` | `/articles/{id}/slug-history` | List historical slugs |

---

## Collision Scenarios

| Scenario | Result |
|---|---|
| First "Hello World" | `hello-world` |
| Second "Hello World" | `hello-world-2` |
| Third "Hello World" | `hello-world-3` |
| Article renamed from `hello` to an already-taken slug | `taken-slug-2` |
| Same title, no change to slug | No history entry, slug unchanged |
| Old slug matches a history entry | 301 redirect response |

---

## Domain Layer Structure

```
src/Article/
├── Article.php
├── ArticleRepository.php   # create / findBySlug / findBySlugWithRedirect / update / slugHistory
├── SlugHelper.php          # fromTitle() + makeUnique()
└── ArticleNotFoundException.php
```

---

## See Also

- [Soft Delete](./soft-delete.md) — combining slug history with soft-deleted records
- [Content Versioning](./content-versioning.md) — version history alongside slug history
- [Content Draft Lifecycle](./content-draft-lifecycle.md) — slug behavior across draft states
