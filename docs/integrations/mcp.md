# MCP Policy

MCP support should make NENE2 easy for AI tools to operate without bypassing application boundaries.

Concrete MCP tool integration rules live in `docs/integrations/mcp-tools.md`.

## Goals

- Expose safe, documented application capabilities to AI tools.
- Reuse API contracts instead of inventing a second hidden interface.
- Keep authentication, authorization, and audit concerns explicit.

## Principles

- MCP tools should call application APIs or application services through documented boundaries.
- MCP tools should not directly manipulate databases or private filesystem state unless a specific tool is designed and documented for that purpose.
- Tool definitions should be generated from or aligned with OpenAPI where practical.
- Dangerous actions must be explicit and easy to review.
- Local development MCP tools and production MCP tools must be documented separately.

## Early Scope

Initial MCP work should wait until the API foundation exists. The first MCP milestone should focus on read-only inspection tools before mutation tools.

## Security Notes

- Do not store MCP credentials in the repository.
- Use example configuration files for non-secret setup.
- Document required scopes for each tool before enabling it outside local development.
