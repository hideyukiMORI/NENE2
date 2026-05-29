---
title: "How-to: Bulk Operations with Partial-Success Semantics"
category: api-design
tags: [bulk, partial-success, multi-status, http-207, validation]
difficulty: intermediate
related: [batch-api-partial-success, bulk-status-update, implement-bulk-endpoint]
---

# How-to: Bulk Operations with Partial-Success Semantics

> **FT reference**: FT258 (`NENE2-FT/bulklog`) — Bulk create / bulk delete with partial-success semantics and HTTP 207 Multi-Status

Demonstrates how to handle bulk API operations where some items may succeed and others may fail.
Each item is processed independently — a validation failure for item N does not abort items N+1 onwards.
The response carries two arrays: `created` (succeeded) and `errors` (failed with reasons).
HTTP 207 Multi-Status is returned when there is a mix; 201 Created when all succeed.

---

## Routes

| Method   | Path          | Description                                   |
|----------|---------------|-----------------------------------------------|
| `POST`   | `/items`      | Create a single item                          |
| `GET`    | `/items/{id}` | Get a single item                             |
| `POST`   | `/items/bulk` | Bulk create items (partial-success)           |
| `DELETE` | `/items/bulk` | Bulk delete items by ID (partial-success)     |

> **Route order**: `/items/bulk` must be registered before `/items/{id}` so the literal
> segment `bulk` is not captured as a path parameter.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    price      INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

`sku TEXT NOT NULL UNIQUE` prevents duplicate SKUs at the DB level. `price INTEGER` stores price in
the smallest currency unit (cents/yen) to avoid floating-point rounding errors.

---

## BulkResult DTO

```php
final readonly class BulkResult
{
    /**
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        public array $created,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`created` holds the successfully created records. `errors` holds per-item error descriptors.
`hasErrors()` is a simple predicate that the controller uses to choose the HTTP status code.

---

## Bulk create: per-item validation

```php
public function bulkCreate(array $inputs, string $now): BulkResult
{
    $created = [];
    $errors  = [];

    foreach ($inputs as $index => $input) {
        $sku   = isset($input['sku'])   && is_string($input['sku'])   ? trim($input['sku'])   : '';
        $name  = isset($input['name'])  && is_string($input['name'])  ? trim($input['name'])  : '';
        $price = isset($input['price']) && is_int($input['price'])    ? $input['price']       : -1;

        $itemErrors = [];
        if ($sku === '') {
            $itemErrors[] = 'sku is required';
        } elseif ($this->skuExists($sku)) {
            $itemErrors[] = "sku \"{$sku}\" already exists";
        }
        if ($name === '') {
            $itemErrors[] = 'name is required';
        }
        if ($price < 0) {
            $itemErrors[] = 'price must be a non-negative integer';
        }

        if ($itemErrors !== []) {
            $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
            continue;   // skip insert, continue to next item
        }

        $item      = $this->create($sku, $name, $price, $now);
        $created[] = $item->toArray();
    }

    return new BulkResult($created, $errors);
}
```

**Key decisions**:
- `continue` on validation failure: failed items do not abort the loop.
- `$index` is included in the error entry: clients know which position in their input array failed.
- SKU uniqueness is checked in PHP (`skuExists()`) before INSERT, not caught from DB exceptions.
  This gives a cleaner, application-level error message rather than a raw constraint violation.
- All successful INSERTs share the same `$now` timestamp: the batch is treated as a single point in time.

---

## Bulk delete: not-found tracking

```php
public function bulkDelete(array $ids): array
{
    $deleted  = [];
    $notFound = [];

    foreach ($ids as $id) {
        $item = $this->findById($id);
        if ($item === null) {
            $notFound[] = $id;
            continue;
        }
        $this->executor->execute('DELETE FROM items WHERE id = ?', [$id]);
        $deleted[] = $id;
    }

    return ['deleted' => $deleted, 'not_found' => $notFound];
}
```

Not-found IDs are tracked but do not abort the operation. The response lets the caller audit
which IDs were actually deleted and which were already gone. Returning 200 (not 207) is reasonable
here because all requested deletes either succeeded or were already absent — there is no "error" state.

---

## Controller: HTTP 207 Multi-Status

```php
private function bulkCreate(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['items']) || !is_array($body['items'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'items', 'code' => 'required', 'message' => 'items array is required.']],
        ]);
    }

    $inputs = array_values($body['items']);
    $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $result = $this->repo->bulkCreate($inputs, $now);

    $status = $result->hasErrors() ? 207 : 201;   // ← 207 when mix of success + error

    return $this->json->create($result->toArray(), $status);
}
```

**HTTP status choice**:

| Outcome | Status | Meaning |
|---|---|---|
| All created | `201 Created` | Full success |
| Some created, some failed | `207 Multi-Status` | Partial success — client must inspect the body |
| All failed | `207 Multi-Status` | Full failure — `created` array is empty |
| No `items` array | `422 Unprocessable Entity` | Malformed request |

`207` signals to the client: _do not assume success — inspect the body_. A client that sees `201`
can assume all items were processed; a client that sees `207` must check `errors`.

**Why not 422 for partial failure?** `422` means the entire request is rejected. Partial-success
bulk endpoints do process some inputs successfully, so `422` would be misleading.

---

## Bulk delete controller

```php
private function bulkDelete(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['ids']) || !is_array($body['ids'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'ids', 'code' => 'required', 'message' => 'ids array is required.']],
        ]);
    }

    $ids    = array_values(array_filter($body['ids'], 'is_int'));
    $result = $this->repo->bulkDelete($ids);

    return $this->json->create($result);   // always 200
}
```

`array_filter($body['ids'], 'is_int')` silently drops non-integer values from the IDs array.
This is a design choice: malformed IDs are ignored rather than causing a 422. An alternative
approach is to reject the entire request if any ID is non-integer.

---

## Example request and response

### Bulk create — partial success

**Request** `POST /items/bulk`:
```json
{
  "items": [
    {"sku": "A001", "name": "Widget A", "price": 1000},
    {"sku": "",     "name": "Bad Item",  "price": 500},
    {"sku": "A001", "name": "Duplicate", "price": 200}
  ]
}
```

**Response** `207 Multi-Status`:
```json
{
  "created": [
    {"id": 1, "sku": "A001", "name": "Widget A", "price": 1000, "created_at": "2026-01-01 00:00:00"}
  ],
  "errors": [
    {"index": 1, "sku": "", "errors": ["sku is required"]},
    {"index": 2, "sku": "A001", "errors": ["sku \"A001\" already exists"]}
  ]
}
```

`index` refers to the position in the input `items` array (0-based). The client can correlate
each error back to the original input without scanning the payload.

### Bulk delete — partial success

**Request** `DELETE /items/bulk`:
```json
{"ids": [1, 999, 2]}
```

**Response** `200 OK`:
```json
{
  "deleted": [1, 2],
  "not_found": [999]
}
```

---

## Design trade-offs

| Approach | Behaviour | When to use |
|---|---|---|
| All-or-nothing | Rollback all if any fails | Financial, inventory — consistency required |
| Partial success (this pattern) | Process each independently | Import/export, data ingestion |
| Fire-and-forget queue | Async processing, deferred results | Large batches, background jobs |

Partial-success is appropriate when items are independent of each other. If item A's success
depends on item B's success (e.g., transferring stock between items), use an all-or-nothing
transaction instead.

---

## Related howtos

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomic all-or-nothing multi-write
- [`job-queue-with-retry.md`](job-queue-with-retry.md) — async bulk processing via job queue
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — explicit DTO whitelisting for each item
