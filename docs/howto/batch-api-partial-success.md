# Batch Write API & Partial Success Pattern

**FT182** — `batchlog` field trial.

When clients submit an array of items in a single request, some items may be
valid and others invalid. Rejecting the whole batch on any error wastes the
valid items; silently skipping errors hides bugs. The _partial success_ pattern
accepts what it can and reports what it cannot — per item, by index.

---

## The Core Problem

JSON array bodies introduce two validation layers:

1. **Batch-level** — is the overall request shape valid? (key present? is it a
   list? is the count in range?)
2. **Item-level** — is each individual element valid? (type? range? required
   fields?)

Treating both layers the same way leads to either over-rejection (one bad item
kills the whole batch) or over-acceptance (bad items silently ignored).

---

## HTTP Conventions

| Scenario | Status | Body |
|---|---|---|
| Batch-level error (missing key, wrong type, empty, oversized) | `422` | `{"error": "..."}` |
| Item-level errors only / mixed success+error | `200` | `{created, errors, total_created, total_errors}` |
| All items valid | `200` | `{created: [...], errors: [], ...}` |
| All items invalid | `200` | `{created: [], errors: [...], ...}` |

**Why 200 for all-invalid?** The batch operation itself succeeded — the server
processed every item and made a decision on each. The caller knows what happened
by inspecting `total_created` and `errors`. Using 422 for "some items invalid"
would conflate two different kinds of failure.

---

## V::bodyInt() — Strict JSON Type Enforcement

`V::bodyInt()` is the key tool for catching JSON type confusion in batch
payloads. PHP's `json_decode` preserves JSON types, but callers may send wrong
types by accident (or on purpose).

```php
// V::bodyInt(mixed $raw, int $min, int $max): ?int
V::bodyInt(5, 1, 999)         // → 5        ✓ PHP int
V::bodyInt("5", 1, 999)       // → null     ✗ JSON type confusion: "5" not 5
V::bodyInt(5.5, 1, 999)       // → null     ✗ float
V::bodyInt(true, 1, 999)      // → null     ✗ bool
V::bodyInt(null, 1, 999)      // → null     ✗ null
V::bodyInt([5], 1, 999)       // → null     ✗ array
```

The critical difference from query strings: `V::queryInt()` accepts the string
`"5"` (because query parameters are always strings), while `V::bodyInt()`
requires a PHP `int` (because JSON distinguishes `5` from `"5"`).

**ATK-07 type confusion attack** — sending `{"quantity": "5"}` instead of
`{"quantity": 5}` must fail. `is_int()` is the only safe check.

---

## Batch Validation Logic

```php
// 1. Parse body (fallback to [] on non-object JSON)
$body = json_decode((string) $request->getBody(), true);
$body = is_array($body) ? $body : [];

// 2. Batch-level guards → 422
if (!array_key_exists('items', $body)) {
    return 422; // key missing
}
$rawItems = $body['items'];
if (!is_array($rawItems)) {
    return 422; // not an array
}
if (count($rawItems) === 0) {
    return 422; // empty
}
if (count($rawItems) > MAX_BATCH) {
    return 422; // oversized
}

// 3. Per-item processing → 200 with errors[]
$created = [];
$errors  = [];

foreach ($rawItems as $index => $rawItem) {
    $intIndex = (int) $index;

    // Each item must be a JSON object (assoc array), not a scalar or list
    if (!is_array($rawItem) || array_is_list($rawItem)) {
        $errors[] = ['index' => $intIndex, 'error' => 'Each item must be a JSON object.'];
        continue;
    }

    $name = V::str($rawItem['name'] ?? null, 100);
    if ($name === null || $name === '') {
        $errors[] = ['index' => $intIndex, 'error' => 'name is required (max 100 chars).'];
        continue;
    }

    $quantity = V::bodyInt($rawItem['quantity'] ?? null, 1, 999);
    if ($quantity === null) {
        $errors[] = ['index' => $intIndex, 'error' => 'quantity must be an integer between 1 and 999.'];
        continue;
    }

    // … more fields …

    $item      = $repository->create(/* ... */);
    $created[] = $item->toArray();
}

// 4. Always 200; caller reads total_created / total_errors
return 200 with [
    'created'       => $created,
    'errors'        => $errors,
    'total_created' => count($created),
    'total_errors'  => count($errors),
];
```

---

## array_is_list() — JSON Object vs JSON Array at Item Level

PHP `json_decode` maps JSON objects to associative arrays and JSON arrays to
list arrays. Use `array_is_list()` to distinguish them at the item level:

```php
// JSON body: {"items": [{"name": "foo"}, "bar", 42, [1,2]]}
is_array(["name" => "foo"])   // true — valid JSON object
array_is_list(["name" => "foo"]) // false — associative → object ✓

is_array("bar")                  // false → caught by is_array check
is_array(42)                     // false → caught
is_array([1, 2])                 // true
array_is_list([1, 2])            // true → rejected: list ≠ object ✗
```

The guard `!is_array($rawItem) || array_is_list($rawItem)` catches scalars,
JSON arrays, and anything else that isn't a plain JSON object.

---

## MAX_BATCH Size Guard

Without an upper limit, a caller could send thousands of items in one request,
consuming unbounded memory and CPU.

```php
const MAX_BATCH = 50; // tune for your use case

if (count($rawItems) > self::MAX_BATCH) {
    return $this->responseFactory->create(
        ['error' => sprintf('"items" must contain at most %d entries.', self::MAX_BATCH)],
        422,
    );
}
```

Reject at the batch level (422) before iterating — don't count errors per item
for an oversized batch.

---

## Error Index Preservation

Report the original input index in each error so clients can correlate errors
with the items they submitted, even when the array indices are non-sequential
(e.g., after client-side filtering):

```php
// Input:  [valid, invalid, valid, invalid]
// Output errors: [{index: 1, error: "..."}, {index: 3, error: "..."}]
```

Always cast the index to `int` explicitly — `foreach` keys can be `string` when
the PHP array was built from non-sequential JSON:

```php
$intIndex = (int) $index;
```

---

## Response Schema

```json
{
  "created": [
    {"id": 1, "user_id": 1, "name": "Widget A", "quantity": 3, "price_cents": 999, "created_at": "..."},
    {"id": 2, "user_id": 1, "name": "Widget B", "quantity": 1, "price_cents": 4999, "created_at": "..."}
  ],
  "errors": [
    {"index": 1, "error": "quantity must be an integer between 1 and 999."},
    {"index": 3, "error": "name is required (max 100 chars)."}
  ],
  "total_created": 2,
  "total_errors": 2
}
```

---

## Idempotency Consideration

Partial success creates a write-then-error scenario. If the client retries the
full batch after a network failure, previously created items may be duplicated.
Options:

- **Idempotency key**: include a client-generated UUID per batch; server stores
  it and deduplicates.
- **Client deduplication**: client tracks which indices succeeded and only
  re-submits failed items.
- **Natural uniqueness**: use a unique constraint (e.g., external ID) and treat
  duplicate-key errors as success.

The `batchlog` FT uses the simplest approach (no idempotency key) for clarity.
Production batch APIs should implement one of the above strategies.

---

## Security Notes

- **V::bodyInt() for all numeric fields** — reject strings, floats, bools, null
  in JSON body.
- **V::str() for string fields** — rejects non-string, trims, checks length;
  check `=== ''` for required fields after trim.
- **User scoping** — each item is bound to the authenticated user ID from the
  header (`V::userId()`), never from the request body.
- **MAX_BATCH guard** — 422 before iterating to prevent DoS via oversized batches.

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Batch-level error | 422 — whole request rejected |
| Item-level error | 200 — report index + message in `errors[]` |
| Type confusion in JSON | `V::bodyInt()` / `is_int()` — not `is_numeric()` |
| JSON object vs array | `!is_array() \|\| array_is_list()` — reject both |
| Size DoS | `count($items) > MAX_BATCH` → 422 before iteration |
| Error correlation | Preserve original `$index` in error response |
