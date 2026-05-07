# LLM Delivery Starter Direction

This document records useful planning notes for NENE2 after the first `v0.1.0` foundation release.

These notes are not a broad product pivot. They clarify how NENE2 should be useful to its primary maintainer when delivering LLM-assisted client work quickly and safely.

## Position

NENE2 is most useful when treated as a small starter kit for client delivery that needs:

- a working JSON API foundation
- an OpenAPI contract that can be shared early
- MCP tool metadata or server integration that can be derived from the API boundary
- predictable PHP deployment on common hosting or VPS environments
- code structure that stays readable to humans and AI agents

The first audience is the maintainer and trusted project collaborators, not a broad public framework market.

## Why This Matters

For LLM-assisted delivery work, the valuable outcome is not framework popularity. The valuable outcome is reducing the time between project start and a credible technical handoff:

- API behavior is documented before it spreads across implementation details.
- AI agents can inspect the project through committed docs and stable commands.
- MCP integrations can be built from application boundaries instead of direct database or filesystem access.
- Small client projects can start from a known runtime, CI, test, and documentation baseline.

This direction fits the current NENE2 foundation better than trying to compete directly with full-stack frameworks.

## Prioritization Notes

The next work should favor capabilities that help a maintainer deliver a small API or AI-enabled system quickly.

High-value next candidates:

- implement a local MCP server that clients can actually connect to
- add an API-key authentication middleware path suitable for machine clients
- add a documented way to create new API endpoints with OpenAPI and tests together
- add real service database checks, starting with MySQL or PostgreSQL in Docker Compose

Lower-priority work for now:

- Packagist publication
- broad public marketing
- release automation beyond the first manual release path
- framework convenience layers that make code less explicit

## Boundaries

NENE2 should keep these constraints:

- Do not expose MCP tools that bypass the documented API or service boundary.
- Do not treat direct production database access as a first-class AI integration.
- Do not make frontend tooling part of the backend runtime contract.
- Do not promise `1.0.0` style public API stability while the framework is still proving its shape.
- Do not add broad framework features unless they help real delivery workflows.

## Success Signals

This direction is working when a new client-style project can quickly produce:

- a running local API
- OpenAPI documentation
- a small set of tested endpoints
- a safe read-only MCP integration path
- basic machine-client authentication
- clear handoff docs that explain what exists and what is deferred

## Related Documents

- Roadmap: `docs/roadmap.md`
- AI tooling policy: `docs/integrations/ai-tools.md`
- MCP tool policy: `docs/integrations/mcp-tools.md`
- Local MCP server guidance: `docs/integrations/local-mcp-server.md`
- Authentication boundary: `docs/development/authentication-boundary.md`
