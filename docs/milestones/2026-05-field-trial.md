# First LLM Field Trial

## Period

May 2026 and after the `v0.1.1` checkpoint release.

## Goal

Use NENE2 in a first real or realistic client-style project and record whether the LLM/MCP delivery workflow actually helps.

This milestone should turn "the starter is ready" into "the starter has been used and observed."

## Theme

The next work should gather practical evidence:

- one small project or sandbox starts from NENE2
- one endpoint is added through the documented workflow
- one local MCP client connects successfully
- one MCP tool call is recorded through the API boundary
- friction becomes follow-up Issues instead of guesswork

## Scope

- create a field-trial report template (`#160`)
- run or document the first field trial against `v0.1.1` (`#164`)
- record a local MCP client connection and tool call without secrets
- record endpoint, OpenAPI, test, and handoff friction
- create follow-up Issues from the field trial

## Acceptance Criteria

- Field-trial direction is documented. `#158`
- `docs/todo/current.md` points at this milestone and lists concrete next candidates. `#158`
- A field-trial report template exists.
- A first field-trial report records at least one MCP tool call through the documented API boundary.
- Follow-up Issues are created from observed friction rather than speculative feature ideas.

## Candidate Issues

- Record the first LLM field-trial direction and next milestone. `#158`
- Add a field-trial report template. `#160`
- Run the first `v0.1.1` client-style field trial. `#164`
- Convert field-trial friction into focused follow-up Issues.
- Decide whether any repeated field-trial step justifies a helper script or generator.

## Non-Goals

- Production MCP deployment.
- Broad marketing claims.
- Packagist publication.
- Release automation.
- Write/admin/destructive MCP tools.
- Large framework features before field-trial evidence exists.

## Related Work

- Field-trial direction: `docs/integrations/llm-field-trial.md`
- Client project start guide: `docs/development/client-project-start.md`
- Endpoint scaffold workflow: `docs/development/endpoint-scaffold.md`
- Local MCP client configuration: `docs/integrations/local-mcp-client-configuration.md`
- Machine-client smoke workflow: `docs/development/machine-client-smoke.md`
- `v0.1.1` release prep: `docs/development/release-v0.1.1-prep.md`
- GitHub Issue: `#158`
