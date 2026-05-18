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

## See also

- `src/Example/Note/ListNotesHandler.php` — reference implementation using the parser
- `src/Example/Tag/ListTagsHandler.php` — second example
- `Nene2\Http\PaginationQuery` — readonly DTO
- `Nene2\Http\PaginationQueryParser` — the parser class
