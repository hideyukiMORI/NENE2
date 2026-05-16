# Local Setup Guide

This guide walks through setting up NENE2 locally from a fresh clone to a running API.

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose plugin)
- Git

No host PHP, Node.js, or MySQL installation required. All runtime dependencies run inside Docker.

## 1. Clone and Configure

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
```

Open `.env` and adjust any values if needed. The defaults work for local development without changes.

Key environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `local` | Runtime environment |
| `NENE2_MACHINE_API_KEY` | *(empty)* | Leave empty to disable machine-client auth in local dev |
| `DB_ADAPTER` | `mysql` | `sqlite` or `mysql` |
| `DB_HOST` | `mysql` | Matches the Docker Compose service name |

## 2. Build and Install

```bash
docker compose build
docker compose run --rm app composer install
```

## 3. Run Backend Checks

```bash
docker compose run --rm app composer check
```

This runs PHPUnit, PHPStan, PHP-CS-Fixer, OpenAPI validation, and MCP catalog validation in sequence. All should pass on a clean clone.

## 4. Start the Web Server

```bash
docker compose up -d app
```

Verify it is running:

```bash
curl -i http://localhost:8080/health
```

Expected response:

```json
{"status":"ok","service":"NENE2"}
```

Other useful local endpoints:

| URL | Description |
|---|---|
| `http://localhost:8080/` | Framework info |
| `http://localhost:8080/health` | Health check |
| `http://localhost:8080/examples/ping` | Ping example |
| `http://localhost:8080/examples/notes/{id}` | Note by ID (requires DB) |
| `http://localhost:8080/openapi.php` | Raw OpenAPI JSON |
| `http://localhost:8080/docs/` | Swagger UI |

## 5. Stop the Server

```bash
docker compose down
```

## Optional: MySQL Database Setup

The default test suite uses SQLite in-memory. If you want to verify the MySQL adapter or run write-operation smoke tests against a real service database:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer test:database:mysql
```

See `docs/development/docker.md` for the SQLite vs MySQL comparison.

## Optional: Machine-Client Authentication

The `/machine/health` endpoint requires an API key. To test it locally:

1. Set `NENE2_MACHINE_API_KEY=local-dev-key` in `.env`.
2. Restart the app service: `docker compose up -d app`
3. Call the protected endpoint:

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

See `docs/development/machine-client-smoke.md` for the full machine-client smoke workflow.

## Optional: Frontend Setup

```bash
npm install --prefix frontend
npm run dev --prefix frontend
```

The frontend dev server proxies API calls to the `app` container. See `docs/development/frontend-integration.md`.

## Optional: Local MCP Server

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

See `docs/integrations/local-mcp-server.md` for MCP client configuration.

## Troubleshooting

**`composer check` fails on a clean clone**
Run `docker compose run --rm app composer install` first. The `vendor/` directory is not committed.

**Port 8080 already in use**
Stop whatever is using it, or change the port mapping in `compose.yaml`:
```yaml
ports:
  - "8081:80"   # use 8081 instead
```

**MySQL connection refused during migrations**
The `mysql` container takes a few seconds to become ready after `docker compose up -d mysql`. Wait a moment and retry.

**`DB_HOST` default is `127.0.0.1` but I get connection refused**
Inside Docker containers, use the service name `mysql` as the host. The default `.env.example` and `compose.yaml` already set this correctly — check that your `.env` has `DB_HOST=mysql`.
