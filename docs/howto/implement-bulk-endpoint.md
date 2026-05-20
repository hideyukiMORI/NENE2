# How-to: Implement a Bulk Create Endpoint

A bulk endpoint accepts multiple resources in a single request — reducing round trips for
batch imports, score submissions, and similar workflows. This guide covers the complete
pattern: parsing, per-item validation with indexed error fields, size limiting, and the route.

---

## 1. Schema

The request body wraps items in a named array key so the envelope can carry metadata:

```json
{
  "scores": [
    { "player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15" },
    { "player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16" }
  ]
}
```

The response returns the created count and the created items:

```json
{ "created": 2, "scores": [ /* ... */ ] }
```

---

## 2. Route

Register the bulk route **before** the parameterised single-resource route to avoid shadowing
(see [add-custom-route.md](add-custom-route.md)):

```php
$router->post('/scores/bulk', $this->bulkSubmit(...)); // static first
$router->post('/scores/{id}', $this->show(...));        // parameterised after
```

---

## 3. Handler

```php
private function bulkSubmit(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    // 1. Validate the envelope
    if (!isset($body['scores']) || !is_array($body['scores'])) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must be a non-empty array.', 'required'),
        ]);
    }

    /** @var array<mixed> $entriesRaw */
    $entriesRaw = $body['scores'];

    if (count($entriesRaw) === 0) {
        throw new ValidationException([
            new ValidationError('scores', 'scores must contain at least one entry.', 'required'),
        ]);
    }

    // 2. Enforce size limit before iterating
    if (count($entriesRaw) > 100) {
        throw new ValidationException([
            new ValidationError('scores', 'scores may contain at most 100 entries per request.', 'out_of_range'),
        ]);
    }

    // 3. Validate each entry, prefixing field names with the index
    $allErrors = [];
    $entries   = [];

    foreach ($entriesRaw as $i => $entry) {
        if (!is_array($entry)) {
            $allErrors[] = new ValidationError("scores[{$i}]", 'Each entry must be an object.', 'invalid_type');
            continue;
        }

        /** @var array<string, mixed> $entry */
        $entryErrors = $this->validateEntry($entry, "scores[{$i}].");
        if ($entryErrors !== []) {
            $allErrors = [...$allErrors, ...$entryErrors];
        } else {
            $entries[] = $entry;
        }
    }

    // 4. Fail the whole request if any entry is invalid
    if ($allErrors !== []) {
        throw new ValidationException($allErrors);
    }

    // 5. Persist all entries and return
    $now     = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    $created = $this->repository->bulkCreate($entries, $now);

    return $this->json->create([
        'created' => count($created),
        'scores'  => array_map(fn ($s) => $this->serialize($s), $created),
    ], 201);
}
```

---

## 4. Per-item validation with indexed field names

Use a private helper that accepts a `string $prefix` argument. The prefix is `"scores[{$i}]."`:

```php
/**
 * @param array<string, mixed> $body
 * @return list<ValidationError>
 */
private function validateEntry(array $body, string $prefix = ''): array
{
    $errors = [];

    if (!isset($body['player']) || !is_string($body['player']) || $body['player'] === '') {
        $errors[] = new ValidationError($prefix . 'player', 'player is required.', 'required');
    }

    if (!isset($body['score']) || !is_int($body['score'])) {
        $errors[] = new ValidationError($prefix . 'score', 'score is required (integer).', 'required');
    } elseif ($body['score'] < 0) {
        $errors[] = new ValidationError($prefix . 'score', 'score must be 0 or greater.', 'out_of_range');
    }

    return $errors;
}
```

**Why `$prefix`?** `ValidationError` accepts any string as the field name. Passing
`"scores[0]."` as a prefix produces error fields like `"scores[0].player"` — making it
immediately clear which entry and field failed. A single prefix argument is enough; no
framework change is needed.

The resulting 422 response body:

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "errors": [
    { "field": "scores[1].player", "message": "player is required.", "code": "required" }
  ]
}
```

---

## 5. Repository contract

Accept a list of pre-validated entries and return the created entities:

```php
/**
 * @param list<array{player: string, game: string, score: int, played_at: string}> $entries
 * @return list<Score>
 */
public function bulkCreate(array $entries, string $now): array
{
    $results = [];
    foreach ($entries as $entry) {
        $results[] = $this->create($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
    }
    return $results;
}
```

> **Atomicity**: The loop above inserts one row at a time. Wrap in
> `DatabaseTransactionManagerInterface::transactional()` if you need all-or-nothing
> behaviour — see [use-transactions.md](use-transactions.md).

---

## 6. Related how-tos

- [`add-pagination.md`](add-pagination.md) — list endpoint pattern
- [`use-transactions.md`](use-transactions.md) — wrap bulk inserts in a transaction
- [`add-domain-exception-handler.md`](add-domain-exception-handler.md) — domain-specific 404/409
