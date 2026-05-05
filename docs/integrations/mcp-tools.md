# MCP Tool Integration Policy

NENE2 MCP tools should expose application capabilities through documented boundaries, not through hidden database or filesystem shortcuts.

## Position

MCP integration is an API-facing integration layer.

The default direction is:

- derive tool shape from OpenAPI where practical
- start with read-only inspection tools
- keep local development tools separate from production tools
- require explicit authorization and audit policy before mutation tools
- avoid direct database access from MCP tools by default

## Tool Sources

Preferred sources for tool definitions:

- OpenAPI operations for public JSON APIs
- documented application services for non-HTTP internal tools
- explicit maintenance commands for local-only workflows

Avoid creating MCP-only behavior that cannot be exercised or verified through normal application boundaries.

## Catalog

The first machine-readable MCP tool catalog lives at `docs/mcp/tools.json`.

It contains read-only tool metadata aligned with shipped OpenAPI operations. The catalog is validated by:

```bash
docker compose run --rm app composer mcp
```

`composer check` includes this validation.

## Safety Levels

Each MCP tool should be classified before implementation:

- `read`: returns application state without changing it
- `write`: changes application state
- `admin`: changes configuration, permissions, data retention, or operational state
- `destructive`: deletes data or performs irreversible actions

The first MCP tools should be `read` tools.

`write`, `admin`, and `destructive` tools require:

- documented authentication and authorization behavior
- audit/logging fields
- request id propagation
- explicit confirmation behavior for destructive actions
- tests that cover failure and permission boundaries

## Local Development Tools

Local-only MCP tools may help agents inspect the development app, but they must stay clearly scoped.

Safe local commands are documented in `docs/integrations/local-ai-commands.md`.

Local tools may:

- call local HTTP APIs
- read committed documentation
- run documented safe verification commands

Local tools must not:

- read `.env` secrets
- bypass application authorization in a way that resembles production behavior
- mutate databases outside documented test or migration commands
- depend on a developer's private filesystem layout

## Production Tools

Production MCP tools must be designed as product features, not debugging shortcuts.

Before enabling a production tool, document:

- owner and purpose
- required credentials or scopes
- allowed environments
- rate limits or abuse controls
- audit fields
- rollback or remediation path for failed mutations

## OpenAPI Alignment

When a tool maps to an HTTP API operation:

- use the OpenAPI operation summary and schema as the starting point
- keep parameter names aligned with the API contract
- preserve Problem Details error behavior
- include request id in logs and returned metadata where useful

If a tool needs a shape that does not fit the current API, update the API contract first or document why an internal service boundary is better.

## Non-Goals

- Direct production database tools as the first MCP milestone.
- MCP-only business behavior that bypasses HTTP/API tests.
- Storing MCP credentials in the repository.
- Exposing destructive tools before authentication, authorization, and audit policies exist.
