# HOWTO: API Versioning — URI Prefix Pattern

This guide shows how to version a NENE2 API using URI prefixes (`/v1/`, `/v2/`),
signal deprecation to clients with `Deprecation` and `Sunset` headers (RFC 8594),
and transform the same stored data into different response shapes per version.

---

## Versioning strategy

| Strategy | Example | Best for |
|---|---|---|
| **URI prefix** | `/v1/notes`, `/v2/notes` | Most APIs — visible in logs, easy to route |
| Accept header | `Accept: application/vnd.api+json;version=2` | Strict REST purists |
| Query parameter | `/notes?version=2` | Internal/admin APIs only |

URI prefix versioning pairs naturally with NENE2's explicit router:
each version gets its own `RouteRegistrar` class registered to a distinct prefix.

---

## Structure

```
src/
  Shared/
    Note.php             — domain DTO (version-agnostic)
    NoteRepository.php   — storage (shared across all versions)
  V1/
    RouteRegistrar.php   — /v1/* handlers + Deprecation headers
  V2/
    RouteRegistrar.php   — /v2/* handlers (current)
```

---

## Register both versions in RuntimeApplicationFactory

```php
$v1 = new V1\RouteRegistrar($notes, $json, $problems);
$v2 = new V2\RouteRegistrar($notes, $json, $problems);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [
        static fn (Router $r) => $v1->register($r),
        static fn (Router $r) => $v2->register($r),
    ],
))->create();
```

---

## V1 RouteRegistrar — deprecated, with Sunset header

```php
final readonly class RouteRegistrar  // V1
{
    private const string SUNSET = 'Sat, 31 Dec 2026 23:59:59 GMT';

    public function register(Router $router): void
    {
        $router->get('/v1/notes', $this->list(...));
        $router->post('/v1/notes', $this->create(...));
        $router->get('/v1/notes/{id}', $this->get(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $notes    = $this->notes->findAll();
        $response = $this->json->create([
            'notes' => array_map(fn (Note $n) => $this->toV1($n), $notes),
        ]);

        return $this->withDeprecationHeaders($response);
    }

    /** @return array<string, mixed> */
    private function toV1(Note $note): array
    {
        return [
            'id'         => $note->id,
            'title'      => $note->title,
            'content'    => $note->body,    // v1 field name
            'created_at' => $note->createdAt,
            // tags and updated_at intentionally omitted from v1
        ];
    }

    private function withDeprecationHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Deprecation', 'true')
            ->withHeader('Sunset', self::SUNSET)
            ->withHeader('Link', '</v2/notes>; rel="successor-version"');
    }
}
```

---

## V2 RouteRegistrar — current version

```php
final readonly class RouteRegistrar  // V2
{
    public function register(Router $router): void
    {
        $router->get('/v2/notes', $this->list(...));
        $router->post('/v2/notes', $this->create(...));
        $router->get('/v2/notes/{id}', $this->get(...));
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min((int) ($q['limit'] ?? 20), 100));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        return $this->json->create([
            'data' => array_map(fn (Note $n) => $this->toV2($n), $this->notes->findAll($limit, $offset)),
            'meta' => ['limit' => $limit, 'offset' => $offset],
        ]);
    }

    /** @return array<string, mixed> */
    private function toV2(Note $note): array
    {
        return [
            'id'         => $note->id,
            'title'      => $note->title,
            'body'       => $note->body,       // renamed from "content" in v1
            'tags'       => $note->tags,        // new in v2
            'created_at' => $note->createdAt,
            'updated_at' => $note->updatedAt,   // new in v2
        ];
    }
    // No withDeprecationHeaders — v2 is current
}
```

---

## Deprecation headers (RFC 8594)

| Header | Value | Purpose |
|---|---|---|
| `Deprecation` | `true` | Signals the endpoint is deprecated |
| `Sunset` | RFC 7231 date | When the endpoint will be removed |
| `Link` | `</v2/notes>; rel="successor-version"` | Points clients to the replacement |

Apply to every response from deprecated versions — including POST, PUT, DELETE.
Apply consistently: do not apply to error responses returned before handler logic runs.

```php
// ✅ Every successful response carries the headers
$response = $this->json->create($data);
return $this->withDeprecationHeaders($response);
```

---

## Shared storage across versions

Both v1 and v2 read and write the same table. The repository is version-agnostic.
A note created through v1 is immediately accessible via v2, with the same `id`.

```
POST /v1/notes  {"title":"A","content":"B"}  → note.id = 1, note.body = "B"
GET  /v2/notes/1                             → {"data":{"id":1,"body":"B","tags":[],...}}
```

Only the *presentation layer* (the `toV1()` / `toV2()` methods) differs between versions.

---

## Response shape differences between versions

| Field | v1 | v2 |
|---|---|---|
| Root wrapper | `{ "notes": [...] }` | `{ "data": [...], "meta": {...} }` |
| Text field name | `content` | `body` |
| `tags` | absent | present |
| `updated_at` | absent | present |

---

## When to create a new version

Create a new API version when you need to make a **breaking change**:

- Renaming or removing a field
- Changing a field's type
- Changing the top-level response structure
- Removing an endpoint

Do **not** create a new version for additive changes (new optional fields, new endpoints).
Additive changes are backward-compatible and can go into the current version.

---

## Deprecation timeline

| Phase | Timeline | Action |
|---|---|---|
| Announcement | Day 0 | Add `Deprecation`/`Sunset`/`Link` headers to v1 |
| Migration window | 6+ months | Support v1 and v2 in parallel |
| Shutdown | Sunset date | Return `410 Gone` from all v1 endpoints |

```php
// After the Sunset date: replace handlers with a 410 response
$router->get('/v1/notes', function (ServerRequestInterface $req) use ($problems): ResponseInterface {
    return $problems->create($req, 'gone', 'API v1 has been retired. Use /v2/notes.', 410);
});
```

---

## Unknown version → 404

Requests to unregistered versions (`/v3/notes`) automatically return 404
because no route matches. No special handling needed.
