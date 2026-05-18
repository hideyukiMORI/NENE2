# Milestone: Field Trial 10 — New Domain Entity from Scratch

## Goal

Validate that the v1.3.0 scaffold workflow is sufficient to build a third domain entity
entirely from scratch — one that does not exist anywhere in the NENE2 repository — and confirm
that `PaginationQueryParser`, `JsonRequestBodyParser`, and the MCP tool path work correctly for
the new entity.

## Theme: Scaffold Ergonomics

All prior field trials exercised features against Note and Tag, both of which ship as reference
implementations in `src/Example/`. FT10 shifts focus: does the documentation alone guide
a developer through adding a new entity, without leaning on the existing examples as a codebase
crutch?

## Phases

### Phase 58 — Field Trial 10 Execution

Key deliverables:

- [ ] Choose a new domain entity distinct from Note and Tag (e.g. Task, Event, Product)
- [ ] Follow `docs/development/endpoint-scaffold.md` as the only procedural guide
- [ ] Implement full CRUD (GET / POST / PUT / DELETE) + list with pagination
- [ ] Add OpenAPI paths and `docs/mcp/tools.json` entries for the new entity
- [ ] Connect a local MCP client and verify tool calls against the new endpoints
- [ ] Record friction in `docs/field-trials/2026-05-field-trial-10.md`
- [ ] Open follow-up Issues for each friction point

## Acceptance Criteria

- [ ] New entity endpoints pass `composer check`
- [ ] `PaginationQueryParser` used in the list handler
- [ ] `JsonRequestBodyParser` used in write handlers
- [ ] MCP tool calls succeed through the local MCP server
- [ ] Field trial report created with friction log

## Notes

- User-executed trial; AI agent assists with planning and follow-up Issue creation.
- Tracked by Issue #404.
