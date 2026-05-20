# Nested JSON Validation

NENE2 does not include a recursive validator. When a request body contains arrays of objects — for example, an order with line items — you validate it yourself with a `foreach` loop and accumulate errors in a structured list.

## Error path convention

Use dot notation with 0-based integer indexes for array elements:

| Path | Meaning |
|---|---|
| `customer` | Top-level field |
| `items` | The array itself (e.g., missing or empty) |
| `items.0.quantity` | `quantity` in the first item |
| `items.2.unit_price` | `unit_price` in the third item |

This format is readable, easy to generate, and easy for clients to map back to their form fields.

## Validator class

Put all validation logic in a dedicated class. Keep the route handler thin — it only calls the validator and maps the result to a response.

```php
final class OrderValidator
{
    /**
     * @param mixed $body
     * @return array{errors: list<array{field: string, code: string, message: string}>}
     *       | array{errors: list<array{field: string, code: string, message: string}>, customer: string, note: string, items: list<array{product_id: int, quantity: int, unit_price: float}>}
     */
    public function validate(mixed $body): array
    {
        if (!is_array($body)) {
            return ['errors' => [['field' => '', 'code' => 'invalid-body', 'message' => 'Request body must be a JSON object.']]];
        }

        $errors = [];

        // --- top-level ---
        $customer = isset($body['customer']) && is_string($body['customer']) ? trim($body['customer']) : '';
        if ($customer === '') {
            $errors[] = ['field' => 'customer', 'code' => 'required', 'message' => 'Customer name is required.'];
        } elseif (strlen($customer) > 200) {
            $errors[] = ['field' => 'customer', 'code' => 'too-long', 'message' => 'Customer name must not exceed 200 characters.'];
        }

        // --- items array ---
        if (!isset($body['items'])) {
            $errors[] = ['field' => 'items', 'code' => 'required', 'message' => 'items is required.'];
        } elseif (!is_array($body['items']) || !array_is_list($body['items'])) {
            $errors[] = ['field' => 'items', 'code' => 'must-be-array', 'message' => 'items must be an array.'];
        } elseif (count($body['items']) === 0) {
            $errors[] = ['field' => 'items', 'code' => 'min-items', 'message' => 'Order must contain at least one item.'];
        } elseif (count($body['items']) > 50) {
            $errors[] = ['field' => 'items', 'code' => 'max-items', 'message' => 'Order may not contain more than 50 items.'];
        } else {
            // --- per-item validation ---
            foreach ($body['items'] as $i => $item) {
                $prefix = "items.{$i}"; // dot-notation path prefix

                if (!is_array($item)) {
                    $errors[] = ['field' => $prefix, 'code' => 'must-be-object', 'message' => "{$prefix} must be an object."];
                    continue; // skip field checks — no point if the item itself is wrong
                }

                // product_id: required integer ≥ 1
                if (!isset($item['product_id']) || !is_int($item['product_id'])) {
                    $errors[] = ['field' => "{$prefix}.product_id", 'code' => 'required', 'message' => "{$prefix}.product_id must be an integer."];
                } elseif ($item['product_id'] < 1) {
                    $errors[] = ['field' => "{$prefix}.product_id", 'code' => 'min-value', 'message' => "{$prefix}.product_id must be ≥ 1."];
                }

                // quantity: required integer 1–9999
                if (!isset($item['quantity']) || !is_int($item['quantity'])) {
                    $errors[] = ['field' => "{$prefix}.quantity", 'code' => 'required', 'message' => "{$prefix}.quantity must be an integer."];
                } elseif ($item['quantity'] < 1) {
                    $errors[] = ['field' => "{$prefix}.quantity", 'code' => 'min-value', 'message' => "{$prefix}.quantity must be ≥ 1."];
                } elseif ($item['quantity'] > 9999) {
                    $errors[] = ['field' => "{$prefix}.quantity", 'code' => 'max-value', 'message' => "{$prefix}.quantity must be ≤ 9999."];
                }

                // unit_price: required number > 0 (JSON gives int OR float)
                $rawPrice = $item['unit_price'] ?? null;
                if (!is_int($rawPrice) && !is_float($rawPrice)) {
                    $errors[] = ['field' => "{$prefix}.unit_price", 'code' => 'required', 'message' => "{$prefix}.unit_price must be a number."];
                } elseif ($rawPrice <= 0) {
                    $errors[] = ['field' => "{$prefix}.unit_price", 'code' => 'min-value', 'message' => "{$prefix}.unit_price must be > 0."];
                }
            }
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        return [
            'errors'   => [],
            'customer' => $customer,
            'note'     => isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '',
            'items'    => array_map(
                static fn (array $item) => [
                    'product_id' => (int) $item['product_id'],
                    'quantity'   => (int) $item['quantity'],
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                ],
                (array) $body['items'],
            ),
        ];
    }
}
```

## Route handler

```php
private function createOrder(ServerRequestInterface $request): ResponseInterface
{
    $body   = json_decode((string) $request->getBody(), true);
    $result = $this->validator->validate($body);

    if ($result['errors'] !== []) {
        return $this->problems->create(
            $request,
            'validation-failed',
            'Validation failed.',
            422,
            null,
            ['errors' => $result['errors']],
        );
    }

    // PHPStan level 8: the union type doesn't narrow automatically after the early return.
    // Use a @var annotation to tell PHPStan which shape remains.
    /** @var array{customer: string, note: string, items: list<array{product_id: int, quantity: int, unit_price: float}>} $valid */
    $valid = $result;
    $order = $this->repo->create($valid['customer'], $valid['note'], $valid['items']);
    return $this->json->create($order->toArray(), 201);
}
```

## Error response shape

All errors are collected before returning — clients get everything wrong in a single response:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed.",
  "status": 422,
  "instance": "/orders",
  "errors": [
    {
      "field": "customer",
      "code": "required",
      "message": "Customer name is required."
    },
    {
      "field": "items.0.quantity",
      "code": "min-value",
      "message": "items.0.quantity must be ≥ 1."
    },
    {
      "field": "items.2.unit_price",
      "code": "required",
      "message": "items.2.unit_price must be a number."
    }
  ]
}
```

## Design decisions

### Collect all errors, not fail-fast

Fail-fast (return on the first error) is simpler to implement but forces clients to iterate: fix one error, submit, discover the next, and so on. Collecting all errors in a single pass means one round-trip for the client to fix everything.

The tradeoff: if item `0` itself is not an object, skip the field checks for that item with `continue` — there is nothing useful to report inside a non-object.

### Numeric keys in paths

Use `0`-based integer indexes matching the array position: `items.0`, `items.1`. Avoid brackets (`items[0]`) — dot notation is simpler to generate and parse, and many client-side validation libraries support it.

### PHPStan and discriminated unions

When a function returns a union of shapes (one with extra keys on success, one without on error), PHPStan level 8 does not automatically narrow after an early return. Use a `@var` annotation at the call site to assert the narrower type:

```php
/** @var array{customer: string, ...} $valid */
$valid = $result;
```

This is the recommended workaround until PHPStan adds full control-flow narrowing for array shapes.

### `array_is_list()` vs `is_array()`

`is_array()` alone accepts `{"0": "x", "1": "y"}` — a JSON object with numeric string keys — as an array. `array_is_list()` additionally requires 0-based sequential integer keys, rejecting associative arrays disguised as lists.

### Numeric types in JSON

JSON has one number type. PHP's `json_decode` maps integers to `int` and decimals to `float`. To accept both for a price field:

```php
$rawPrice = $item['unit_price'] ?? null;
if (!is_int($rawPrice) && !is_float($rawPrice)) {
    // reject strings, booleans, null
}
```

Never use `is_numeric()` here — it accepts `"9.99"` (a string), which would pass but is semantically wrong.
