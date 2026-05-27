# How-to: Slug URL Management with History

> **FT reference**: FT339 (`NENE2-FT/sluglog`) — Auto-generated slugs from titles, collision counter, slug history for old-slug 301 redirects, explicit slug override, vulnerability assessment, 17 tests / 50+ assertions PASS.

This guide shows how to generate clean URL slugs from content titles, handle collisions with sequential suffixes, preserve old slugs in a history table for permanent redirects, and prevent common attack vectors.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    slug       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE slug_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    old_slug   TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/articles` | Create article (auto-slug from title) |
| `PUT`  | `/articles/{id}` | Update article (slug regenerated on title change) |
| `GET`  | `/articles/by-slug/{slug}` | Get by current or old slug |
| `GET`  | `/articles/{id}/slug-history` | List slug history |

## Slug Generation

### `SlugHelper::fromTitle()`

```php
SlugHelper::fromTitle('Hello World')          // → "hello-world"
SlugHelper::fromTitle('PHP 8.4: New Features!') // → "php-8-4-new-features"
SlugHelper::fromTitle('  --Hello--  ')        // → "hello"
SlugHelper::fromTitle('')                     // → "untitled"
SlugHelper::fromTitle('---')                  // → "untitled"
```

Rules:
1. Lowercase everything
2. Replace non-alphanumeric characters with `-`
3. Collapse consecutive hyphens
4. Trim leading/trailing hyphens
5. Return `"untitled"` if result is empty

```php
public static function fromTitle(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'untitled';
}
```

### Collision Resolution

```php
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-2"}
POST /articles  {"title": "Hello", "body": "..."}  → 201  {"slug": "hello-3"}
```

```php
public static function makeUnique(string $base, callable $isTaken): string
{
    if (!$isTaken($base)) {
        return $base;
    }

    $i = 2;
    while ($isTaken("{$base}-{$i}")) {
        $i++;
    }

    return "{$base}-{$i}";
}
```

`$isTaken` is a DB lookup callback: `fn(string $s): bool => (bool) $repo->findBySlug($s)`.

## Create Article

```php
POST /articles
{"title": "My First Post", "body": "Content here."}
→ 201
{
  "id": 1,
  "title": "My First Post",
  "slug": "my-first-post",
  "body": "...",
  "created_at": "..."
}
```

## Update Article

```php
PUT /articles/1
{"title": "New Title", "body": "Updated content."}
→ 200  {"slug": "new-title", ...}
```

When the title changes, the new slug is derived and the old slug is saved to `slug_history`.

```php
// Same title — slug unchanged, no history entry
PUT /articles/1  {"title": "New Title", "body": "Different body."}
→ 200  {"slug": "new-title"}  // same slug

// Explicit slug override
PUT /articles/1  {"title": "New Title", "body": "Body.", "slug": "custom-url-here"}
→ 200  {"slug": "custom-url-here"}

// Collision on update — auto-resolved
// (if "popular" exists, renames to "popular-2")
PUT /articles/2  {"title": "Popular", "body": "Body."}
→ 200  {"slug": "popular-2"}

// Unknown article
PUT /articles/9999  {"title": "X", "body": "Y"}
→ 404
```

## Get by Slug

```php
// Current slug → 200
GET /articles/by-slug/new-title
→ 200  {"id": 1, "slug": "new-title", "title": "New Title", ...}

// Old slug → 301 redirect
GET /articles/by-slug/my-first-post
→ 301
{
  "redirect": true,
  "canonical_slug": "new-title"
}

// Unknown → 404
GET /articles/by-slug/does-not-exist
→ 404
```

301 responses tell crawlers/clients to update their links to the canonical slug.

## Slug History

```php
GET /articles/1/slug-history
→ 200
{
  "current_slug": "new-title",
  "slug_history": [
    {"old_slug": "my-first-post", "created_at": "..."}
  ]
}

// New article — empty history
{"current_slug": "fresh", "slug_history": []}

// Unknown article → 404
GET /articles/9999/slug-history → 404
```

History entries only accumulate when the slug actually changes. Updating body without changing the title leaves history untouched.

---

## Vulnerability Assessment

### V-01 — Path Traversal via Slug ✅ SAFE

**Risk**: Attacker sends `GET /articles/by-slug/../../../etc/passwd` to traverse server directories.
**Finding**: SAFE — Slug lookups are SQL `WHERE slug = ?` with a bound parameter. The path segment is never interpreted as a filesystem path. Routing parses the path before it reaches the controller; `../` in a URL path is canonicalized by the HTTP layer.

---

### V-02 — SQL Injection via Slug in URL ✅ SAFE

**Risk**: `GET /articles/by-slug/' OR '1'='1` leaks all articles.
**Finding**: SAFE — Slug is passed as a bound parameter in `WHERE slug = ?`. SQL injection is impossible regardless of the slug value.

---

### V-03 — Slug Enumeration (Brute-Force Discovery) ⚠️ EXPOSED

**Risk**: Attacker iterates common slugs (`/articles/by-slug/admin`, `/articles/by-slug/secret-doc`) to discover private articles.
**Finding**: EXPOSED — Slugs are predictable derivations of human-readable titles. No rate limiting or authentication is enforced on `GET /articles/by-slug/{slug}`. Mitigation: require authentication for private content; add per-IP rate limiting; consider opaque IDs for sensitive resources.

---

### V-04 — Slug History IDOR ✅ SAFE

**Risk**: Attacker calls `GET /articles/{id}/slug-history` for another user's article to discover past titles.
**Finding**: SAFE — Slug history is public metadata. If articles are public, their history is as well. If articles require authorization, apply the same auth check to the `/slug-history` endpoint consistently.

---

### V-05 — Infinite Redirect Loop via Slug History ✅ SAFE

**Risk**: Article A renames to slug B; article B renames to slug A — `GET /by-slug/a` → redirect to B → redirect to A (infinite loop).
**Finding**: SAFE — The implementation looks up the **current** slug in `articles.slug`, then checks `slug_history` only for old slugs. A 301 response always points to the current canonical. Clients following redirects reach the canonical in one hop.

---

### V-06 — Slug Collision Abuse (Sequential Counter Exhaustion) ⚠️ EXPOSED

**Risk**: Attacker creates thousands of articles titled "popular" to reserve "popular-2" through "popular-9999", then deletes them — or to force expensive counter scanning.
**Finding**: EXPOSED — No rate limiting on article creation. The `makeUnique` counter scan is O(n) DB queries. Mitigation: rate-limit POST /articles per user; cap slug counter at a reasonable limit (e.g. 99); use a random suffix after threshold.

---

### V-07 — Explicit Slug Injection (Overwrite Another Article's Slug) ✅ SAFE

**Risk**: Attacker uses `PUT /articles/2  {"slug": "popular"}` where "popular" belongs to article 1.
**Finding**: SAFE — `articles.slug` has a `UNIQUE` constraint. Attempting to set a slug already claimed by another article triggers a DB constraint violation, translated to 409 Conflict.

---

### V-08 — Unicode/Homograph Slug Attack ⚠️ EXPOSED

**Risk**: Attacker creates an article with a Unicode title that normalizes to the same bytes as an existing ASCII slug (e.g. `café` → `caf-`) to create a visually confusing URL.
**Finding**: EXPOSED — `SlugHelper::fromTitle()` uses `preg_replace('/[^a-z0-9]+/', '-', strtolower($title))`. Non-ASCII characters are replaced by `-`, which may cause unexpected collisions or empty slugs. Mitigation: normalize Unicode to ASCII transliteration (e.g. `iconv`) before slug generation; treat all non-ASCII as `-` after normalization.

---

### V-09 — XSS via Title Stored in Slug ✅ SAFE

**Risk**: Title `<script>alert(1)</script>` produces slug `script-alert-1-script` — safe alphanumeric output.
**Finding**: SAFE — `SlugHelper::fromTitle()` strips all non-alphanumeric characters to `-`. The slug output is always `[a-z0-9-]`, making HTML injection impossible through the slug.

---

### V-10 — Old Slug Lookup Reveals Renamed Content ⚠️ EXPOSED

**Risk**: Article renamed from "secret-plan-v1" to "public-announcement"; attacker uses old slug to discover the original title via the redirect response `canonical_slug`.
**Finding**: EXPOSED — The 301 response exposes the new canonical slug, which may reveal the renamed content. The slug history endpoint also reveals all old names. For sensitive renames, tombstone old slugs without revealing the new location; or use opaque slugs.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | Path traversal via slug | ✅ SAFE |
| V-02 | SQL injection via slug | ✅ SAFE |
| V-03 | Slug enumeration | ⚠️ EXPOSED |
| V-04 | Slug history IDOR | ✅ SAFE |
| V-05 | Infinite redirect loop | ✅ SAFE |
| V-06 | Collision counter exhaustion | ⚠️ EXPOSED |
| V-07 | Explicit slug overwrite | ✅ SAFE |
| V-08 | Unicode homograph attack | ⚠️ EXPOSED |
| V-09 | XSS via title | ✅ SAFE |
| V-10 | Old slug reveals renamed content | ⚠️ EXPOSED |

**6 SAFE, 4 EXPOSED** — Rate-limit article creation; add authentication for private content; normalize Unicode before slug generation; consider tombstone-only slug history for sensitive renames.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Interpolate slug into SQL directly | SQL injection via slug path parameter |
| Hard-delete slug history on article delete | Old URLs return 404 instead of 301; SEO and link rot |
| No `UNIQUE` constraint on `articles.slug` | Concurrent inserts create duplicate slugs |
| Return old slug unchanged on title update | Slug drift — URL no longer reflects content |
| No counter cap in `makeUnique` | Attacker exhausts counter via bulk creation |
| Use `!==` to compare existing slugs | Type coercion surprises; always use `===` for slug comparison |
