# ETag & Conditional Requests

> **FT reference**: FT307 (`NENE2-FT/etaglog`) — ETag conditional requests: `If-None-Match`→304, `If-Modified-Since`→304, `If-Match`→412 stale / 428 absent, wildcard `If-Match: *` passes, ETag changes after every update, 15 tests PASS.

ETags let clients avoid re-downloading unchanged content and detect stale state before writing. NENE2 provides two helpers for the most common patterns.

| Scenario | Header | Helper | On match |
|---|---|---|---|
| Conditional GET | `If-None-Match` | `ConditionalGetHelper` | 304 Not Modified |
| Conditional write | `If-Match` | `ConditionalWriteHelper` | write proceeds |
| Write without header | — | `ConditionalWriteHelper` | 428 Precondition Required |
| Stale write ETag | `If-Match` | `ConditionalWriteHelper` | 412 Precondition Failed |

## ETag Generation

Generate a strong ETag from the resource content as a double-quoted MD5:

```php
final readonly class Article
{
    public function etag(): string
    {
        // Double quotes are required by RFC 9110 — without them If-None-Match comparison always fails
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
```

Keep ETag generation in one place (a method on the entity) so changing the algorithm (e.g. to SHA-256) is a single edit.

## Conditional GET — 304 Not Modified

```php
private function get(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    $etag = $article->etag();

    // Returns a 304 response when If-None-Match matches the current ETag.
    // Returns null when a full 200 response must be sent.
    $notModified = ConditionalGetHelper::check($request, $this->responseFactory, $etag, $article->updatedAt);
    if ($notModified !== null) {
        return $notModified;
    }

    return $this->json->create($this->serialize($article))
        ->withHeader('ETag', $etag)
        ->withHeader('Last-Modified', $article->updatedAt);
}
```

`ConditionalGetHelper::check()` evaluates two headers:
- `If-None-Match`: exact ETag match → 304
- `If-Modified-Since`: string comparison `$ifModifiedSince >= $lastModified` → 304

Always include the same `$etag` value in both the `check()` call and the `withHeader('ETag', $etag)` call. Generating them separately risks drift.

### Last-Modified format

The `If-Modified-Since` check is a **string comparison**, not a parsed date comparison. Use a format that sorts lexicographically — ISO 8601 is recommended:

```php
$now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'); // ✅ 2026-05-21T12:00:00Z
```

The HTTP standard `Sat, 21 May 2026 12:00:00 GMT` format sorts incorrectly — do not use it with this helper.

### 304 has no body

RFC 9110 forbids a body in 304 responses. `ConditionalGetHelper` returns an empty `createResponse(304)`, so this is handled correctly as long as you return the helper's response directly.

## Conditional Write — If-Match

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $article = $this->repo->findById((int) Router::param($request, 'id'));
    if ($article === null) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }

    // Must call BEFORE the write — checking after is meaningless.
    // Returns 428 when If-Match is absent; 412 when If-Match is present but wrong.
    // Returns null when the precondition passes.
    $preconditionFailed = ConditionalWriteHelper::check($request, $this->problems, $article->etag());
    if ($preconditionFailed !== null) {
        return $preconditionFailed;
    }

    $updated = $this->repo->update($id, $title, $body);

    return $this->json->create($this->serialize($updated))
        ->withHeader('ETag', $updated->etag())
        ->withHeader('Last-Modified', $updated->updatedAt);
}
```

### If-Match: * wildcard

A client can send `If-Match: *` to mean "proceed if the resource exists at all". `ConditionalWriteHelper` passes this unconditionally. **The caller is responsible for returning 404 when the resource does not exist** — fetch the record first and guard with a 404.

### Making If-Match optional

By default (`$require = true`), missing `If-Match` returns 428. To allow writes without a precondition header:

```php
ConditionalWriteHelper::check($request, $this->problems, $article->etag(), require: false);
```

Only relax this when optimistic locking is genuinely optional for the resource.

## Client Flow

```
POST /articles            → 201 { id: 1, ... }  ETag: "abc123"
GET  /articles/1          → 200 { id: 1, ... }  ETag: "abc123"

GET  /articles/1          → 304 (no body)
  If-None-Match: "abc123"

PATCH /articles/1         → 200 { ... }  ETag: "def456"
  If-Match: "abc123"
  { title: "Updated" }

PATCH /articles/1         → 412 Precondition Failed
  If-Match: "abc123"       (stale — content changed, ETag is now "def456")

PATCH /articles/1         → 428 Precondition Required
  (no If-Match header)

PATCH /articles/1         → 200 { ... }
  If-Match: *              (wildcard — any existing version)
```

## Always Include ETag in Every Response

Return `ETag` (and `Last-Modified`) on POST, GET, and PATCH responses so the client always has a fresh value without an extra round-trip:

```php
return $this->json->create($this->serialize($article), 201)
    ->withHeader('ETag', $article->etag())
    ->withHeader('Last-Modified', $article->updatedAt);
```

## ETag vs Version Field

| | ETag (HTTP header) | Version field (body) |
|---|---|---|
| Where checked | HTTP header | Request body |
| Granularity | Content hash | Integer counter |
| Client needs to track | ETag value | Version number |
| Best for | HTTP caching + optimistic locking | API-level conflict detection |

They can be used together: ETag for HTTP caching, version for DB-level conflict detection (see [optimistic-locking.md](optimistic-locking.md)).

## Code Review Checklist

- [ ] ETag string includes surrounding double quotes (`'"' . md5(...) . '"'`)
- [ ] ETag generation is in one place (entity method), not duplicated across handlers
- [ ] `ConditionalGetHelper::check()` is called before building the 200 response
- [ ] The same `$etag` value is passed to both `check()` and `withHeader('ETag', $etag)`
- [ ] `ConditionalWriteHelper::check()` is called before the write
- [ ] 304 response body is empty (use the helper's response directly)
- [ ] `Last-Modified` values use ISO 8601 format (lexicographic ordering required)
- [ ] Every response (201, 200) includes `ETag` so the client always has a fresh value
- [ ] Tests cover: 200 without `If-None-Match`, 304 on match, 200 on stale ETag, 428 without `If-Match`, 412 on stale `If-Match`, 200 on correct `If-Match`, `If-Match: *`
