# Request Validation Policy

NENE2 uses layered request validation with OpenAPI as the public contract and readonly DTOs as the application input boundary.

## Position

Validation should make request handling predictable without turning middleware into hidden controller logic.

The standard direction is:

- Use layered validation.
- Keep OpenAPI as the API contract source of truth.
- Start with contract and schema validation in tests.
- Add runtime OpenAPI validation later when schemas and routes are stable.
- Convert HTTP input into readonly DTOs or command objects before calling use cases.
- Keep business invariants inside use cases and domain services.
- Return validation failures as RFC 9457 Problem Details.

This Issue defines the policy only. Validation packages and implementation should be added in later Issues.

## Layered Validation

Validation responsibilities are split by layer.

```text
Middleware:
  request size / content-type / JSON parse / auth / CORS / request id

Controller or handler:
  path / query / body mapping
  readonly DTO creation
  format validation
  API input semantic validation

Use case:
  business invariants
  authorization-sensitive application rules
  state-dependent rules
```

This keeps each rule close to the layer that understands it.

## Middleware Boundary

Middleware should handle request concerns that apply across routes.

Good middleware validation candidates:

- request size
- content type
- malformed JSON
- authentication and authorization boundary checks
- request id propagation
- CORS
- future runtime OpenAPI validation

Middleware should not contain route-specific business input rules such as "email must be unique" or "campaign must still be editable".

## Controller and Handler Boundary

Controllers and handlers should be thin input mappers.

They should:

- read path parameters, query parameters, headers, and body data from the PSR-7 request
- validate request-local format and semantic rules
- create readonly DTOs or command objects
- call a use case with explicit input
- return a response

They should not pass raw unstructured request arrays deep into use cases.

## DTO and Command Object Policy

Readonly DTOs are the standard use case input boundary.

Recommended shape:

```php
final readonly class CreateUserInput
{
    public function __construct(
        public string $email,
        public string $name,
    ) {
    }
}
```

DTOs should:

- be small and named after the use case or operation
- use native PHP types
- avoid depending on PSR-7 or superglobals
- represent already parsed request values
- include PHPDoc only when it explains public semantics that types cannot express

DTO construction may happen in controllers, handlers, or focused mapper/factory classes when mapping becomes non-trivial.

Example mapper shape:

```php
final readonly class CreateUserInputMapper
{
    public function fromArray(array $data): CreateUserInput
    {
        $errors = [];

        if (($data['email'] ?? '') === '') {
            $errors[] = new ValidationError('email', 'Email is required.', 'required');
        }

        if (($data['name'] ?? '') === '') {
            $errors[] = new ValidationError('name', 'Name is required.', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new CreateUserInput((string) $data['email'], (string) $data['name']);
    }
}
```

`ValidationException` maps to `validation-failed` Problem Details at the HTTP error boundary. Use cases may still throw domain-specific exceptions for business invariants.

## Use Case Boundary

Use cases validate business invariants.

Examples:

- a user cannot register with an email that already exists
- an expired campaign cannot be edited
- an account cannot access another account's private resource
- a state transition is not allowed from the current state

These rules should not be expressed only as HTTP request validation because they must remain true outside HTTP, including CLI, workers, tests, and future MCP-driven workflows.

## OpenAPI Relationship

OpenAPI is the public API contract.

Initial policy:

- document public request shapes in OpenAPI before clients depend on them
- validate OpenAPI documents and examples in tests first
- use shared Problem Details schemas for validation errors
- do not require runtime OpenAPI validation in the first HTTP skeleton

Future runtime OpenAPI validation can be added as PSR-15 middleware after:

- route definitions are stable enough
- OpenAPI schemas are shared and maintained
- error mapping to Problem Details is implemented
- performance and developer experience are understood

## Error Response

Validation failures should use:

```text
422 Unprocessable Content
Content-Type: application/problem+json
```

Problem type:

```text
https://nene2.dev/problems/validation-failed
```

The response should include an `errors` array.

Each item should include:

- `field`: field name, parameter name, or JSON pointer
- `message`: safe human-readable message
- `code`: stable machine-readable code

Example:

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

## Testing

Request validation tests should cover:

- successful DTO creation from valid request data
- missing required fields
- invalid field format
- invalid query or path parameters
- malformed JSON at the middleware boundary
- Problem Details response shape for validation failures
- use case invariant failures separately from request format failures

Contract tests should verify that stable API behavior matches OpenAPI.

## Non-Goals

- Passing raw request arrays throughout the application.
- Putting route-specific business validation into global middleware.
- Requiring runtime OpenAPI validation before the HTTP runtime is stable.
- Generating all DTOs from OpenAPI as the first implementation path.
- Hiding validation behind magic controller resolvers.
