# Endpoint Scaffold Workflow

This workflow keeps new NENE2 JSON endpoints aligned across runtime code, OpenAPI, tests, and optional MCP metadata.

It is intentionally manual for now. The goal is to make the steps clear before adding code generation.

## Position

An endpoint is complete only when its behavior is visible in all relevant places:

- runtime route and handler
- OpenAPI path, response schema, and examples
- runtime or handler tests
- contract tests through `docs/openapi/openapi.yaml`
- MCP catalog updates when the endpoint should become a tool
- documentation updates when the endpoint changes project policy or workflow

## Standard Steps

1. Create or reuse a focused GitHub Issue.
2. Add or update the runtime route in the smallest appropriate handler boundary.
3. Add the OpenAPI path with `operationId`, examples, success schema, and Problem Details responses.
4. Add or update tests close to the behavior.
5. Run the focused tests first, then `docker compose run --rm app composer check`.
6. Run a local HTTP smoke check when the endpoint is reachable through Docker.
7. Update `docs/todo/current.md`, milestone docs, or MCP catalog only when the endpoint affects current work.

## Runtime Route

For the current small runtime, example routes live in `RuntimeApplicationFactory`.

The scaffold example is:

```text
GET /examples/ping
```

It returns:

```json
{
  "message": "pong",
  "status": "ok"
}
```

This endpoint exists to exercise the workflow. Application endpoints should move toward thin handlers or use cases as behavior grows.

## OpenAPI Requirements

Each shipped JSON endpoint should include:

- stable `operationId`
- short summary and safe description
- `200` response schema and `ok` example
- appropriate Problem Details responses such as `401`, `413`, and `500`
- security requirements only when matching middleware exists

Runtime contract tests automatically read `docs/openapi/openapi.yaml` and verify documented `200` examples for JSON endpoints.

## Testing Requirements

Use the narrowest useful checks first:

```bash
docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
```

Then run the full backend check:

```bash
docker compose run --rm app composer check
```

For endpoints served by Docker, run a local smoke check:

```bash
curl -i http://localhost:8080/examples/ping
```

## MCP Relationship

If a new endpoint should become an MCP tool:

1. Add the OpenAPI operation first.
2. Add a read-only entry to `docs/mcp/tools.json` only when the tool can safely call the public API boundary.
3. Run `docker compose run --rm app composer mcp`.
4. Keep mutation, admin, and destructive tools out of scope until authentication, authorization, and audit behavior are documented and implemented.

## Non-Goals

- Generating endpoint files automatically before the manual workflow proves useful.
- Hiding route behavior behind magic controller discovery.
- Updating MCP catalog for every endpoint by default.
- Requiring runtime OpenAPI validation before the route and schema patterns stabilize.

## Related Work

- Runtime: `src/Http/RuntimeApplicationFactory.php`
- OpenAPI: `docs/openapi/openapi.yaml`
- Runtime contract tests: `tests/OpenApi/RuntimeContractTest.php`
- Request validation policy: `docs/development/request-validation.md`
- MCP tool policy: `docs/integrations/mcp-tools.md`
