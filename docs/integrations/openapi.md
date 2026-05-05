# OpenAPI Policy

OpenAPI is the contract layer for NENE2 APIs.

## Goals

- Make API behavior explicit before frontend or MCP clients depend on it.
- Keep request, response, pagination, and error shapes stable.
- Enable tests and client generation where useful.
- Make the API understandable to humans and AI agents.

## Principles

- Public JSON APIs should have OpenAPI coverage.
- Error responses should use RFC 9457 Problem Details and shared schemas where possible.
- Examples should include at least one success and one failure case for important endpoints.
- OpenAPI documents should describe shipped behavior, not aspirational behavior.
- Contract changes should be reviewed as public API changes.

## Structure

The source of truth lives in `docs/openapi/`:

```text
docs/openapi/
├── openapi.yaml
└── examples/
```

`public_html/openapi.php` exposes `docs/openapi/openapi.yaml` to local tools and Swagger UI without duplicating the contract file.

Swagger UI is served from:

```text
http://localhost:8080/docs/
```

The raw OpenAPI contract is served from:

```text
http://localhost:8080/openapi.php
```

## Local Verification

Start Docker and open Swagger UI:

```bash
docker compose up -d app
```

Then verify:

- `http://localhost:8080/` returns the smoke JSON response.
- `http://localhost:8080/openapi.php` returns the OpenAPI YAML.
- `http://localhost:8080/docs/` loads Swagger UI.

Validate the contract file locally:

```bash
docker compose run --rm app composer openapi
```

`composer check` includes this OpenAPI validation step.

## Testing Direction

Runtime contract tests should verify shipped JSON responses against documented examples and required schema fields.
The first lightweight checks live in `tests/OpenApi/RuntimeContractTest.php` and should grow toward fuller JSON Schema validation only when that extra precision reduces maintenance risk.

Shared schemas for `ProblemDetails`, `ValidationProblemDetails`, and `ValidationError` live in `docs/openapi/openapi.yaml`. Stable endpoints should reference these schemas for non-2xx JSON responses where the behavior is implemented. See `docs/development/api-error-responses.md`.

OpenAPI security schemes should be added only when the matching middleware or authentication adapter is implemented. See `docs/development/middleware-security.md`.

Runtime OpenAPI request validation should be added after the HTTP runtime and schemas are stable. The first validation step should be contract and schema validation in tests. See `docs/development/request-validation.md`.
