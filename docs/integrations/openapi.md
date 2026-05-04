# OpenAPI Policy

OpenAPI is the contract layer for NENE2 APIs.

## Goals

- Make API behavior explicit before frontend or MCP clients depend on it.
- Keep request, response, pagination, and error shapes stable.
- Enable tests and client generation where useful.
- Make the API understandable to humans and AI agents.

## Principles

- Public JSON APIs should have OpenAPI coverage.
- Error responses should use shared schemas where possible.
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

## Testing Direction

Once runtime code exists, API tests should verify that responses match the documented contract. The first implementation can be lightweight and grow with the framework.
