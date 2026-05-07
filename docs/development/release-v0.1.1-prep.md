# v0.1.1 Checkpoint Release Preparation

This document records the `v0.1.1` checkpoint release decision.

It does not create a tag.

## Decision

`v0.1.1` is a good checkpoint release candidate after the completed LLM Delivery Starter and Client Delivery Hardening increments.

Reason:

- the changes improve the existing `v0.1.0` foundation without broadening the public framework surface
- the work is mostly local delivery readiness, docs, verification, and safe machine-client workflow hardening
- the scope matches `docs/development/release-v0.1.x-policy.md`

Do not tag `v0.1.1` without explicit release approval.

## Candidate Scope

Candidate highlights since `v0.1.0`:

- local MCP server for read-only OpenAPI-aligned tools
- API-key middleware for machine-client requests
- endpoint scaffold workflow with the first example endpoint
- Docker Compose MySQL verification as an opt-in real database check
- `v0.1.x` patch release policy
- LLM Delivery Starter milestone completion summary
- Client Delivery Hardening milestone and README entry points
- client project start guide
- local MCP client configuration example
- protected machine-client smoke workflow

## Related Issues

- Local MCP server: `#138`
- API-key middleware: `#140`
- Endpoint scaffold workflow: `#142`
- Docker Compose real database verification: `#144`
- `v0.1.x` patch release policy: `#146`
- Phase close and README entry points: `#148`
- Client project start guide: `#150`
- Local MCP client configuration: `#152`
- Machine-client smoke workflow: `#154`
- This decision: `#156`

## Before Tagging

Before creating a `v0.1.1` tag:

1. Get explicit maintainer approval to tag `v0.1.1`.
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

Optional delivery-starter smoke checks:

```bash
docker compose up -d app
curl -i http://localhost:8080/examples/ping
printf '%s\n' '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
docker compose up -d app
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
- Current milestone: `docs/milestones/2026-05-client-delivery-hardening.md`
- Current TODO: `docs/todo/current.md`
- GitHub Issue: `#156`
