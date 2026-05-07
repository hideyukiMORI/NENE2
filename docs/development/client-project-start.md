# Client Project Start Guide

This guide explains how to adapt NENE2 into a small client-style API project.

It is intentionally practical and manual. The goal is to make the first project handoff credible before adding generators or broad framework convenience layers.

## Starting Point

Use this guide when a project needs:

- a running local JSON API
- OpenAPI documentation that can be shared early
- a small set of tested endpoints
- optional React starter integration
- safe local MCP inspection through documented API boundaries
- basic machine-client authentication
- a Docker-based database verification path

NENE2 is still a `0.x` foundation. Treat public contracts as useful but still forming.

## Public field trial reference sandbox (optional)

After the first local milestone, it can help to inspect a **completed public demo** that stayed on the documented scaffold path:

- Repository: [`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial) (based on NENE2 **`v0.1.1`**).
- Contents: read-only exhibition-shaped JSON APIs, OpenAPI, PHPUnit, local MCP tools, and Markdown field-trial notes.

It is **not** an official product repository and **does not imply endorsement** of any real exhibition. **Fictional sandbox data** — read that project’s `README.md` and `SECURITY.md` before treating names or years as facts.

## First Local Setup

Start from a clean checkout:

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

Confirm the local API and docs:

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
```

Useful browser URLs:

- OpenAPI: `http://localhost:8080/openapi.php`
- Swagger UI: `http://localhost:8080/docs/`

## Rename the Project Boundary

Before adding application behavior, decide what is project-specific and what stays framework foundation.

Update project-facing metadata first:

- `README.md` project description
- `composer.json` package name and description when the project will not remain the NENE2 framework package
- OpenAPI `info.title`, `info.description`, and `info.version`
- default examples that should no longer describe NENE2 itself

Keep these unchanged unless the application truly owns them:

- low-level framework namespaces in `src/`
- Problem Details shape
- request id behavior
- endpoint scaffold workflow
- test and static analysis commands

## Add the First Application Endpoint

Use `docs/development/endpoint-scaffold.md` for every shipped JSON endpoint.

For the first client endpoint:

1. Create or reuse a focused GitHub Issue.
2. Add the route in the smallest clear runtime boundary.
3. Add the OpenAPI path, `operationId`, schema, and examples.
4. Add runtime tests close to the endpoint behavior.
5. Let `tests/OpenApi/RuntimeContractTest.php` verify documented success examples.
6. Run a local HTTP smoke check through Docker.

Keep early endpoints thin. Move behavior toward use cases or small services once a route grows beyond simple runtime demonstration.

## Keep OpenAPI as the Handoff Contract

OpenAPI should be updated in the same PR as endpoint behavior.

Each endpoint should include:

- stable `operationId`
- concise summary and description
- success response schema and example
- relevant Problem Details responses
- security requirements only when matching middleware exists

Before sending the handoff, run:

```bash
docker compose run --rm app composer openapi
docker compose run --rm app composer check
```

## Add MCP Only Through API Boundaries

Start with public or read-only API behavior first. Add MCP metadata only after the endpoint exists in OpenAPI.

If an endpoint should become a local MCP tool:

1. Add or confirm the OpenAPI operation.
2. Add a read-only entry to `docs/mcp/tools.json`.
3. Run `docker compose run --rm app composer mcp`.
4. Smoke test the local MCP server only against local APIs.

Local MCP guidance lives in `docs/integrations/local-mcp-server.md`.

Do not expose direct database, filesystem, or production-only behavior through MCP tools.

## Protect Machine-Client Paths

Use the existing `X-NENE2-API-Key` direction for machine-client paths only.

For local testing:

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

Do not commit real API keys, generated secrets, or local `.env` files.

Authentication policy lives in `docs/development/authentication-boundary.md`.
The local protected route smoke workflow lives in `docs/development/machine-client-smoke.md`.

## Verify Database Behavior

Default database adapter tests use SQLite and run through the standard check path.

Use MySQL verification only when behavior should be checked against a service database:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

Database strategy lives in `docs/development/test-database-strategy.md`.

## Handoff Checklist

Before handing off a client-style project, confirm:

- `README.md` describes the project, not just the starter.
- `docs/openapi/openapi.yaml` matches shipped JSON behavior.
- Swagger UI loads locally.
- New endpoints have runtime tests and OpenAPI examples.
- Protected routes document required credentials without exposing secret values.
- MCP tools, if any, call documented API boundaries only.
- `docker compose run --rm app composer check` passes.
- Frontend checks pass when the React starter is part of the handoff.
- Deferred work is visible in `docs/todo/current.md` or a project-specific tracker.

## Useful Next Documents

- Endpoint scaffold workflow: `docs/development/endpoint-scaffold.md`
- Local MCP server guidance: `docs/integrations/local-mcp-server.md`
- Local MCP client configuration: `docs/integrations/local-mcp-client-configuration.md`
- MCP tool policy: `docs/integrations/mcp-tools.md`
- Authentication boundary: `docs/development/authentication-boundary.md`
- Machine-client smoke workflow: `docs/development/machine-client-smoke.md`
- Database test strategy: `docs/development/test-database-strategy.md`
- Release policy: `docs/development/release-v0.1.x-policy.md`
- Current milestone: `docs/milestones/2026-05-client-delivery-hardening.md`
- GitHub Issue: `#150`
