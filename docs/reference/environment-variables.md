# Environment Variables

All environment variables recognised by NENE2.
Set them in `.env` (loaded by phpdotenv) or export them before starting the server.

## Application

| Variable | Type | Default | Description |
|---|---|---|---|
| `APP_ENV` | string | `local` | Runtime environment. Accepted: `local`, `test`, `production`. |
| `APP_DEBUG` | boolean | `false` | Enable debug output. Use `true` only in development. |
| `APP_NAME` | string | `NENE2` | Application name used in log output. Must not be empty. |

## Authentication

| Variable | Type | Default | Description |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(empty — disabled)* | API key expected in the `X-NENE2-API-Key` request header for machine client endpoints. Leave empty to disable the machine key path entirely. |
| `NENE2_LOCAL_JWT_SECRET` | string | *(empty — disabled)* | HMAC-HS256 secret used by the local MCP server to gate write tools. Leave empty to allow read-only tool access without a token. |

## Local MCP server

| Variable | Type | Default | Description |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(required)* | Base URL the local MCP server uses to proxy API calls (e.g. `http://app`). Required when running the MCP server in Docker Compose. |

## Database

| Variable | Type | Default | Description |
|---|---|---|---|
| `DATABASE_URL` | string | *(empty — use `DB_*`)* | Full database connection URL. When non-empty, overrides all individual `DB_*` variables. |
| `DB_ADAPTER` | string | `mysql` | Database driver. Accepted: `sqlite`, `mysql`. |
| `DB_HOST` | string | `127.0.0.1` | Database hostname or IP. |
| `DB_PORT` | integer | `3306` | Database port (1–65535). |
| `DB_NAME` | string | `nene2` | Database name. |
| `DB_USER` | string | `nene2` | Database username. |
| `DB_PASSWORD` | string | *(empty)* | Database password. |
| `DB_CHARSET` | string | `utf8mb4` | Database character set. |

::: warning Never commit secrets
Do not commit `.env` files containing passwords, API keys, or JWT secrets to version control.
:::
