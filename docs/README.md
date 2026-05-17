# NENE2 Documentation

Documentation for NENE2 — a minimal PHP 8.4 JSON API framework.

---

## Start here

| If you want to… | Go to |
|---|---|
| Build your first API from scratch | [Tutorial: Your first API](tutorial/first-api.md) |
| Add a route with path parameters | [HOWTO: Add a custom route](howto/add-custom-route.md) |
| Connect a database | [HOWTO: Add a database-backed endpoint](howto/add-database-endpoint.md) |
| Understand the project workflow | [Contributing](CONTRIBUTING.md) |
| See what is built and what is next | [Roadmap](roadmap.md) |

---

## Tutorials

Step-by-step guides that get you from zero to a working result. Follow in order.

- [Your first API in 10 minutes](tutorial/first-api.md) — `composer require` → running JSON endpoint

---

## HOWTOs

Goal-oriented guides. Pick the one that matches what you need to do.

- [Add a custom route](howto/add-custom-route.md) — GET, POST, path parameters, query strings
- [Add a database-backed endpoint](howto/add-database-endpoint.md) — UseCase → Repository → Handler pattern

---

## Reference

Look these up while you are working.

- [OpenAPI contract](openapi/openapi.yaml) — all shipped endpoints, schemas, and examples
- [MCP tool catalog](mcp/tools.json) — local MCP server tool definitions
- [Endpoint scaffold workflow](development/endpoint-scaffold.md) — checklist for adding a new endpoint
- [Domain layer policy](development/domain-layer.md) — UseCase / Repository / DTO conventions

---

## Development guides

For contributors and maintainers.

- [Setup](development/setup.md) — local environment setup
- [Coding standards](development/coding-standards.md)
- [Authentication boundary](development/authentication-boundary.md)
- [Test database strategy](development/test-database-strategy.md)
- [Client project start guide](development/client-project-start.md)

---

## Architecture decisions

- [ADR index](adr/) — all recorded architecture decisions

---

## Integrations

- [Local MCP server](integrations/local-mcp-server.md)
- [Local MCP client configuration](integrations/local-mcp-client-configuration.md)
- [AI tools policy](integrations/ai-tools.md)
