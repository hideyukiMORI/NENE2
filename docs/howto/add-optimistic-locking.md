# How to add optimistic concurrency control (ETag / If-Match)

Optimistic locking prevents the **lost update problem**: two clients read the same resource,
both modify it, and the second write silently overwrites the first.

NENE2 ships `ConditionalWriteHelper` for the write side (PUT, PATCH, DELETE) and
`ConditionalGetHelper` for the read side (GET → 304 Not Modified).

---

## 1. Add a version counter to the schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## 2. Return an ETag on every GET and write response

Use the version number as a simple, debuggable ETag:

```php
private function etag(int $version): string
{
    return '"v' . $version . '"';
}

// In GET handler:
return $this->json->create($doc->toArray())
    ->withHeader('ETag', $this->etag($doc->version));

// In POST (create) handler:
return $this->json->create($doc->toArray(), 201)
    ->withHeader('ETag', $this->etag($doc->version));
```

---

## 3. Check `If-Match` on PUT / PATCH / DELETE

```php
use Nene2\Http\ConditionalWriteHelper;

private function update(ServerRequestInterface $request): ResponseInterface
{
    $id  = $this->resolveId($request);
    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    $block = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc->version));
    if ($block !== null) {
        return $block; // 412 Precondition Failed or 428 Precondition Required
    }

    // ETag matched — safe to write
    $updated = $this->repo->updateIfMatch($id, /* new values */, $doc->version);
    if ($updated === null) {
        // Concurrent modification after our check
        return $this->problems->create($request, 'precondition-failed', 'Precondition Failed', 412, '');
    }
    return $this->json->create($updated->toArray())
        ->withHeader('ETag', $this->etag($updated->version));
}
```

### Status codes returned by `ConditionalWriteHelper::check()`

| `If-Match` header | Server ETag | Result |
|-------------------|-------------|--------|
| absent | any | **428** Precondition Required (header is mandatory) |
| `*` | any | **null** — pass (wildcard, any version) |
| `"v3"` | `"v3"` | **null** — pass (exact match) |
| `"v2"` | `"v3"` | **412** Precondition Failed (stale version) |

To make `If-Match` optional, pass `require: false`:

```php
ConditionalWriteHelper::check($request, $this->problems, $etag, require: false);
```

---

## 4. Use a conditional UPDATE in the repository

```php
public function updateIfMatch(int $id, string $title, int $expectedVersion): ?Document
{
    $newVer  = $expectedVersion + 1;
    $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $updated = $this->db->execute(
        'UPDATE documents SET title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $newVer, $now, $id, $expectedVersion],
    );

    if ($updated === 0) {
        return null; // version mismatch or not found
    }
    return new Document($id, $title, $newVer, $now);
}
```

The `WHERE version = ?` clause is the database-level lock guard. If the row's version was
already advanced by a concurrent writer, `execute()` returns `0` (no rows updated) and the
caller can return a second 412 response.

---

## 5. Test the lost-update scenario

```php
public function testLostUpdatePrevented(): void
{
    $id = $this->decode($this->create('Original'))['id'];

    // Alice reads version 1 and updates → version becomes 2
    $this->req('PUT', '/documents/' . $id, ['title' => "Alice's edit"], '"v1"');

    // Bob tries to update with stale v1 ETag → must fail
    $bob = $this->req('PUT', '/documents/' . $id, ['title' => "Bob's edit"], '"v1"');
    self::assertSame(412, $bob->getStatusCode());

    // Alice's update is preserved
    $final = $this->decode($this->req('GET', '/documents/' . $id));
    self::assertSame("Alice's edit", $final['title']);
    self::assertSame(2, $final['version']);
}
```

---

## Notes

- **ETag format**: `"v{version}"` (integer-based) is simple and predictable in tests.
  Content-hash ETags (`'"' . md5($body) . '"'`) are more robust for content-addressable resources
  but harder to predict in tests without pre-computing the hash.
- **Wildcard `If-Match: *`**: RFC 9110 defines `*` to mean "succeed if the resource has any
  current representation" — i.e., it exists. Useful for "update-if-exists" without knowing
  the version. The caller must still return 404 when the resource is absent.
- **428 Precondition Required** (RFC 6585 §3): the correct status when `If-Match` is required
  but absent. Use it instead of 400 or 422 — the request is well-formed; the precondition is missing.
- **TOCTOU window**: the `findById()` + conditional UPDATE pattern has a brief race window on
  multi-writer databases. Under SQLite's write serialization this is harmless. On PostgreSQL
  under high concurrency, wrap both operations in a `SERIALIZABLE` transaction.
