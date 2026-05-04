# API Error Response Policy

NENE2 uses RFC 9457 Problem Details as the standard JSON API error response format.

## Position

API errors are public contracts. They should be stable, documented, and easy for frontend clients, MCP tools, tests, and AI agents to understand.

The standard direction is:

- Use RFC 9457 Problem Details for public JSON API errors.
- Keep internal exceptions separate from public error responses.
- Add OpenAPI shared schemas before shipping stable error behavior.
- Use a structured `errors` array for validation details.
- Avoid leaking secrets, stack traces, SQL, file paths, or private identifiers.

This Issue defines the policy only. OpenAPI schemas and runtime implementation should be added in later Issues.

## Base Shape

The default error response should use `application/problem+json`.

```json
{
  "type": "https://nene2.dev/problems/not-found",
  "title": "Not Found",
  "status": 404,
  "detail": "The requested resource was not found.",
  "instance": "/example"
}
```

## Required Fields

For NENE2 public API errors:

- `type`: stable problem identifier URI
- `title`: short human-readable summary
- `status`: HTTP status code

Optional fields:

- `detail`: safe human-readable detail
- `instance`: request-specific URI or identifier
- `errors`: validation error details

## Problem Type Policy

Problem `type` values should be stable and documented. They should not be raw exception class names.

Recommended pattern:

```text
https://nene2.dev/problems/{problem-name}
```

Examples:

- `https://nene2.dev/problems/not-found`
- `https://nene2.dev/problems/method-not-allowed`
- `https://nene2.dev/problems/validation-failed`
- `https://nene2.dev/problems/unauthorized`
- `https://nene2.dev/problems/forbidden`
- `https://nene2.dev/problems/internal-server-error`

## Validation Errors

Validation failures should use the base Problem Details shape and include an `errors` array.

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    {
      "field": "email",
      "message": "Email must be a valid email address.",
      "code": "invalid_email"
    }
  ]
}
```

Each validation error item should include:

- `field`: field name, parameter name, or JSON pointer
- `message`: safe human-readable message
- `code`: stable machine-readable code

The final validation rule mapping follows the layered request validation policy. See `docs/development/request-validation.md`.

## Exception Boundary

Internal exceptions are not the API contract.

The error handler should map exceptions to public Problem Details responses:

- known domain/application exceptions become stable public problem types
- routing failures become `not-found` or `method-not-allowed`
- validation failures become `validation-failed`
- unexpected exceptions become `internal-server-error`

Unexpected errors must not expose stack traces in public responses.

## OpenAPI Policy

OpenAPI should eventually define shared schemas for:

- `ProblemDetails`
- `ValidationProblemDetails`
- `ValidationError`

Stable endpoints should reference these schemas for non-2xx responses.

## Testing

Error response tests should verify:

- status code
- `Content-Type: application/problem+json`
- problem `type`
- safe `title` and `detail`
- validation `errors` structure when applicable

Avoid asserting full stack traces or environment-specific details.
