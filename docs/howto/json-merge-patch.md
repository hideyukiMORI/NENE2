# How-to: JSON Merge Patch & ETag Conflict Detection

**FT178 — patchlog**

Implementing PATCH (RFC 7396 JSON Merge Patch) and PUT semantics with
optimistic locking via ETag, immutable field protection, and V.php integration.

---

## The problem with PUT

`PUT` replaces the entire resource. Clients must send every field, even those
they didn't change. This creates:

- **Race conditions**: concurrent readers both see version 1, both PUT, last one
  wins and silently discards the other's changes.
- **Bandwidth waste**: full payload even for a one-field change.
- **Permission confusion**: writing fields the client doesn't own.

`PATCH` with **JSON Merge Patch (RFC 7396)** solves the first two; `ETag` /
`If-Match` solves the race condition for both PATCH and PUT.

---

## JSON Merge Patch semantics (RFC 7396)

The patch document describes changes using a simple rule:

| Patch value | Meaning |
|-------------|---------|
| `"new value"` | Set the field to this value |
| `null` | Reset the field (delete or revert to default) |
| *(key absent)* | Leave the field unchanged |

```json
// Document before PATCH:
{ "title": "Hello", "body": "World", "status": "draft" }

// PATCH body:
{ "title": "Goodbye", "status": null }

// Result:
{ "title": "Goodbye", "body": "World", "status": "draft" }
//                              ^^^^^     ^^^^^^^^^^^^^^
//                              unchanged  null → reset to default
```

### Immutable fields

Some fields must never be modifiable via PATCH or PUT:

```php
private const array IMMUTABLE_FIELDS = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

$violations = array_intersect(array_keys($body), self::IMMUTABLE_FIELDS);

if ($violations !== []) {
    return $this->responseFactory->create(
        ['error' => 'Fields are immutable: ' . implode(', ', $violations)],
        422,
    );
}
```

### Empty PATCH is valid (no-op)

RFC 7396 §3 explicitly allows an empty patch `{}`:

```php
// No keys in $patch → skip UPDATE, return current document unchanged
if ($patch === []) {
    return $doc;  // no-op; version NOT incremented
}
```

---

## ETag and If-Match for optimistic locking

### ETag format

```php
public function etag(): string
{
    return sprintf('"doc-%d-%d"', $this->id, $this->version);
    // e.g. "doc-42-7"
}
```

Return `ETag` on every GET/PATCH/PUT response:

```php
return $this->responseFactory->create($doc->toArray())
    ->withHeader('ETag', $doc->etag());
```

### Conflict detection

```php
$ifMatch = $request->getHeaderLine('If-Match');

if ($ifMatch !== '' && $ifMatch !== $doc->etag()) {
    return $this->responseFactory->create(
        ['error' => 'Version conflict. Fetch the document and retry.'],
        412,  // Precondition Failed
    );
}
```

**If-Match absent**: optimistic update without conflict check (last-write-wins).
**If-Match present and matching**: safe concurrent update.
**If-Match present but stale**: 412 — client must re-fetch and retry.

### Version increment in SQL

Use the database to atomically increment version:

```sql
UPDATE documents
SET title = ?, version = version + 1, updated_at = ?
WHERE id = ? AND version = ?
```

The `WHERE version = ?` clause double-checks the optimistic lock at the DB level,
preventing a concurrent write from sneaking in between our read and write.

---

## V.php integration

FT178 is the first FT to use `Nene2\Validation\V` as a shared utility:

```php
// Query params
$page  = V::queryInt($params, 'page', 1, PHP_INT_MAX, 1);
$limit = V::queryInt($params, 'limit', 1, 50, 20);

// Auth header
$ownerId = V::userId($request->getHeaderLine('X-User-Id'));

// String fields (with explicit length limits)
$title = V::str($body['title'] ?? null, 200);

// Enum validation
$status = V::enum($body['status'] ?? null, DocumentStatus::class);
```

### The `?? ''` trap for optional body fields

```php
// ❌ WRONG — bypasses V::str null return for over-length input
$text = V::str($body['body'] ?? null, 10000) ?? '';

// ✅ CORRECT — validate when present, default when absent
$rawText = $body['body'] ?? null;
if ($rawText !== null) {
    $text = V::str($rawText, 10000);
    if ($text === null) {
        return $this->responseFactory->create(['error' => 'body too long'], 422);
    }
} else {
    $text = '';
}
```

`V::str(null, ...)` returns `null` because `null` is not a string.  
`V::str(too_long_string, 10000)` also returns `null`.  
Using `?? ''` collapses both cases into empty string — silently accepting the over-length input.

---

## Route parameter extraction

The NENE2 Router stores path parameters in the `nene2.route.parameters`
attribute, not as individual request attributes:

```php
// ❌ WRONG
$id = $request->getAttribute('id');  // always null for path params

// ✅ CORRECT
$id = Router::param($request, 'id');  // reads from nene2.route.parameters
```

---

## Attack checklist (ATK-01 through ATK-12)

| # | Test | Expectation |
|---|------|-------------|
| ATK-01 | PATCH `{"id": 999}` | 422 — immutable field |
| ATK-02 | PATCH `{"owner_id": 99}` | 422 — immutable field |
| ATK-03 | PATCH `{"version": 999}` | 422 — immutable field |
| ATK-04 | PATCH `{"title": 42}` (type confusion) | 422 — V::str rejects non-string |
| ATK-05 | PATCH by non-owner | 404 — IDOR protection |
| ATK-06 | If-Match stale ETag | 412 — optimistic lock conflict |
| ATK-07 | PUT missing required title | 422 |
| ATK-08 | PATCH empty `{}` | 200 — valid no-op (RFC 7396 §3) |
| ATK-09 | PATCH `{"status": null}` | 200 — reset to default `draft` |
| ATK-10 | PATCH `{"status": 2}` (type confusion) | 422 — V::enum rejects non-string |
| ATK-11 | PATCH `{"__proto__": {...}}` | 200 — unknown key ignored, no crash |
| ATK-12 | `?limit=999999`, `?page=-1`, 20-digit overflow | 422 — V::queryInt guards |
