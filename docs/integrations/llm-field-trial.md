# LLM Field Trial Direction

This document records the next practical step after the `v0.1.1` delivery-starter checkpoint.

It is based on external review feedback, treated as a useful perspective rather than a market claim.

## Position

NENE2 is ready for a first field trial in a small client-style project.

The goal is not to prove broad framework popularity. The goal is to prove that the existing API, OpenAPI, MCP, auth, database, and documentation boundaries help real delivery work.

The most useful next evidence is:

- a real or realistic client-style API started from NENE2
- at least one endpoint added through the documented scaffold workflow
- a local LLM client connected to the NENE2 MCP server
- a recorded MCP tool call through the documented API boundary
- a short handoff note showing what worked, what was confusing, and what should change

## Why This Matters

Until this happens, NENE2 is still mostly a well-prepared starter.

After this happens, NENE2 has evidence that:

- its docs are usable outside the original implementation chat
- its MCP integration works in an actual LLM-assisted workflow
- its API and OpenAPI boundaries are enough for a first handoff
- its machine-client and database smoke workflows reduce setup uncertainty

This is a better success signal than adding broad framework features early.

## Field Trial Shape

The first field trial should stay small.

Recommended scenario:

1. Start from the current `v0.1.1` release.
2. Create a small client-style API project or sandbox.
3. Add one new read-only endpoint using `docs/development/endpoint-scaffold.md`.
4. Update OpenAPI and tests in the same change.
5. Connect a local MCP client using `docs/integrations/local-mcp-client-configuration.md`.
6. Call at least one MCP tool such as `getHealth` through the local server.
7. Record the request id, command or client action, result, and any friction.
8. Write a short field-trial report before expanding the framework.

## Evidence to Record

Record enough detail to make the result useful without exposing private client data.

Good evidence:

- repository or sandbox name when safe
- NENE2 version or tag
- endpoint added
- OpenAPI operation id
- MCP tool name called
- request id from the API response when present
- verification commands run
- what the LLM did well
- what the LLM could not infer from docs
- follow-up Issues created from real friction

Do not record:

- client secrets
- raw API keys
- private customer data
- production URLs
- private prompts that include confidential business details

## Conservative Claims

NENE2 may become a useful differentiator for PHP projects that need LLM-assisted API delivery.

Avoid documenting unsupported claims such as being the only PHP framework with MCP support unless there is a maintained comparison and clear evidence.

Prefer claims like:

- NENE2 has a documented local MCP path.
- NENE2 keeps MCP tools behind API and OpenAPI boundaries.
- NENE2 is optimized for small PHP API delivery with AI-readable docs.

## Non-Goals

- Production MCP deployment.
- Marketing copy.
- Broad framework comparisons.
- Adding write/admin/destructive MCP tools.
- Building generators before the first field trial exposes real repetition.

## Related Work

- Roadmap: `docs/roadmap.md`
- Current milestone: `docs/milestones/2026-05-field-trial.md`
- Client project start guide: `docs/development/client-project-start.md`
- Endpoint scaffold workflow: `docs/development/endpoint-scaffold.md`
- Local MCP client configuration: `docs/integrations/local-mcp-client-configuration.md`
- Machine-client smoke workflow: `docs/development/machine-client-smoke.md`
- GitHub Issue: `#158`
