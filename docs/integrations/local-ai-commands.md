# Local AI and MCP Commands

Local AI and MCP workflows may run documented verification commands when they stay inside the development boundary and do not read secrets or mutate production-like state.

## Safe Defaults

These commands are safe for local agents and MCP development tools:

```bash
docker compose run --rm app composer check
docker compose run --rm app composer openapi
docker compose run --rm app composer mcp
docker compose run --rm app composer test:database
npm run check --prefix frontend
npm run build --prefix frontend
git diff --check
```

Use `docker compose up -d app` when the local HTTP API needs to be inspected through public routes.

Read-only HTTP inspection may call:

```bash
curl -fsS http://localhost:8080/
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8080/openapi.php
```

## Boundaries

Local AI and MCP commands must:

- use committed configuration and documented scripts
- prefer public HTTP routes over direct database or filesystem shortcuts
- keep test databases deterministic and disposable
- avoid reading `.env`, credentials, tokens, or private user files
- avoid destructive git commands unless the user explicitly approves them

Local AI and MCP commands must not:

- run production credentials or production database URLs
- modify databases outside documented test or migration commands
- bypass application authorization in a way that resembles production behavior
- commit generated build output unless a release policy explicitly requires it
- install new dependencies without a focused Issue and reviewable diff

## Adding Commands

Before a command becomes part of AI or MCP automation:

- document the command and its purpose
- define whether it is read-only, write, admin, or destructive
- verify it locally
- add it to CI only after its configuration is committed
- update `docs/todo/current.md` or the related Issue when it changes current workflow
