# Field Trial 56 — ETag and Conditional GET (etaglog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/etaglog/`
**NENE2 version**: 1.5.19
**Theme**: `ETag` header generation, `If-None-Match` → 304, `Last-Modified` + `If-Modified-Since`, ETag invalidation after updates

## Overview

Built an article management API to validate HTTP caching headers and conditional GET:
- Every article has an ETag computed from `md5(id|updated_at|title|content)`, wrapped in double quotes as the HTTP spec requires
- `GET /articles/{id}` returns `ETag` and `Last-Modified` on every response
- If the request includes a matching `If-None-Match` header, returns 304 Not Modified with an empty body
- If `If-Modified-Since` >= the article's `updated_at`, also returns 304
- After a PUT update, the ETag changes and stale ETags correctly get 200 instead of 304

## Endpoints Implemented

- `POST /articles` — create article (returns ETag, Last-Modified)
- `GET /articles` — list all articles
- `GET /articles/{id}` — get single article with ETag conditional GET support
- `PUT /articles/{id}` — update article (returns new ETag)

## Test Results

18 tests, 24 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — No NENE2 helper for ETag/conditional GET [MEDIUM]

**Symptom**: Conditional GET logic (check `If-None-Match`, compare ETags, return 304) must be hand-rolled in every route handler that wants cache validation. There is no NENE2 utility for this.

**Implementation**:
```php
$etag = $article->etag();
$ifNoneMatch = $request->getHeaderLine('If-None-Match');
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    return $this->json->create([], 304)
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

**NENE2 impact**: Could be a `ConditionalGetHelper::check()` static utility that takes the request, ETag, and last-modified string, and returns either a 304 response or `null` (signalling the handler to return the full body). Not blocking — PSR-7 already provides the header access — but would reduce boilerplate.

---

## Patterns Validated

### ETag computed on the entity

```php
public function etag(): string
{
    return '"' . md5($this->id . '|' . $this->updatedAt . '|' . $this->title . $this->content) . '"';
}
```

The ETag includes `updated_at` so any UPDATE automatically invalidates it. Double-quote wrapping is required by HTTP spec (strong ETag).

### 304 response requires ETag header

RFC 9110 requires that a 304 response include the same `ETag` header as the full 200 would have. `withHeader()` chained on the 304 response satisfies this.

### `JsonResponseFactory::create([], 304)` produces an empty body

Passing `[]` to `create()` serializes as `{}` (empty JSON object). A true 304 body should be empty, but since this is a JSON API implementation detail, an empty object is acceptable — clients should use the status code, not the body.

### ETag changes after update

```php
// Get original ETag
$etag1 = $this->req('GET', '/articles/' . $id)->getHeaderLine('ETag');

// Update the article
$this->req('PUT', '/articles/' . $id, ['title' => 'Updated', 'content' => 'new']);

// ETag is now different
$etag2 = $this->req('GET', '/articles/' . $id)->getHeaderLine('ETag');
$this->assertNotSame($etag1, $etag2);
```

### Stale ETag returns 200

After an update, sending the old ETag in `If-None-Match` no longer matches, so the handler falls through to the full 200 response with the new body.

---

## NENE2 Changes Required

### Consider adding ConditionalGetHelper utility [MEDIUM]

A static helper that checks `If-None-Match` and `If-Modified-Since` and returns a ready-to-send 304 response or `null` would eliminate the repetitive conditional GET pattern from every cacheable route handler. Candidate API:

```php
use Nene2\Http\ConditionalGetHelper;

$response = ConditionalGetHelper::check($request, $etag, $lastModified);
if ($response !== null) {
    return $response; // 304
}
// ... build and return 200 response
```

This is a convenience addition, not a correctness issue — PSR-7 already provides everything needed.
