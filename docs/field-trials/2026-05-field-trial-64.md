# Field Trial 64 — Bulk Operations with Partial-Success Semantics

**Date**: 2026-05-20
**Theme**: Bulk create and bulk delete with per-item validation and partial-success (HTTP 207)
**Project**: `/home/xi/docker/NENE2-FT/bulklog/`
**NENE2 version**: 1.5.21

---

## Summary

Implemented a bulk item management API that demonstrates partial-success semantics for batch write operations. The API accepts arrays of items for bulk create and arrays of IDs for bulk delete, returning per-item success/failure results rather than all-or-nothing responses.

**Result**: 14 tests, 35 assertions — all pass. PHPStan level 8 clean. CS-Fixer clean.

---

## What Was Built

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/items` | Create single item |
| `GET` | `/items/{id}` | Get item by ID |
| `POST` | `/items/bulk` | Bulk create with partial-success |
| `DELETE` | `/items/bulk` | Bulk delete with partial-success |

### Domain Model

```
items(id, sku UNIQUE, name, price, created_at)
```

- `sku` has a UNIQUE constraint to test duplicate detection in bulk operations
- `price` stored as integer cents

### BulkResult DTO

```php
final readonly class BulkResult
{
    public function __construct(
        public array $created,  // list<array<string, mixed>>
        public array $errors,   // list<array<string, mixed>>
    ) {}

    public function hasErrors(): bool { return $this->errors !== []; }

    public function toArray(): array
    {
        return ['created' => $this->created, 'errors' => $this->errors];
    }
}
```

### Bulk Create — Partial-Success Logic

Each item in the input array is validated independently:
- Empty SKU → `sku is required`
- Duplicate SKU → `sku "X" already exists` (checked via `skuExists()`)
- Empty name → `name is required`
- Negative price → `price must be a non-negative integer`

Valid items are inserted; invalid items accumulate error entries with `index`, `sku`, and `errors` fields.

**Status codes**:
- `201 Created` — all items succeeded
- `207 Multi-Status` — at least one item failed (even if all failed)

### Bulk Delete

Iterates over requested IDs, finds each, deletes found ones, accumulates not-found IDs.
Always returns `200 OK` with `{"deleted": [...], "not_found": [...]}`.

---

## Frictions Encountered

### None

FT64 was the smoothest field trial to date. No NENE2 API friction was encountered.

- `JsonRequestBodyParser.parse()` worked cleanly for both `POST /items/bulk` (body with `items` array) and `DELETE /items/bulk` (body with `ids` array).
- `BulkResult.toArray()` returns `array<string, mixed>` — compatible with `JsonResponseFactory::create()`.
- `(object)[]` workaround for empty-body tests is now well-understood from FT59.
- `Router::PARAMETERS_ATTRIBUTE` for path params remains consistent.

---

## Patterns Validated

### Pattern: Per-Item Error Collection

```php
foreach ($inputs as $index => $input) {
    $itemErrors = [];
    // ... validate ...
    if ($itemErrors !== []) {
        $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
        continue;
    }
    // ... insert ...
    $created[] = $item->toArray();
}
return new BulkResult($created, $errors);
```

Collecting errors per-index allows the client to correlate failures back to the original request payload.

### Pattern: 207 vs 201 Based on hasErrors()

```php
$status = $result->hasErrors() ? 207 : 201;
return $this->json->create($result->toArray(), $status);
```

Simple flag on the DTO drives the HTTP status. Even "all failed" returns 207 (not 422), since the request format was valid — only the data was bad.

### Pattern: Bulk Delete with not_found

DELETE bulk operations always return 200 because the overall operation succeeded (we processed the request). Callers can inspect `not_found` to learn which IDs were already gone.

---

## Comparison with Previous FTs

| FT | Theme | Key Pattern |
|----|-------|-------------|
| 59 | Audit log | Per-field change tracking, changed_by header |
| 60 | Event sourcing | Append-only events, balance replay |
| 61 | State machine | Backed enum, 409 on invalid transition |
| 62 | Soft delete | deleted_at column, restore/purge lifecycle |
| 63 | Cursor pagination | Fetch limit+1, next_cursor pointer |
| **64** | **Bulk operations** | **207 partial-success, per-item errors** |

---

## No NENE2 Changes Required

All bulk operation patterns are implementable with NENE2 1.5.21 as-is. No new framework features were needed.

- `JsonResponseFactory::create()` handles `BulkResult::toArray()` cleanly (returns `array<string, mixed>`)
- `JsonResponseFactory::createList()` (added in v1.5.21 for FT62) was not needed here since the response envelope is always an object
- `JsonRequestBodyParser` handles request bodies with arrays as values (`items`, `ids`) correctly
