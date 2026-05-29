---
title: "How-to: Implement a PATCH Endpoint"
category: api-design
tags: [patch, partial-update, json-merge-patch, dto]
difficulty: intermediate
related: [json-merge-patch, patch-partial-update, optimistic-lock-patch-version]
---

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

## 4. Validating PATCH fields

Validate only the fields that are **present**. Skip validation for absent fields — they won't be
touched. Use nullable parameters in the repository signature to make intent explicit:

```php
$body   = JsonRequestBodyParser::parse($request);
$errors = [];

// Extract only present fields (array_key_exists, not isset)
$amount   = array_key_exists('amount', $body) ? $body['amount'] : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$date     = array_key_exists('date', $body) ? $body['date'] : null;

// Validate only the fields that were sent
if ($amount !== null) {
    if (!is_int($amount) || $amount <= 0) {
        $errors[] = new ValidationError('amount', 'amount must be a positive integer.', 'out_of_range');
    }
}

if ($date !== null) {
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
    }
}

if ($errors !== []) {
    throw new ValidationException($errors);
}

// Call repository with nullable args — repository uses existing value when null
$entity = $this->repository->update(
    id:       $id,
    amount:   is_int($amount) ? $amount : null,
    category: is_string($category) && $category !== '' ? $category : null,
    date:     is_string($date) && $date !== '' ? $date : null,
    now:      (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
);
```

In the repository, use `??` to fall back to the existing value:

```php
public function update(int $id, ?int $amount, ?string $category, ?string $date, string $now): Entity
{
    $existing    = $this->findById($id); // throws NotFoundException when missing
    $newAmount   = $amount   ?? $existing->amount;
    $newCategory = $category ?? $existing->category;
    $newDate     = $date     ?? $existing->date;

    $this->executor->execute(
        'UPDATE entities SET amount = ?, category = ?, date = ?, updated_at = ? WHERE id = ?',
        [$newAmount, $newCategory, $newDate, $now, $id],
    );

    return new Entity($id, $newDate, $newAmount, $newCategory, $existing->createdAt, $now);
}
```

> **Why `array_key_exists` and not `isset`?** `isset($body['field'])` returns `false` for both
> a missing key and a key present with value `null`. For PATCH, that distinction matters:
> "not sent" means "keep the existing value", while `null` may mean "clear this field".
> Always use `array_key_exists` for PATCH field detection.

---

## 5. Repository contract

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
