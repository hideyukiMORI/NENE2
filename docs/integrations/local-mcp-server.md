# Local MCP Server Integration

Local MCP server integration should help agents inspect and verify NENE2 through documented boundaries.

It is a development convenience, not a production backdoor.

## Position

A local MCP server may expose read-only inspection tools and safe verification commands for the developer's local NENE2 checkout.

It should use:

- public local HTTP APIs
- committed documentation
- `docs/mcp/tools.json`
- documented safe local commands

It must not use:

- direct production database access
- raw `.env` secret reads
- private user filesystem paths
- hidden application behavior that cannot be tested through normal boundaries

## Local Responsibilities

A local MCP server may:

- read the committed MCP catalog
- call `http://localhost:8080/` and other documented local API routes
- return `X-Request-Id` metadata from HTTP responses
- run documented verification commands from `docs/integrations/local-ai-commands.md`
- read committed project docs to answer project-structure questions

A local MCP server should keep tools small and reviewable. If a tool needs business behavior, prefer adding or documenting an application API first.

## Credentials

Local MCP server configuration may name environment variables, but it must not commit credential values.

Allowed examples:

```text
NENE2_LOCAL_API_BASE_URL=http://localhost:8080
NENE2_LOCAL_MCP_API_KEY=<set outside the repository>
```

Credentials should follow `docs/development/authentication-boundary.md`.

Local tools that do not need authentication should not pretend to require credentials. Tools that do require credentials should document required scopes, not raw secret values.

## Tool Shape

Read-only local tools should map to existing catalog or OpenAPI operations when practical.

Recommended metadata:

- tool name
- safety level
- source operation or command
- required scopes, if any
- whether the tool calls HTTP
- whether the tool returns request id metadata

Mutation, admin, and destructive tools are out of scope for the first local MCP server guidance.

## HTTP Behavior

When a local MCP tool calls an HTTP API:

- use the configured local API base URL
- send `Accept: application/json` for JSON APIs
- preserve Problem Details errors instead of rewriting them into unrelated shapes
- return or log the `X-Request-Id` response header when present
- do not include credentials in returned metadata

## Safe Commands

Local command tools should be limited to documented checks such as:

```bash
docker compose run --rm app composer check
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
```

Commands that install dependencies, mutate databases, tag releases, merge PRs, or change git history require a focused Issue and explicit user intent.

## Production Boundary

Production MCP tools must be designed as product features with authentication, authorization, audit, and operational ownership.

Do not reuse a local MCP server configuration as production configuration.

Before production MCP integration exists, document:

- owner
- allowed environments
- credentials and scopes
- audit fields
- rate limits or abuse controls
- failure and rollback behavior
