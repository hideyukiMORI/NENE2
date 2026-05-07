# OpenAPI to MCP Catalog Direction

NENE2 currently keeps the MCP tool catalog in `docs/mcp/tools.json`.

The catalog is hand-maintained while the public API surface is small. When the OpenAPI document grows, NENE2 should move toward generated MCP metadata so tools stay aligned with the public API contract.

## Position

OpenAPI is the source of truth for public JSON API operations. MCP catalog generation should derive tool shape from `docs/openapi/openapi.yaml` and then apply explicit MCP safety policy.

Generation should not create hidden behavior. A generated MCP tool still represents an existing API operation, documented application service, or documented local maintenance command.

## Generation Inputs

The first generator should read:

- OpenAPI `paths`
- operation `operationId`
- operation `summary` and `description`
- HTTP method and path
- request parameters and request body schemas
- success response schema references
- Problem Details response references
- OpenAPI tags

If an operation has no stable `operationId`, it should not become a generated MCP tool.

## Output Shape

Generated entries should keep the existing catalog shape:

```json
{
  "name": "getHealth",
  "title": "Health",
  "description": "Read the health endpoint through the documented public API.",
  "safety": "read",
  "source": {
    "type": "openapi",
    "operationId": "getHealth",
    "method": "GET",
    "path": "/health"
  },
  "inputSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  },
  "responseSchemaRef": "#/components/schemas/HealthResponse"
}
```

The catalog may stay committed if reviewable diffs are useful. If the generated output becomes noisy, the project should instead commit the generator and validate generated output in CI.

## Safety Defaults

Generation must default conservatively:

- `GET`, `HEAD`, and clearly read-only operations may become `read` tools.
- `POST`, `PUT`, `PATCH`, and `DELETE` must not be generated as safe `read` tools.
- `write`, `admin`, and `destructive` tools require explicit metadata before they are exposed.
- Operations with authentication, authorization, audit, or confirmation requirements must preserve those requirements in MCP metadata.

The generator should fail when it cannot determine a safety level.

## Manual Overrides

Generated metadata may need an override file for fields that OpenAPI does not express well.

Allowed override uses:

- safety level
- tool title or description wording
- local-only versus production availability
- required confirmation behavior
- audit metadata requirements

Overrides must be committed and reviewed like API contract changes.

## Validation

`composer mcp` should continue validating that catalog entries match OpenAPI operations.

When generation is added, validation should also check:

- every generated tool has a stable `operationId`
- generated `method` and `path` match OpenAPI
- generated `responseSchemaRef` matches the documented success response
- unsupported or ambiguous safety levels fail the check
- generated output is deterministic

## Adoption Trigger

Keep the current hand-maintained catalog until one of these happens:

- more than a few public JSON endpoints are shipped
- catalog updates become repetitive
- frontend or external tools begin consuming the catalog directly
- MCP tooling needs parameter schemas beyond the simple read-only endpoints

At that point, add a focused Issue for the generator instead of mixing generation work with unrelated API changes.
