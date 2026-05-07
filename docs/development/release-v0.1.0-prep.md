# v0.1.0 Release Preparation

This document prepares the first `v0.1.0` release. It is not a release announcement and does not tag the repository.

## Release Intent

`v0.1.0` should represent the first coherent NENE2 foundation release:

- PSR-first HTTP runtime skeleton
- explicit dependency injection wiring
- typed configuration boundary
- Problem Details error shape
- OpenAPI contract and lightweight contract tests
- React + TypeScript starter baseline
- database adapter boundaries
- MCP and AI tooling policies
- backend and frontend CI checks

The release should still be described as early `0.x` framework foundation work. It must not promise long-term public API stability.

## Candidate Highlights

Potential release highlights:

- JSON API first runtime with `/` and `/health` endpoints.
- Internal router with full-segment path parameters.
- PSR-15 middleware pipeline foundation with request id, security, CORS, and request size policies.
- RFC 9457 Problem Details response policy and shared OpenAPI schemas.
- Runtime contract tests aligned with OpenAPI examples and basic response schemas.
- Typed app and database configuration.
- PDO connection, query executor, and transaction manager boundaries.
- React + TypeScript + Vite starter with frontend-to-backend health integration.
- GitHub Actions for backend Composer checks and frontend npm checks.
- MCP read-only catalog, safe local command guidance, local server guidance, and authentication boundary policies.

## Verification Before Tagging

Before tagging `v0.1.0`, run:

```bash
git switch main
git pull --ff-only origin main
docker compose run --rm app composer validate
docker compose run --rm app composer check
npm run check --prefix frontend
npm run build --prefix frontend
git diff --check
```

Confirm the latest `main` push has passing Backend and Frontend GitHub Actions.

## Draft Release Notes Shape

```markdown
## Highlights

- <short foundation summary>

## Breaking Changes

None. This is the first `0.x` foundation release.

## Migration Notes

None for existing released users.

## Verification

- `composer validate`
- `composer check`
- `npm run check --prefix frontend`
- `npm run build --prefix frontend`
- Backend and Frontend GitHub Actions on `main`

## Known Follow-up Work

- <deferred items>
```

## Deferred Work

Do not block `v0.1.0` on:

- Packagist publication
- production MCP tooling
- full authentication middleware
- full JSON Schema runtime validation
- release automation
- branch protection being enabled in repository settings
- `1.0.0` stability guarantees

## Tagging Reminder

Only tag from an up-to-date, clean `main` after verification:

```bash
git tag v0.1.0
git push origin v0.1.0
```

Create the GitHub Release from that tag and include related Issues and PRs in the notes.
