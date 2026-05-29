---
title: Add pagination
category: api-design
tags: [pagination, query-params]
difficulty: beginner
related: [pagination, dynamic-filter-query]
---

# Add pagination

This guide shows how to add `?limit=` / `?offset=` pagination to a collection endpoint using the
`PaginationQueryParser` helper from `Nene2\Http`.

## Prerequisites

- A working collection handler (e.g. `ListNotesHandler`).
- The handler returns a JSON envelope with `items`, `limit`, and `offset`.

## Step 1 — Call `PaginationQueryParser::parse()`

Replace manual query-param extraction with the parser. It validates the values and throws
`ValidationException` (→ 422) when they are out of range.

```php
use Nene2\Http\PaginationQueryParser;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request); // default: limit=20, max=100

    $output = $this->useCase->execute(
        new ListWidgetsInput($pagination->limit, $pagination->offset),
    );

    return $this->response->create([
        'items'  => /* map $output->items */,
        'limit'  => $output->limit,
        'offset' => $output->offset,
    ]);
}
```

`PaginationQuery` is a readonly DTO with two properties: `limit: int` and `offset: int`.

## Step 2 — Customise limits (optional)

Pass `$defaultLimit` and `$maxLimit` to override the defaults (20 and 100):

```php
$pagination = PaginationQueryParser::parse($request, defaultLimit: 10, maxLimit: 50);
```

| Parameter | Default | Meaning |
|---|---|---|
| `$defaultLimit` | `20` | Returned when `?limit=` is absent |
| `$maxLimit` | `100` | Maximum allowed value; returns 422 if exceeded |

## Step 3 — Handle the 422 error

`PaginationQueryParser::parse()` throws `ValidationException` when:

- `limit < 1` or `limit > $maxLimit`
- `offset < 0`

`ErrorHandlerMiddleware` maps `ValidationException` → `422 validation-failed` automatically.
No additional error handling is needed in the handler.

**Example 422 response:**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    { "field": "limit", "message": "limit must be between 1 and 100.", "code": "out_of_range" }
  ]
}
```

## How it works

`PaginationQueryParser::parse()` reads `getQueryParams()` from the PSR-7 request, casts values to
`int`, validates them, and returns a `PaginationQuery` DTO. Non-numeric values are coerced to `0`
(PHP's `(int)` casting behaviour) and then caught by the `limit < 1` check.

## Step 4 — Use `PaginationResponse` to standardise the envelope

`PaginationResponse` is a readonly DTO that builds the standard list envelope. Use it instead of
constructing the array manually:

```php
use Nene2\Http\PaginationResponse;

return $this->response->create(
    (new PaginationResponse(
        items:  array_map(fn ($item) => ['id' => $item->id, 'name' => $item->name], $output->items),
        limit:  $output->limit,
        offset: $output->offset,
    ))->toArray(),
);
```

`toArray()` returns `['items' => ..., 'limit' => ..., 'offset' => ...]`.

## Step 5 — Include the total record count (optional)

Pass `total` to `PaginationResponse` when the repository supports a count query. Clients use
this to determine the last page without an extra request.

```php
// In the repository:
public function countAll(): int
{
    $rows = $this->query->select('SELECT COUNT(*) AS n FROM widgets', []);
    return (int) ($rows[0]['n'] ?? 0);
}

// In the handler:
$total = $this->repository->countAll();

return $this->response->create(
    (new PaginationResponse(
        items:  /* ... */,
        limit:  $output->limit,
        offset: $output->offset,
        total:  $total,
    ))->toArray(),
);
```

The resulting response:

```json
{
    "items":  [ /* ... */ ],
    "limit":  20,
    "offset": 0,
    "total":  42
}
```

When `total` is `null` (the default), the key is omitted from the response.

> **Trade-off**: `COUNT(*)` adds one extra query per request. Omit `total` when the collection
> is large and the overhead is unacceptable, and instruct clients to detect the last page by
> checking `items.length < limit`.

## Step 6 — Parse other query parameters with `QueryStringParser`

Use `QueryStringParser` for additional filter parameters beyond `limit`/`offset`.

```php
use Nene2\Http\QueryStringParser;

$search = QueryStringParser::string($request, 'search');   // ?string
$page   = QueryStringParser::int($request, 'page');        // ?int
$active = QueryStringParser::bool($request, 'is_active');  // ?bool

// Comma-separated multi-value: ?tags=php,lang → ['php', 'lang']
$tags = QueryStringParser::commaSeparated($request, 'tags'); // list<string>|null

// PHP-style repeated key: ?tags[]=php&tags[]=api → ['php', 'api']
$tags = QueryStringParser::array($request, 'tags'); // list<string>|null
```

`commaSeparated()` splits on commas, trims whitespace, removes empty values, and returns `null`
when the parameter is absent or produces an empty list after filtering.

`array()` handles PHP-style repeated keys (`?key[]=v1&key[]=v2`). PSR-7 implementations parse
these into `['key' => ['v1', 'v2']]` in `getQueryParams()`. Returns `null` when the key is absent
or the value is not an array.

## See also

- `src/Example/Note/ListNotesHandler.php` — reference implementation using `PaginationResponse`
- `src/Example/Tag/ListTagsHandler.php` — second example
- `Nene2\Http\PaginationQuery` — readonly DTO for parsed parameters
- `Nene2\Http\PaginationQueryParser` — the parser class
- `Nene2\Http\PaginationResponse` — the list-envelope DTO
- `Nene2\Http\QueryStringParser` — typed helpers for other query parameters
