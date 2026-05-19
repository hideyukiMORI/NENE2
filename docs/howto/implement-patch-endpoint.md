# How-to: Implement a PATCH Endpoint

PATCH is for **partial updates**: only the fields the client sends should change.
This requires distinguishing three states for every field:

| State | Meaning |
|---|---|
| Key absent from body | Do not touch this field |
| Key present, value non-null | Update to the new value |
| Key present, value `null` | Clear the field (set to null) |

`isset()` cannot tell apart "absent" and "explicit null" — both return `false`.
Use `array_key_exists()` instead.

---

## 1. Parse the body and extract only the fields that are present

```php
$body   = JsonRequestBodyParser::parse($request);   // array<string, mixed>
$fields = [];

if (array_key_exists('title', $body)) {
    $fields['title'] = is_string($body['title']) ? trim($body['title']) : null;
}
if (array_key_exists('is_read', $body)) {
    $fields['is_read'] = (bool) $body['is_read'];
}
```

Pass `$fields` to your repository's `update()` method. If `$fields` is empty the
call is still valid — respond with the current state of the resource.

---

## 2. Route registration

```php
$router->patch(
    '/entries/{id}',
    static function (ServerRequestInterface $request) use ($entries, $json): ResponseInterface {
        /** @var array<string, string> $params */
        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id     = (int) ($params['id'] ?? 0);

        $body   = JsonRequestBodyParser::parse($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = $body['title'];
        }
        if (array_key_exists('is_read', $body)) {
            $fields['is_read'] = (bool) $body['is_read'];
        }

        $entry = $entries->update($id, $fields) ?? throw new EntryNotFoundException($id);

        return $json->create(self::payload($entry));
    },
);
```

---

## 3. Sending an empty PATCH body

To send a PATCH with no fields (a no-op that returns the current state), you must
send a JSON **object**, not an array.

```php
// WRONG: json_encode([]) === "[]"  → 400 Bad Request (JSON array)
$request->withBody($stream->write(json_encode([])));

// CORRECT: json_encode((object)[]) === "{}"  → 200 OK (JSON object)
$request->withBody($stream->write(json_encode((object)[])));
```

In test helpers, pass `new \stdClass()` as the body:

```php
// In PHPUnit tests
$response = $this->request('PATCH', "/entries/{$id}", new \stdClass());
```

This is because `JsonRequestBodyParser` rejects JSON arrays (see the `JsonBodyParseException`
message for details). An empty PHP array `[]` encodes to the JSON array `[]`, not the
JSON object `{}`.

---

## 4. Repository contract

Your repository's `update()` should accept only the fields passed in and return
the updated entity (or `null` when not found):

```php
/** @param array<string, mixed> $fields */
public function update(int $id, array $fields): ?Entry
{
    if ($fields === []) {
        return $this->findById($id);   // no-op: return current state
    }

    $setClauses = implode(', ', array_map(fn (string $k): string => "{$k} = ?", array_keys($fields)));
    $params     = [...array_values($fields), $id];

    $affected = $this->executor->execute(
        "UPDATE entries SET {$setClauses} WHERE id = ?",
        $params,
    );

    return $affected > 0 ? $this->findById($id) : null;
}
```

---

## 5. Related how-tos

- [`add-pagination.md`](add-pagination.md) — GET with `PaginationQueryParser`
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — 404 handler for missing resources
