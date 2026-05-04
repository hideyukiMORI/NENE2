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

## Planned Structure

The exact location will be decided with the runtime skeleton, but the expected shape is:

```text
docs/openapi/
├── openapi.yaml
└── examples/
```

## Testing Direction

Once runtime code exists, API tests should verify that responses match the documented contract. The first implementation can be lightweight and grow with the framework.
