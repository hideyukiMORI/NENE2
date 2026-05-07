# LLM Delivery Starter

## Period

May 2026 and after `v0.1.0`

## Goal

Turn the `v0.1.0` foundation into a practical starter kit for LLM-assisted client delivery.

This milestone should make it realistic to start a small client-style API project and quickly produce a running API, OpenAPI handoff, and safe MCP integration path.

## Theme

NENE2 should become useful first as the maintainer's own delivery base:

- small enough to understand quickly
- explicit enough for AI agents to change safely
- complete enough to demonstrate API, MCP, auth, and database boundaries in one local environment

## Scope

- implement a local MCP server that exposes read-only tools through documented API boundaries
- add a basic API-key authentication middleware path for machine clients
- define and document an endpoint scaffold workflow that keeps PHP code, OpenAPI, and tests aligned
- add real service database verification through Docker Compose, starting with MySQL or PostgreSQL
- keep the existing React starter optional and decoupled from backend runtime behavior
- keep Packagist publication deferred until the starter proves its project shape

## Acceptance Criteria

- A local MCP client can connect to a documented NENE2 MCP server and call at least one read-only tool. `#138`
- The first protected JSON API path can require an API key and return Problem Details on authentication failure. `#140`
- A documented endpoint creation workflow exists and is exercised by at least one small example endpoint.
- Docker-based database verification covers at least one real service database in addition to SQLite adapter tests.
- `docs/todo/current.md` points at this milestone and lists concrete next candidates.
- Follow-up work remains split into small GitHub Issues and PRs.

## Candidate Issues

- Implement the first local MCP server for read-only OpenAPI-aligned tools. `#138`
- Add API-key authentication middleware for machine-client requests. `#140`
- Create endpoint scaffold documentation and the first example endpoint workflow.
- Add Docker Compose real database service verification.
- Review whether `v0.1.x` should include patch releases for milestone increments.

## Non-Goals

- Production MCP deployment before local behavior is proven.
- OAuth, user login, or full authorization policy.
- Direct production database access through MCP tools.
- Packagist publication.
- Broad framework marketing.
- Release automation before the manual release path has been exercised a little more.

## Related Work

- Delivery direction: `docs/integrations/llm-delivery-starter.md`
- MCP tool policy: `docs/integrations/mcp-tools.md`
- Local MCP server guidance: `docs/integrations/local-mcp-server.md`
- Authentication boundary: `docs/development/authentication-boundary.md`
- Database test strategy: `docs/development/test-database-strategy.md`
- GitHub Issue: `#136`
