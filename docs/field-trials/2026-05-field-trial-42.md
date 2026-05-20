# Field Trial 42 — Cursor-based Pagination (cursorlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/cursorlog/`
**NENE2 version**: 1.5.17
**Theme**: Keyset (cursor) pagination with opaque base64url tokens, newest-first stable ordering, 400 on invalid cursor

## Overview

Built a social-feed message API that uses keyset (cursor) pagination instead of offset-based pagination. Cursors encode a `(created_at, id)` pair as base64url JSON, providing stable ordering even during concurrent inserts. Invalid cursor tokens return a structured 409 Problem Details response.

## Endpoints Implemented

- `POST /messages` — create (author, content); timestamps server-side
- `GET /messages` — list (cursor pagination): `?limit=N&after=<cursor>`; returns `{items, total, has_more, next_cursor}`
- `GET /messages/{id}` — show

## Test Results

17 tests, 41 assertions — pass with no fixes required.

---

## Frictions Found

None. All patterns worked as expected on first attempt.

---

## Patterns Validated

### Keyset (cursor) pagination SQL

```sql
-- First page (no cursor):
SELECT * FROM messages ORDER BY created_at DESC, id DESC LIMIT ?

-- Subsequent pages (with cursor at created_at=C, id=I):
SELECT * FROM messages
WHERE (created_at < ? OR (created_at = ? AND id < ?))
ORDER BY created_at DESC, id DESC
LIMIT ?
```

Fetching `$limit + 1` rows and discarding the last one is the standard probe for `has_more`.

### Opaque cursor encoding

```php
final readonly class Cursor
{
    public static function decode(string $token): ?self
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['c'], $data['i']) || ...) {
            return null;
        }
        return new self((string) $data['c'], (int) $data['i']);
    }

    public function encode(): string
    {
        $json = json_encode(['c' => $this->createdAt, 'i' => $this->id], JSON_THROW_ON_ERROR);
        return strtr(base64_encode($json), '+/', '-_');
    }
}
```

Using `strtr(base64_encode(...), '+/', '-_')` produces base64url (URL-safe, no padding issues with query strings).

### Limit clamping

```php
$limit = QueryStringParser::int($request, 'limit') ?? 20;
$limit = max(1, min(100, $limit));
```

`QueryStringParser::int()` returns `?int`, so clamping is applied after the default fallback.

### Invalid cursor → 400 Problem Details

```php
$after = Cursor::decode($afterToken);
if ($after === null) {
    throw new InvalidCursorException($afterToken);
}
```

`InvalidCursorExceptionHandler::supports()` checks `instanceof InvalidCursorException` — the clean typed-exception pattern (cf. the string-match smell noted in FT41's `HasChildrenExceptionHandler`).

### Response shape

```json
{
  "items": [...],
  "total": 3,
  "has_more": true,
  "next_cursor": "eyJjIjoiMjAyNi0wNS0yMFQxMjowMDowMFoiLCJpIjo1fQ=="
}
```

`total` reflects the count of items returned in *this page*, not the total in the database — consistent with how `PaginationResponse` works in NENE2 core.

---

## NENE2 Changes Required

None — zero frictions. The existing `QueryStringParser`, `JsonRequestBodyParser`, `DomainExceptionHandlerInterface`, and `ProblemDetailsResponseFactory` APIs were sufficient.

No version bump needed.
