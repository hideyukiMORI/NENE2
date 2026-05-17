# Milestone: Phase 21 — Field Trial 5 (MCP Write Tools)

## Period

May 2026, after v0.4.0 release.

## Goal

Validate that the MCP write tools added in v0.4.0 (#228) work end-to-end through the local MCP server — covering `POST`, `PUT`, and `DELETE` operations against the Note endpoints.

All prior field trials focused on HTTP endpoints or the `composer require` consumer path. This trial focuses specifically on the MCP tool layer and write operations.

## Acceptance Criteria

- [ ] Local MCP server is started against a running NENE2 app
- [ ] `create_note` tool (`POST /examples/notes`) is called and returns the created note id
- [ ] `update_note` tool (`PUT /examples/notes/{id}`) is called and verifies the update
- [ ] `delete_note` tool (`DELETE /examples/notes/{id}`) is called and verifies deletion
- [ ] Path parameter interpolation is confirmed working (`{id}` → actual id)
- [ ] A field trial report is recorded in `docs/field-trials/`
- [ ] Follow-up Issues are created for any friction discovered
- [ ] `docs/todo/current.md` is updated

## Scope

- Framework source code changes are out of scope during the trial (observations only)
- The trial uses the NENE2 repository directly (no separate consumer project needed)
- Friction findings become the input for Phase 22+

## Candidate Follow-up Areas

- Does path parameter interpolation (`{id}`) behave as documented?
- Does the MCP client (Claude / Cursor) correctly pass integer vs string arguments?
- Is the tool catalog clear enough for an LLM to call write tools without guidance?
- Is there friction in starting the local MCP server alongside the app container?
- Are write tool error responses (422, 404) surfaced usefully through MCP?
