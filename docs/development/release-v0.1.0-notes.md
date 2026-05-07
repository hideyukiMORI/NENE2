# v0.1.0 Release Notes Draft

These notes are a draft for the first `v0.1.0` GitHub Release.

Do not publish them until the release checklist has been completed and the `v0.1.0` tag exists on `main`.

## Highlights

`v0.1.0` is the first NENE2 foundation release.

It establishes a small, explicit PHP framework base for:

- JSON APIs with OpenAPI contracts
- PSR-first HTTP runtime behavior
- explicit dependency injection and typed configuration
- lightweight frontend starter integration
- database adapter boundaries
- AI and MCP tooling through documented API boundaries
- local and CI verification paths

## Runtime and API

- Added PSR-7 / PSR-15 / PSR-17 HTTP runtime direction and implementation foundation.
- Added explicit route dispatch with full-segment path parameters.
- Added request id, security header, CORS, request size, and request logging boundaries.
- Added RFC 9457 Problem Details policy and OpenAPI shared error schemas.
- Added OpenAPI contract validation and runtime contract tests for shipped JSON endpoints.

## Configuration and Dependency Injection

- Added typed configuration loading with `.env.example`.
- Added PSR-11 container foundation and explicit runtime service provider wiring.
- Registered runtime, config, logger, response, database, and transaction services through the container boundary.

## Database Foundation

- Selected Phinx as the initial migration runner.
- Added typed database configuration and Phinx integration through the config loader.
- Added PDO connection, parameterized query executor, and transaction manager boundaries.
- Added SQLite-first database adapter test strategy and focused database test command.

## Frontend Starter

- Added React + TypeScript + Vite starter direction.
- Added npm metadata, lockfile, ESLint, Prettier, TypeScript, and frontend check scripts.
- Added a typed `/health` fetch wrapper and Vite proxy path for local backend integration.
- Added frontend GitHub Actions checks.

## AI and MCP Tooling

- Added MCP tool integration safety policy.
- Added read-only MCP tool catalog aligned with OpenAPI operations.
- Added local AI/MCP safe command policy and local MCP server guidance.
- Added API key and token boundary policy for future MCP and machine clients.
- Added request-id based AI debugging guidance.
- Added OpenAPI-to-MCP catalog generation direction.

## Quality and Release

- Added backend Composer checks and frontend npm checks in GitHub Actions.
- Added Dependabot configuration for Composer, GitHub Actions, and npm.
- Added branch protection readiness checklist and current branch protection decision.
- Added first `v0.1.0` release preparation notes.

## Breaking Changes

None.

This is the first `0.x` foundation release and does not promise long-term public API stability.

## Migration Notes

None for existing released users.

## Verification

Release-candidate verification is recorded in `docs/development/release-v0.1.0-verification.md`.

Before publishing this release, verify or reuse the recorded result:

```bash
git switch main
git pull --ff-only origin main
docker compose run --rm app composer validate
docker compose run --rm app composer check
npm run check --prefix frontend
npm run build --prefix frontend
git diff --check
```

Also confirm Backend and Frontend GitHub Actions pass on the release commit.

## Known Follow-up Work

- Decide whether to enable `main` branch protection before tagging.
- Run and record final `v0.1.0` release verification.
- Tag `v0.1.0` only after explicit release approval.
- Keep Packagist publication deferred until Composer package contracts are stable enough for early users.
- Keep production MCP tooling and full authentication middleware deferred.
- Add full JSON Schema validation only when the API surface needs it.
- Add release automation only after the manual release process has been exercised.
