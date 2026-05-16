# AI Tooling Policy

NENE2 is designed to be easy for AI agents to inspect, change, and verify without guessing.

## Source of Truth

- Project direction: `README.md` and `docs/roadmap.md`
- LLM-assisted delivery direction: `docs/integrations/llm-delivery-starter.md`
- Workflow: `docs/workflow.md`
- Coding rules: `docs/development/coding-standards.md`
- Current state: `docs/todo/current.md`
- Local milestones: `docs/milestones/`
- Agent entry point: `AGENTS.md`
- Cursor summaries: `.cursor/rules/`

## Agent Workflow

For normal code or documentation work, an AI agent should:

1. Confirm or create a GitHub Issue.
2. Check the roadmap, milestone, and TODO state.
3. Create an Issue-numbered branch from `main`.
4. Make focused changes only.
5. Update documentation when behavior or policy changes.
6. Run the narrowest useful verification.
7. Commit, push, open a PR, merge, and sync `main` unless the user narrowed the scope.

## Design for AI Readability

- Use predictable directory names.
- Keep framework conventions documented.
- Prefer typed DTOs and value objects over ambiguous arrays.
- Keep generated files clearly separated from source files.
- Avoid hidden reflection or naming magic unless it is documented and tested.

## Safety Boundaries

- AI tools must not commit secrets.
- MCP tools should interact with the application through documented APIs, not direct database access.
- MCP tool design should follow `docs/integrations/mcp-tools.md`.
- Machine-readable MCP tool metadata is tracked in `docs/mcp/tools.json`.
- OpenAPI-to-MCP catalog generation direction is documented in `docs/integrations/openapi-to-mcp-catalog.md`.
- Local MCP server integration guidance is documented in `docs/integrations/local-mcp-server.md`.
- API-key and token boundaries are documented in `docs/development/authentication-boundary.md`.
- Safe local AI and MCP commands are documented in `docs/integrations/local-ai-commands.md`.
- AI-assisted debugging should follow `docs/integrations/ai-debugging.md`.
- Destructive operations must require explicit user approval.
- Production access rules must be documented before production tooling is added.

## Cursor GitHub MCP Authentication

Cursor's GitHub MCP integration uses its own configured Personal Access Token (PAT), not the `gh` CLI session established by `gh auth login`.

If you encounter a `403` error on GitHub MCP operations such as `create_pull_request` for a private repository, fix the PAT in Cursor's MCP settings — not the `gh` CLI session.

**`gh auth login` does not affect Cursor's GitHub MCP credentials.**

### Required PAT scopes for private repositories

Use one of these token types:

- **Classic PAT**: `repo` scope. If the repository belongs to an organization with SSO, also authorize the token for that organization after creation.
- **Fine-grained PAT**: grant `Contents` (read) and `Pull requests` (read and write) permissions on the target repository, plus any other scopes the MCP operations require.

### Where to update the token

The token location depends on your Cursor MCP configuration. Common locations:

- Cursor settings → MCP → GitHub integration → token field
- Your local MCP client configuration file (if you maintain one outside the repository)

Do not commit PAT values to the repository. Store them only in your local Cursor or MCP client settings.
