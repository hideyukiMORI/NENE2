# Field Trial 69 — i18n Multi-Language Content (i18nlog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/i18nlog/`
**Theme**: Multi-language article content with locale-specific translations, fallback to default locale, and locale query parameter for all list/get endpoints.

---

## What was built

A multi-language CMS article API where articles can have translations in multiple locales.

### Domain

- `Article` — has `default_locale`, `published` flag, and a list of `Translation` objects
- `Translation` — per-locale content: `locale`, `title`, `body`
- `Article::getTranslationWithFallback(locale)` — returns requested locale or falls back to `default_locale`

### Schema

```sql
CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    default_locale TEXT NOT NULL DEFAULT 'en',
    published INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS article_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES articles(id),
    locale TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(article_id, locale)
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/articles` | Create article (default_locale, published) |
| GET | `/articles?locale=` | List published articles with optional locale for translation display |
| GET | `/articles/{id}?locale=` | Get article with optional locale (fallback to default) |
| PUT | `/articles/{id}/translations/{locale}` | Upsert translation (title + body required) |

### Key design decisions

**Two path parameters on one route**: `PUT /articles/{id}/translations/{locale}` uses two `{param}` segments. NENE2 router handles this cleanly.

**Upsert pattern**: `SqliteArticleRepository::upsertTranslation()` fetches existing row → UPDATE if found, INSERT if not. This preserves the original `created_at` on update.

**Locale fallback in the domain object**: `Article::getTranslationWithFallback()` implemented as a pure PHP method — no framework-level i18n support needed:

```php
public function getTranslationWithFallback(string $locale): ?Translation
{
    return $this->getTranslation($locale)
        ?? $this->getTranslation($this->defaultLocale);
}
```

**Null title when no locale param**: `GET /articles/{id}` without `?locale=` returns the full `translations` array but `title`/`body`/`locale` fields are `null`. This lets consumers choose their own locale client-side.

**BCP 47 locale validation**: `/^[a-z]{2}(-[A-Z]{2})?$/` — accepts `en`, `ja`, `fr-FR`. Applied at both POST /articles (default_locale) and PUT .../translations/{locale}.

### Test results

```
OK (14 tests, 27 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

No frictions encountered. NENE2 v1.5.22 handled all i18n patterns cleanly:

- Multiple path parameters per route: ✅
- BCP 47 validation with `preg_match`: ✅ (straightforward, no helper needed)
- Optional `?locale=` query param with fallback: ✅ (domain-layer pattern, no framework involvement)
- Upsert translation with SELECT → UPDATE/INSERT: ✅

---

## Summary

The i18n content pattern is well-supported by NENE2 v1.5.22. No framework changes needed. The locale fallback logic lives cleanly in the `Article` domain object, keeping the framework agnostic to i18n concerns.
