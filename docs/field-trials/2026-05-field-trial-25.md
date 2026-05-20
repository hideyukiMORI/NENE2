# Field Trial 25 — URL Bookmark API (linklog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/linklog/`
**NENE2 version**: 1.5.8
**Theme**: Multi-value query parameter parsing, `DomainExceptionHandlerInterface` with multiple handlers, CSV tag storage, delete with `LinkNotFoundException`

## What was built

A URL bookmark API with:

- `GET /links` — list with full-text search (`?search=`), tag filter (`?tags=php,lang`), pagination
- `POST /links` — create with URL uniqueness enforcement → 409
- `GET /links/{id}` — fetch by ID → 404 via `DomainExceptionHandlerInterface`
- `DELETE /links/{id}` — delete by ID → 404 via `DomainExceptionHandlerInterface`

Tags stored as CSV in a single SQLite TEXT column. Full-text search covers title and URL fields.

26/26 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. PHPDoc required on all `array` parameters — no inference from interface (Medium)

**Description**: PHPStan level 8 requires explicit `list<string>` annotations on every `array`-typed parameter, even when the interface already declares them. Both the interface and implementation must be annotated.

**Severity**: Medium

**Reproduction**:
```php
// LinkRepositoryInterface.php — interface declares list<string>|null $tags
// SqliteLinkRepository.php — still needs its own @param annotation
public function count(?string $search = null, ?array $tags = null): int
// PHPStan: "has parameter $tags with no value type specified in iterable type array"
```

**Fix**: Add `/** @param list<string>|null $tags */` to every method in the implementation that receives `array`. The interface annotation alone does not propagate.

**Impact**: Boilerplate PHPDoc required on concrete classes that implement typed interfaces. Not a NENE2 bug — PHPStan behavior — but worth documenting for FT authors.

---

### 2. `array_values(array_filter(...))` inferred as `array<int, string>`, not `list<string>` (Low)

**Description**: PHPStan does not narrow `array_values(array_filter(explode(...)))` to `list<string>`. A `/** @var list<string> $tags */` assertion is required to pass it as `list<string>` to constructors.

**Severity**: Low

**Reproduction**:
```php
$tags = array_values(array_filter(explode(',', $tagsStr)));
// PHPStan: "array might not be a list" when passed to list<string> param
```

**Fix**:
```php
/** @var list<string> $tags */
$tags = array_values(array_filter(explode(',', $tagsStr)));
```

**Impact**: Minor boilerplate. Well-understood PHPStan limitation.

---

### 3. `?tags=php,lang` multi-value query parameter has no built-in parser (Medium)

**Description**: The framework provides `QueryStringParser::string()` for single values and `PaginationQueryParser` for limit/offset, but there is no helper for comma-separated multi-value parameters like `?tags=php,lang`. Developers must split, trim, and filter manually.

**Severity**: Medium

**Reproduction**:
```php
// Must write every time:
$rawTags = QueryStringParser::string($request, 'tags');
$tags = $rawTags !== null
    ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
    : null;
```

**Potential fix**: Add `QueryStringParser::commaSeparated(request, key): ?list<string>` that handles split + trim + filter + null-when-absent in one call.

---

### 4. `DomainExceptionHandlerInterface` works well with multiple handlers (Positive)

**Description**: Registering two handlers (`LinkNotFoundExceptionHandler` → 404, `DuplicateUrlExceptionHandler` → 409) via `$domainExceptionHandlers` in `RuntimeApplicationFactory` worked exactly as expected. No try/catch in route handlers.

**Severity**: N/A (positive finding)

**Pattern used**:
```php
new RuntimeApplicationFactory(
    $psr17, $psr17,
    domainExceptionHandlers: [
        new LinkNotFoundExceptionHandler($problems),
        new DuplicateUrlExceptionHandler($problems),
    ],
    routeRegistrars: [...],
)
```

Both handlers were exercised in tests and produced correct 404/409 Problem Details responses with appropriate `type` URIs.

## Tests

26 tests, 54 assertions — all pass.

Coverage: list (empty, pagination, search by title, search by URL, tag filter, multi-tag AND, invalid limit/offset), create (201 with all fields, default tags, duplicate URL, missing url/title, invalid JSON), show (200, 404), delete (204, removes from list, 404, re-bookmark after delete), infrastructure (security headers on success+error, X-Request-Id, 404 on unknown route, 405 on wrong method).
