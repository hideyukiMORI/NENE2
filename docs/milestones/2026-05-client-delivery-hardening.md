# Client Delivery Hardening

## Period

May 2026 and after the LLM Delivery Starter checkpoint.

## Goal

Make NENE2 easier to use as a small client delivery base after the first API, MCP, auth, and database boundaries are in place.

This milestone should improve the path from clone to credible handoff without broadening the framework into a large full-stack product.

## Theme

The next work should make the existing foundation easier to explain, repeat, and adapt:

- clearer README and first-run guidance
- practical examples for API, MCP, auth, and database workflows
- tighter handoff docs for a maintainer or trusted collaborator
- small hardening changes that reduce setup friction

## Scope

- improve README entry points for setup, verification, and delivery-starter capabilities
- add a small guide for starting a new client-style API from the current foundation
- document a local MCP client configuration example without adding production MCP deployment
- add a focused machine-client smoke workflow for protected API access
- review whether a `v0.1.1` checkpoint tag is useful after the completed delivery-starter work
- keep Packagist publication and broad marketing deferred

## Acceptance Criteria

- README explains the current starter capabilities and links to the most useful docs. `#148`
- `docs/todo/current.md` points at this milestone and lists concrete next candidates. `#148`
- A new project start guide exists for adapting NENE2 to a small client-style API. `#150`
- Local MCP usage can be followed from committed docs without relying on chat history. `#152`
- Protected machine-client API smoke checks are documented with safe local credentials. `#154`
- Any `v0.1.1` tag decision uses `docs/development/release-v0.1.x-policy.md`.

## Candidate Issues

- Close out the LLM Delivery Starter milestone and improve README entry points. `#148`
- Add a client project start guide for adapting the foundation. `#150`
- Document a local MCP client configuration example. `#152`
- Add a protected machine-client smoke workflow. `#154`
- Decide whether to tag `v0.1.1` as a delivery-starter checkpoint.

## Non-Goals

- Production MCP deployment.
- OAuth, user login, or full authorization policy.
- Packagist publication.
- Release automation.
- Broad framework marketing.
- Replacing the explicit endpoint scaffold with code generation.

## Related Work

- Previous milestone: `docs/milestones/2026-05-llm-delivery-starter.md`
- Delivery direction: `docs/integrations/llm-delivery-starter.md`
- Client project start guide: `docs/development/client-project-start.md`
- Endpoint scaffold workflow: `docs/development/endpoint-scaffold.md`
- Local MCP server guidance: `docs/integrations/local-mcp-server.md`
- Local MCP client configuration: `docs/integrations/local-mcp-client-configuration.md`
- Authentication boundary: `docs/development/authentication-boundary.md`
- Machine-client smoke workflow: `docs/development/machine-client-smoke.md`
- Database test strategy: `docs/development/test-database-strategy.md`
- `v0.1.x` patch release policy: `docs/development/release-v0.1.x-policy.md`
- GitHub Issue: `#148`
