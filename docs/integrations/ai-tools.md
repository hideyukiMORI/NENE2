# AI Tooling Policy

NENE2 is designed to be easy for AI agents to inspect, change, and verify without guessing.

## Source of Truth

- Project direction: `README.md` and `docs/roadmap.md`
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
- API-key and token boundaries are documented in `docs/development/authentication-boundary.md`.
- Safe local AI and MCP commands are documented in `docs/integrations/local-ai-commands.md`.
- AI-assisted debugging should follow `docs/integrations/ai-debugging.md`.
- Destructive operations must require explicit user approval.
- Production access rules must be documented before production tooling is added.
