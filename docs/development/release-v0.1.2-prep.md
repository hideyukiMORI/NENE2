# v0.1.2 Checkpoint Release Preparation

This document records the `v0.1.2` checkpoint release decision.

It does not create a tag.

## Decision

`v0.1.2` is a good checkpoint release candidate after the completed Domain Layer Starter milestone and First LLM Field Trial friction fixes.

Reason:

- the changes improve the existing `v0.1.1` foundation without broadening the public framework surface
- the domain layer example, policy doc, and tests make NENE2 meaningfully more useful as a client project starter
- the friction fixes and smoke helper reduce MCP setup effort observed in the field trial
- the scope matches `docs/development/release-v0.1.x-policy.md`

Do not tag `v0.1.2` without explicit release approval.

## Candidate Scope

Candidate highlights since `v0.1.1`:

- domain layer policy doc (`docs/development/domain-layer.md`): UseCase/RepositoryInterface/DTO conventions
- example end-to-end domain endpoint `GET /examples/notes/{id}` with UseCase, PDO adapter, and handler
- PHPUnit unit tests for the use case (in-memory repository, no DB) and integration tests for the PDO adapter (SQLite in-memory)
- Phinx migration for the `notes` table
- OpenAPI schema for the example domain endpoint
- field-trial friction fix: document MCP integer path parameter type requirement
- field-trial friction fix: document Cursor GitHub MCP PAT independence from `gh` CLI
- local MCP smoke helper script (`tools/mcp-smoke.sh`) with stdin piping fix

## Related Issues

- Domain layer policy doc: `#182`
- Domain layer example implementation: `#184`
- MCP integer path parameters: `#167`
- Cursor GitHub MCP PAT guidance: `#168`
- MCP smoke helper script: `#178`
- Phase 9 milestone definition: `#180`
- This decision: `#186`

## Before Tagging

Before creating a `v0.1.2` tag:

1. Get explicit maintainer approval to tag `v0.1.2`.
2. Confirm `main` is up to date and clean.
3. Confirm Backend and Frontend GitHub Actions pass on the release commit.
4. Run the manual release checklist in `docs/development/release-checklist.md`.
5. Draft short GitHub Release notes from the candidate scope above.

Recommended local verification:

```bash
git switch main
git pull --ff-only origin main
docker compose run --rm app composer validate
docker compose run --rm app composer check
npm run check --prefix frontend
npm run build --prefix frontend
git diff --check
```

Recommended domain layer smoke checks:

```bash
docker compose up -d app
docker compose run --rm app composer migrations:migrate
curl -i http://localhost:8080/examples/notes/1
```

Note: `GET /examples/notes/1` returns `404` until a seed row is inserted. The 404 Problem Details response itself confirms the full UseCase → Handler path is wired correctly through the container.

Optional MCP smoke check:

```bash
bash tools/mcp-smoke.sh getHealth '{}'
```

## Non-Goals

- No tag in this documentation PR.
- No GitHub Release publication without explicit approval.
- No Packagist publication.
- No release automation.
- No `1.0.0` public API stability promise.

## Related Work

- Patch release policy: `docs/development/release-v0.1.x-policy.md`
- Release checklist: `docs/development/release-checklist.md`
- Domain Layer Starter milestone: `docs/milestones/2026-05-domain-layer-starter.md`
- Current TODO: `docs/todo/current.md`
- GitHub Issue: `#186`
