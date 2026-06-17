---
title: "How-to: Multilingual Content API"
category: product
tags: [i18n, multilingual, localization, content]
difficulty: intermediate
related: [content-versioning, content-draft-lifecycle]
---

# How-to: Multilingual Content API

> **FT reference**: FT232 (`NENE2-FT/i18nlog`) — Multilingual Content API
> **ATK**: FT232 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a multilingual article API where content is stored as locale-keyed
translations separate from the article record itself. Supports BCP 47 locale
validation, upsert semantics for translations, locale fallback for content
negotiation, and publish/draft state per article.

---

## Routes

| Method | Path                                    | Description                                   |
|--------|-----------------------------------------|-----------------------------------------------|
| `POST` | `/articles`                             | Create an article (draft or published)        |
| `GET`  | `/articles`                             | List published articles (optional `?locale=`) |
| `GET`  | `/articles/{id}`                        | Get a single article (optional `?locale=`)    |
| `PUT`  | `/articles/{id}/translations/{locale}`  | Create or update a translation (upsert)       |

---

## Creating an article

```json
{
  "default_locale": "en",
  "published": false
}
```

`default_locale` sets the fallback language when a requested locale is unavailable.
`published` controls list visibility — only published articles appear in `GET /articles`.

```php
$defaultLocale = isset($body['default_locale']) && is_string($body['default_locale'])
    ? trim($body['default_locale']) : 'en';
$published = isset($body['published']) && $body['published'] === true;

if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $defaultLocale)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'default_locale', 'code' => 'invalid',
                      'message' => 'default_locale must be a BCP 47 language tag (e.g. en, ja, fr-FR).']],
    ]);
}
```

`$body['published'] === true` (strict equality) means JSON `true` sets the flag — any
other value (string `"true"`, integer `1`, omitted) leaves the article as a draft.

---

## BCP 47 locale validation

```php
preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)
```

Accepts:
- Two lowercase letters: `en`, `ja`, `fr`, `de`
- Two lowercase + hyphen + two uppercase: `fr-FR`, `zh-TW`, `pt-BR`

Rejects:
- Wrong case: `EN`, `en_US`, `En`
- Underscores: `en_US` (BCP 47 uses hyphens)
- Subtags beyond region: `zh-Hant-TW`
- Path traversal: `../../etc/passwd`
- Empty string: `""`

This regex is sufficient for the common `language` and `language-REGION` forms. For
full BCP 47 support (script codes, variant tags) a dedicated library is needed.

---

## Upserting a translation

`PUT /articles/{id}/translations/{locale}` creates the translation if it doesn't exist
or updates it if it does — idempotent with last-write-wins semantics:

```php
public function upsertTranslation(int $articleId, string $locale, string $title, string $body, string $now): Translation
{
    $existing = $this->executor->fetchAll(
        'SELECT * FROM article_translations WHERE article_id = ? AND locale = ?',
        [$articleId, $locale],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE article_translations SET title = ?, body = ?, updated_at = ? WHERE article_id = ? AND locale = ?',
            [$title, $body, $now, $articleId, $locale],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO article_translations (article_id, locale, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$articleId, $locale, $title, $body, $now, $now],
        );
    }
    // ... fetch and return the row
}
```

The `UNIQUE(article_id, locale)` constraint in the schema acts as a backstop; the
application-level SELECT-then-INSERT/UPDATE avoids silent conflict resolution and
enables explicit return of the persisted row.

Body validation rejects empty title or body:

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
$text  = isset($body['body'])  && is_string($body['body'])  ? trim($body['body'])  : '';

$errors = [];
if ($title === '') {
    $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'title is required.'];
}
if ($text === '') {
    $errors[] = ['field' => 'body', 'code' => 'required', 'message' => 'body is required.'];
}
```

`trim()` before the empty check ensures whitespace-only strings also fail validation.

---

## Locale fallback for content negotiation

When the caller passes `?locale=fr`, the `Article` entity looks up the requested
locale and falls back to `default_locale` if no translation exists:

```php
public function getTranslationWithFallback(string $locale): ?Translation
{
    return $this->getTranslation($locale)
        ?? $this->getTranslation($this->defaultLocale);
}

public function toArray(?string $locale = null): array
{
    $translation = $locale !== null
        ? $this->getTranslationWithFallback($locale)
        : null;

    return [
        'id'             => $this->id,
        'default_locale' => $this->defaultLocale,
        'published'      => $this->published,
        'title'          => $translation?->title,    // null if no translation stored
        'body'           => $translation?->body,
        'locale'         => $translation?->locale,   // indicates which locale was served
        'translations'   => array_map(fn (Translation $t) => $t->toArray(), $this->translations),
        'created_at'     => $this->createdAt,
        'updated_at'     => $this->updatedAt,
    ];
}
```

The `locale` field in the response tells the caller which locale was actually served —
useful when fallback occurred (`?locale=zh` → article serves `en` translation because
no Chinese translation exists yet).

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS articles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    default_locale TEXT    NOT NULL DEFAULT 'en',
    published      INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL,
    updated_at     TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS article_translations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    locale     TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(article_id, locale)
);
```

Key design choices:
- `published` is stored as `INTEGER` (SQLite boolean: 0/1); PHP reads it via `(bool) $row['published']`.
- `UNIQUE(article_id, locale)` enforces at most one translation per locale per article.
- No language validation in the DB — the application layer enforces BCP 47 format.
- `article_translations.body` is plain text; JSON API callers are responsible for sanitising before rendering in HTML.

---

## ATK — Cracker-mindset attack test (FT232)

### ATK-01 — No authentication on any endpoint

**Attack**: Create or modify articles without any credentials.

```bash
curl -s -X POST http://localhost:8200/articles \
  -H 'Content-Type: application/json' \
  -d '{"default_locale":"en","published":true}'
```

**Observed**: `201 Created` — no token required. Any caller can create, translate, or
publish articles.

**Verdict**: **EXPOSED** (by design for FT232 demo). Add authentication and authorisation
for production. Gate `POST /articles` and `PUT .../translations/{locale}` behind a
writer or admin role.

---

### ATK-02 — Path traversal in locale path parameter

**Attack**: Use path-traversal or shell-metacharacter strings as the `{locale}` path
parameter.

```
PUT /articles/1/translations/../../etc/passwd
PUT /articles/1/translations/../admin
PUT /articles/1/translations/%2F%2Fetc
```

**Observed**: The BCP 47 regex `/^[a-z]{2}(-[A-Z]{2})?$/` rejects all of these — none
match two lowercase letters (optionally followed by a hyphen and two uppercase letters).
Response: `422 Unprocessable Entity`.

**Verdict**: **BLOCKED** — strict regex anchored with `^` and `$` rejects traversal sequences.

---

### ATK-03 — SQL injection via locale path parameter

**Attack**: Embed SQL metacharacters in the `{locale}` value.

```
PUT /articles/1/translations/en'; DROP TABLE articles; --
PUT /articles/1/translations/en" OR "1"="1
```

**Observed**:
1. The BCP 47 regex immediately rejects these strings → `422` before any SQL runs.
2. Even if the regex were bypassed, the locale is passed as a parameterised `?` value — no string concatenation with SQL.

**Verdict**: **BLOCKED** — dual layer: regex allowlist + parameterised queries.

---

### ATK-04 — IDOR: translate another user's article

**Attack**: Write a translation for an article the attacker did not create.

```bash
# Attacker knows article ID 1 was created by another user
curl -s -X PUT http://localhost:8200/articles/1/translations/fr \
  -H 'Content-Type: application/json' \
  -d '{"title":"Hacked","body":"Attacker content"}'
```

**Observed**: `200 OK` — translation is accepted and overwrites any existing French
translation. No ownership check exists.

**Verdict**: **EXPOSED** — no ownership model. Add a `created_by` column and compare
against the authenticated caller before allowing writes.

---

### ATK-05 — Whitespace-only title or body

**Attack**: Send a title or body that is blank after trimming.

```json
{"title": "   ", "body": "\t\n"}
```

**Observed**: `trim()` reduces both to empty strings. Both fields are added to `$errors`.
Response: `422 Unprocessable Entity` with structured field errors.

**Verdict**: **BLOCKED** — `trim()` before empty-string check handles whitespace-only input.

---

### ATK-06 — XSS payload in title or body

**Attack**: Store a script tag in a translation field.

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**Observed**: Content is stored as-is and returned verbatim in JSON. The API itself
does not HTML-encode output — it is a JSON API, not an HTML renderer.

**Verdict**: **ACCEPTED BY DESIGN** — JSON APIs return raw content; the rendering
layer (browser, mobile app) is responsible for HTML escaping. Document this clearly in
the API spec so consumers do not render untrusted content without sanitisation.

---

### ATK-07 — Unbounded title or body length

**Attack**: Send a multi-megabyte title or body.

```python
{"title": "A" * 1_000_000, "body": "B" * 5_000_000}
```

**Observed**: No length limit is enforced — very large payloads are stored and returned.
Memory and I/O usage scale with payload size. SQLite `TEXT` has no practical size limit.

**Verdict**: **EXPOSED** — add a `maxlength` check:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
if (mb_strlen($text) > 50000) {
    $errors[] = ['field' => 'body', 'code' => 'too_long', 'message' => 'body must not exceed 50 000 characters.'];
}
```
Also apply a request-size middleware limit to cap total body bytes before parsing.

---

### ATK-08 — BCP 47 case and separator bypass

**Attack**: Try variants that are semantically similar but syntactically wrong.

```
PUT /articles/1/translations/EN        → uppercase language code
PUT /articles/1/translations/en_US     → underscore separator (POSIX style)
PUT /articles/1/translations/en-us     → lowercase region
PUT /articles/1/translations/EN-us     → mixed case
PUT /articles/1/translations/fra       → three-letter ISO 639-2 code
```

**Observed**: All rejected by `/^[a-z]{2}(-[A-Z]{2})?$/`:
- `EN` — fails `[a-z]`
- `en_US` — `_` fails `(-[A-Z]{2})?`
- `en-us` — `us` fails `[A-Z]`
- `fra` — three chars fail `{2}` exactly

**Verdict**: **BLOCKED** — the regex is precise; only exact BCP 47 `ll` or `ll-RR` forms pass.

---

### ATK-09 — Translation for non-existent article

**Attack**: Target an article ID that does not exist.

```bash
curl -s -X PUT http://localhost:8200/articles/99999/translations/en \
  -H 'Content-Type: application/json' \
  -d '{"title":"Ghost","body":"Body"}'
```

**Observed**: `findById(99999)` returns `null`. The handler returns `404 Not Found`
before processing the body.

**Verdict**: **BLOCKED** — article existence is verified before translation is written.

---

### ATK-10 — Publish manipulation without auth

**Attack**: Create an article as published to bypass draft review.

```json
{"default_locale": "en", "published": true}
```

**Observed**: `201 Created` — `published: true` is accepted immediately. No draft review
or approval gate exists; any caller can publish.

**Verdict**: **EXPOSED** (same root as ATK-01). A publish action should require at
minimum a writer role. Separate the `published` flag from the create payload — require
an explicit `POST /articles/{id}/publish` action guarded by authorisation.

---

### ATK-11 — `?locale=` with unknown locale falls back silently

**Attack**: Request an article with a locale that has no translation stored.

```
GET /articles/1?locale=zh-TW
```

**Observed**: `getTranslationWithFallback('zh-TW')` finds no Chinese translation and
falls back to `default_locale` (e.g. `en`). The `locale` field in the response shows
`en` — indicating that fallback occurred. No 404 or error is returned.

**Verdict**: **ACCEPTED BY DESIGN** — silent fallback is correct for content delivery.
Callers can detect fallback by comparing the requested locale against `locale` in the
response. If strict locale enforcement is needed, add a `?strict=1` parameter.

---

### ATK-12 — Non-numeric article ID

**Attack**: Pass a string or float as the article ID.

```
GET /articles/abc
GET /articles/1.5
GET /articles/0x10
```

**Observed**:
- `GET /articles/abc` → Router matches the `{id}` parameter; `(int) 'abc'` = `0`.
  `findById(0)` returns `null` → `404 Not Found`.
- `GET /articles/1.5` → `(int) '1.5'` = `1`. If article 1 exists, it is returned.
  This is a silent truncation, not an error.

**Verdict**: **PARTIALLY BLOCKED** — non-numeric strings resolve to 0 and return 404.
Floats are silently truncated. For strict validation, add:
```php
if (!ctype_digit((string) ($params['id'] ?? ''))) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'id', 'code' => 'invalid', 'message' => 'id must be a positive integer.']],
    ]);
}
```

---

## ATK summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| ATK-01 | No authentication | EXPOSED |
| ATK-02 | Path traversal in locale | BLOCKED |
| ATK-03 | SQL injection via locale | BLOCKED |
| ATK-04 | IDOR: translate another article | EXPOSED |
| ATK-05 | Whitespace-only title/body | BLOCKED |
| ATK-06 | XSS in title/body | ACCEPTED BY DESIGN |
| ATK-07 | Unbounded title/body length | EXPOSED |
| ATK-08 | BCP 47 case/separator bypass | BLOCKED |
| ATK-09 | Translation for non-existent article | BLOCKED |
| ATK-10 | Publish without auth | EXPOSED |
| ATK-11 | Unknown `?locale=` silently falls back | ACCEPTED BY DESIGN |
| ATK-12 | Non-numeric article ID | PARTIALLY BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01 / ATK-04 / ATK-10** — Add authentication, ownership checks, and a separate publish action
2. **ATK-07** — Add title and body length limits
3. **ATK-12** — Add `ctype_digit()` guard for ID parameters

---

## Related howtos

- [`approval-workflow.md`](approval-workflow.md) — state machine for content review before publish
- [`bulk-status-update.md`](bulk-status-update.md) — bulk mutation patterns with partial success
- [`media-watchlist.md`](media-watchlist.md) — enum-backed status and optional nullable fields
