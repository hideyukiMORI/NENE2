---
title: "How-to: Article Versioning API"
category: product
tags: [versioning, content, snapshot, history, draft]
difficulty: intermediate
related: [content-versioning, document-versioning, content-draft-lifecycle]
---

# How-to: Article Versioning API

> **FT reference**: FT249 (`NENE2-FT/contentvlog`) — Article Versioning API
> **VULN**: FT249 — vulnerability assessment (V-01 through V-10)

Demonstrates an article versioning system where a `current_version` integer column
on the `articles` table tracks the latest version, each update appends to
`article_versions`, and rollback creates a new version from historical content.
Includes a vulnerability assessment of the unauthenticated design.

---

## Routes

| Method | Path                              | Description                                          |
|--------|-----------------------------------|------------------------------------------------------|
| `POST` | `/articles`                       | Create an article (version 1)                        |
| `GET`  | `/articles/{id}`                  | Get an article (current content)                     |
| `PUT`  | `/articles/{id}`                  | Update article (creates new version)                 |
| `GET`  | `/articles/{id}/versions`         | List version history (metadata only)                 |
| `GET`  | `/articles/{id}/versions/{version}` | Get a specific version                             |
| `POST` | `/articles/{id}/rollback`         | Rollback to a version (creates new version)          |

---

## Schema: `current_version` integer column

```sql
CREATE TABLE articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    body            TEXT    NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE article_versions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (article_id, version),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

The `current_version` column stores the version number of the current content.
`UNIQUE(article_id, version)` prevents duplicate version numbers for the same article.

**Comparison with `is_current` flag approach** (see `document-versioning.md`):

| Approach | `current_version` integer | `is_current` flag |
|---|---|---|
| Schema | Column on `articles` table | Column on `versions` table |
| Current version lookup | `SELECT * FROM articles WHERE id = ?` (no JOIN) | `LEFT JOIN ... ON dv.is_current = 1` |
| Version number tracking | Explicit integer on parent row | Implicit from row count or MAX |
| Atomicity | Update article + insert version (2 writes) | UPDATE flag + INSERT (2 writes) |

---

## Create: two-write initialization

Creating an article writes to both tables:

```php
$id = $this->db->insert(
    'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
    [$title, $body, $now, $now],
);
$this->db->insert(
    'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
    [$id, $title, $body, $now],
);
```

Both writes happen without a wrapping transaction. If the second insert fails, the
`articles` row exists but `article_versions` has no corresponding entry — the article
is at version 1 with no history record. Wrap both in `$txManager->transactional()`
for production use.

---

## Update: read-then-increment pattern

```php
public function update(int $id, string $title, string $body, string $now): bool
{
    $article = $this->find($id);
    if ($article === null) {
        return false;
    }
    $nextVersion = (int) $article['current_version'] + 1;

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

The version number is read, incremented in PHP, then written back. Without a
transaction, concurrent updates can produce duplicate version numbers — the
`UNIQUE(article_id, version)` constraint will catch this, but the `UPDATE` to
`articles` may succeed before the `INSERT` to `article_versions` fails, leaving
the article's `current_version` ahead of its history.

---

## Rollback: non-destructive (copy as new version)

```php
public function rollback(int $id, int $version, string $now): bool
{
    $target = $this->findVersion($id, $version);
    if ($target === null) {
        return false;
    }
    $article     = $this->find($id);
    $nextVersion = (int) $article['current_version'] + 1;
    $title       = (string) $target['title'];
    $body        = (string) $target['body'];

    $this->db->insert(
        'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $nextVersion, $now, $id],
    );
    $this->db->insert(
        'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $nextVersion, $title, $body, $now],
    );
    return true;
}
```

Rollback does not delete versions — it copies the content of the target version
as a new (current) version. History is always preserved. If an article is at
version 5 and rolled back to version 2:

```
v1 → v2 → v3 → v4 → v5 → v6 (copy of v2 content)
```

---

## Version list: metadata only (no body)

`GET /articles/{id}/versions` returns version metadata without the full body:

```php
$this->db->fetchAll(
    'SELECT id, article_id, version, title, created_at FROM article_versions
     WHERE article_id = ? ORDER BY version ASC',
    [$articleId],
);
```

`body` is excluded from the list — callers must fetch individual versions with
`GET /articles/{id}/versions/{version}` to get the content. This avoids sending
potentially large content in the list response.

---

## VULN — Vulnerability assessment (FT249)

### V-01 — No authentication: any caller can update or delete any article

**Risk**: All endpoints are unauthenticated.

**Impact**: An attacker can overwrite any article, roll back its content to a previous
version, or enumerate all version history.

**Verdict**: **EXPOSED** — add authentication (API key, JWT, or session). Update/rollback
should require the article's owner to be authenticated.

---

### V-02 — No ownership: any authenticated user can modify any article

**Risk**: Even with authentication, there is no ownership-scoped query. Any authenticated
user can update any other user's article.

**Impact**: Without `WHERE id = ? AND owner_id = ?`, article IDs are enumerable and
modifiable by anyone with a valid token.

**Verdict**: **EXPOSED** — add an `owner_id` column to `articles`. Enforce ownership
with `WHERE id = ? AND owner_id = ?` in all write operations.

---

### V-03 — IDOR: read another user's version history

**Risk**: `GET /articles/{id}/versions` returns all version history for any article ID.

**Impact**: An attacker can enumerate draft content history that the author may not
have intended to make public.

**Verdict**: **EXPOSED** — ownership-scope all reads: only the article owner (or roles
with explicit read permission) should see version history.

---

### V-04 — Race condition on version number increment

**Risk**: `update()` reads `current_version`, increments in PHP, then writes back.
No transaction or row lock wraps the read-write sequence.

**Attack**: Two concurrent `PUT /articles/1` requests both read `current_version = 3`.
Both compute `nextVersion = 4`. One succeeds (inserts version 4); the other fails
the `UNIQUE(article_id, version)` constraint — but the `UPDATE articles` may have
already succeeded, setting `current_version = 4` for both, with only one version
record in history.

**Verdict**: **EXPOSED** — wrap `find` + `UPDATE` + `INSERT` in a DB transaction.
Use `UPDATE articles SET current_version = current_version + 1` for atomic increment.

---

### V-05 — SQL injection via title or body

**Attack**: Embed SQL metacharacters.

```json
{"title": "'; DROP TABLE articles; --", "body": "x"}
```

**Observed**: Values are bound as parameterized `?` placeholders. Injection is stored
as literal text.

**Verdict**: **BLOCKED** — parameterized queries prevent SQL injection.

---

### V-06 — Version enumeration: unbounded history access

**Risk**: `GET /articles/{id}/versions` returns the full version history with no
pagination or limit.

**Impact**: An article with thousands of versions returns all rows in a single response,
causing memory pressure and slow queries.

**Verdict**: **EXPOSED** — add pagination (`LIMIT ? OFFSET ?`) to the version list
endpoint. Consider capping maximum versions per article.

---

### V-07 — Non-transactional two-write operations

**Risk**: Both `create()` and `update()` perform two sequential writes without a
wrapping DB transaction.

**Impact**: If the second write fails (e.g., constraint violation, connection error),
the system is left in an inconsistent state: `articles.current_version` may differ from
the count of `article_versions` rows, or an article may exist with no version record.

**Verdict**: **EXPOSED** — wrap paired writes in `DatabaseTransactionManagerInterface::transactional()`.

---

### V-08 — Rollback to a version of another article

**Attack**: Submit a rollback with a `version` number that exists for a different
article.

```bash
# Article 1 has versions 1-3; Article 2 has version 1
POST /articles/1/rollback  {"version": 1}
```

**Observed**: `findVersion(articleId=1, version=1)` uses `WHERE article_id = ? AND version = ?`
— it only finds versions belonging to article 1. A version that exists for article 2
is not returned.

**Verdict**: **BLOCKED** — version lookup is scoped by `article_id`.

---

### V-09 — Large body: no size limit on article content

**Risk**: `body` accepts arbitrary-length strings with no validation.

**Impact**: Multi-megabyte bodies consume storage and memory on every read.

**Verdict**: **EXPOSED** — add a body length check (e.g., `strlen($body) > 1_000_000 → 422`).
Rely on request-size middleware as the outer limit.

---

### V-10 — Rollback to `version = 0` or negative version

**Attack**: Submit a rollback with version 0 or -1.

```json
{"version": 0}
{"version": -1}
```

**Observed**: `(int) $body['version']` accepts any integer. `findVersion($id, 0)` and
`findVersion($id, -1)` return `null` (no such version) → `404 Not Found`. No version 0
is ever stored (versions start at 1).

**Verdict**: **BLOCKED** — `findVersion` returns `null` for non-existent versions;
no special case is needed.

---

## VULN summary

| # | Vulnerability | Verdict |
|---|---------------|---------|
| V-01 | No authentication on write endpoints | EXPOSED |
| V-02 | No ownership check (any user can modify any article) | EXPOSED |
| V-03 | IDOR on version history | EXPOSED |
| V-04 | Race condition on version number increment | EXPOSED |
| V-05 | SQL injection via title/body | BLOCKED |
| V-06 | Unbounded version list (no pagination) | EXPOSED |
| V-07 | Non-transactional paired writes | EXPOSED |
| V-08 | Rollback to version of another article | BLOCKED |
| V-09 | No body size limit | EXPOSED |
| V-10 | Rollback to version 0 / negative | BLOCKED |

**Critical fixes before production**:
1. **V-01 / V-02 / V-03** — Add authentication and `owner_id` ownership enforcement
2. **V-04 / V-07** — Wrap all multi-write operations in `transactional()`; use atomic version increment
3. **V-06** — Add `LIMIT ? OFFSET ?` pagination to version list
4. **V-09** — Add body size validation

---

## Related howtos

- [`document-versioning.md`](document-versioning.md) — `is_current` flag approach with `DatabaseTransactionManagerInterface`
- [`content-versioning.md`](content-versioning.md) — content versioning with linear version numbers
- [`transactions.md`](transactions.md) — DatabaseTransactionManagerInterface patterns
- [`optimistic-locking.md`](optimistic-locking.md) — race condition prevention with version column + conditional UPDATE
