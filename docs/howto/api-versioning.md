# How-to: API Versioning

> **FT reference**: FT346 (`NENE2-FT/versionlog`) — URL path versioning with /v1/ and /v2/ namespaces, deprecated V1 carrying Deprecation/Sunset/Link headers, V2 with enriched response shape, shared underlying storage, 16 tests PASS.

This guide shows how to implement URL path versioning: run two API versions side by side with different response shapes, mark the old version as deprecated with HTTP headers, and share a single database across versions.

## Version Strategy

| Version | Status      | Prefix  | List wrapper              |
|---------|-------------|---------|---------------------------|
| V1      | Deprecated  | `/v1/`  | `{"notes": [...]}`        |
| V2      | Current     | `/v2/`  | `{"data": [...], "meta": {...}}` |

Both versions share the same database tables. V1 clients can continue using their existing integration while the deprecation headers signal a migration deadline.

## Endpoints

| Method   | V1 Path         | V2 Path         | Description     |
|----------|-----------------|-----------------|-----------------|
| `POST`   | `/v1/notes`     | `/v2/notes`     | Create note     |
| `GET`    | `/v1/notes`     | `/v2/notes`     | List notes      |
| `GET`    | `/v1/notes/{id}`| `/v2/notes/{id}`| Get single note |

## V1 Response Shape

```php
// POST /v1/notes
{"title": "Hello", "content": "World"}
→ 201
{
  "id": 1,
  "title": "Hello",
  "content": "World",    // ← field name: "content"
  "created_at": "..."
  // No "body", no "tags", no "updated_at"
}

// GET /v1/notes
→ 200
{
  "notes": [              // ← wrapper key: "notes"
    {"id": 1, "title": "Hello", "content": "World", ...}
  ]
}
```

## V2 Response Shape

```php
// POST /v2/notes
{"title": "Hello", "body": "World", "tags": ["php", "api"]}
→ 201
{
  "data": {               // ← envelope key: "data"
    "id": 2,
    "title": "Hello",
    "body": "World",      // ← field name: "body"
    "tags": ["php", "api"],  // ← tags added
    "updated_at": "...",     // ← updated_at added
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← list wrapper: "data"
  "meta": {               // ← meta section
    "limit": 20,
    "offset": 0
  }
}
```

## V1 Deprecation Headers

Every V1 response carries three headers informing clients to migrate:

```
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"
```

```php
// Every V1 endpoint adds:
return $response
    ->withHeader('Deprecation', 'true')
    ->withHeader('Sunset', 'Sat, 01 Jan 2027 00:00:00 GMT')
    ->withHeader('Link', '</v2/notes>; rel="successor-version"');
```

V2 responses carry **none** of these headers.

```php
// V1 GET /v1/notes headers:
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// V2 GET /v2/notes headers:
// (no Deprecation, Sunset, or Link)
```

## Shared Storage — Cross-Version Access

Both versions share the same `notes` table. A note created via V1 is readable from V2 (and vice versa):

```php
// Create via V1
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// Read via V2 — same record, V2 shape
GET /v2/notes/5
→ 200
{
  "data": {
    "id": 5,
    "title": "Cross-version",
    "body": "Shared body",    // V2 calls it "body", not "content"
    "tags": [],
    "updated_at": "...",
    "created_at": "..."
  }
}
```

V1 clients never see `tags` (not in the V1 response shape), even if the note has tags from a V2 write.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- JSON array
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

The underlying column is `body`. V1 maps it to `content` in the response transformer.

## Implementation — Response Transformers

```php
// V1 transformer — maps "body" column → "content" field, hides tags/updated_at
final class V1NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['body'],   // field rename
            'created_at' => $row['created_at'],
            // No "body", "tags", "updated_at"
        ];
    }
}

// V2 transformer — full row, wrapped in "data"
final class V2NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'tags'       => json_decode($row['tags'], true),
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
```

## Route Registration

```php
// V1Registrar::register()
$router->get('/v1/notes',       [V1ListHandler::class, 'handle']);
$router->post('/v1/notes',      [V1CreateHandler::class, 'handle']);
$router->get('/v1/notes/{id}',  [V1GetHandler::class, 'handle']);

// V2Registrar::register()
$router->get('/v2/notes',       [V2ListHandler::class, 'handle']);
$router->post('/v2/notes',      [V2CreateHandler::class, 'handle']);
$router->get('/v2/notes/{id}',  [V2GetHandler::class, 'handle']);
```

Both registrars are passed to `RuntimeApplicationFactory` — routes from both are registered in the same router.

## Unknown Version → 404

```php
GET /v3/notes
→ 404
```

There is no V3 route; the router returns 404. No "version not supported" error type is needed — 404 is sufficient.

## Validation

```php
POST /v1/notes  {"content": "no title"}
→ 422  // title is required

POST /v2/notes  {"body": "no title"}
→ 422  // title is required
```

Both versions require `title`. V1 accepts `content` as the body field; V2 accepts `body`.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Different DB tables per version | Cross-version reads break; data migrates poorly when versions share no state |
| Return `Deprecation: true` on V2 | Clients cannot distinguish which version is current |
| No `Link` header with successor | Deprecated clients don't know where to migrate to |
| Rename DB column `body` → `content` for V1 | All V2 code must change; use response transformer to rename, not schema |
| Hard-code Sunset date in tests | Tests fail after the sunset date; use a future constant or config value |
| Expose V1 `tags` in response | V1 clients receive a field they don't understand; shape contracts break silently |
