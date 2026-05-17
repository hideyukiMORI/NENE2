# Problem Details Types

NENE2 returns `application/problem+json` for all error responses, following [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457).
Every error response includes a `type` URI, `title`, `status`, `detail`, and `instance` field.

## Type catalog

| `type` | HTTP status | `title` | Produced by |
|---|---|---|---|
| `…/not-found` | 404 | Not Found | Route not found; Note or Tag with given id not found |
| `…/method-not-allowed` | 405 | Method Not Allowed | Wrong HTTP method for a known route; `Allow` header lists valid methods |
| `…/validation-failed` | 422 | Validation Failed | Invalid request body or missing required fields |
| `…/unauthorized` | 401 | Unauthorized | Missing or invalid bearer token on a protected endpoint |
| `…/payload-too-large` | 413 | Payload Too Large | Request body exceeds the configured size limit |
| `…/internal-server-error` | 500 | Internal Server Error | Unhandled exception; details are not exposed to the client |

Base URI prefix: `https://nene2.dev/problems/`

## Example responses

**404 Not Found:**

```json
{
  "type": "https://nene2.dev/problems/not-found",
  "title": "Not Found",
  "status": 404,
  "detail": "The requested resource was not found.",
  "instance": "/examples/notes/99"
}
```

**422 Validation Failed:**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request contains invalid values.",
  "instance": "/examples/notes",
  "errors": [
    { "field": "title", "message": "Title must not be empty.", "code": "required" }
  ]
}
```

**405 Method Not Allowed:**

```json
{
  "type": "https://nene2.dev/problems/method-not-allowed",
  "title": "Method Not Allowed",
  "status": 405,
  "detail": "The requested resource does not support this HTTP method.",
  "instance": "/examples/notes"
}
```

## Adding a custom type

1. Create a domain exception class (e.g. `ProductNotFoundException`).
2. Implement `DomainExceptionHandlerInterface` — call `ProblemDetailsResponseFactory::create()` with your type slug.
3. Register the handler in `RuntimeServiceProvider`.

See `NoteNotFoundExceptionHandler` and `TagNotFoundExceptionHandler` for concrete examples.
