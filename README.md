# NENE2

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-8892BF)](https://www.php.net/)
[![Packagist](https://img.shields.io/packagist/v/hideyukimori/nene2)](https://packagist.org/packages/hideyukimori/nene2)
[![OpenAPI](https://img.shields.io/badge/OpenAPI-3.1-85EA2D)](https://raw.githubusercontent.com/hideyukiMORI/NENE2/main/docs/openapi/openapi.yaml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)

PHP micro-framework: JSON APIs first, minimal server HTML, easy React starter integration, structure friendly to AI tooling.

**[OpenAPI contract](https://raw.githubusercontent.com/hideyukiMORI/NENE2/main/docs/openapi/openapi.yaml)** — machine-readable API spec (OpenAPI 3.1). Live Swagger UI at `http://localhost:8200/docs/` after `docker compose up -d app`.

NENE2 is a small, modern PHP framework foundation designed for projects that want to ship JSON APIs quickly, keep server-rendered HTML thin, and add a React frontend starter without turning the backend into frontend build glue.

The `v1.x` foundation covers full Note/Tag CRUD, Bearer JWT auth, pagination helpers, and a six-language VitePress documentation site, with opt-in rate limiting and database health checks as production-ready opt-in features. A maintainer can clone the repository, run a local API, share an OpenAPI contract, expose safe MCP tools through the API boundary, and verify database behavior in Docker Compose.

## Project Highlights

NENE2 is built in the open, at high velocity, by a solo maintainer using an AI-assisted, Issue-driven workflow:

- **30 releases** (current: `v1.8.2`) and **730+ merged pull requests** since the first commit in May 2026 — every change lands through an Issue → branch → PR → CI pipeline; nothing is committed directly to `main`.
- **[262 how-to guides](docs/howto/README.md)** covering authentication, security, database patterns, API design, background jobs, and 100+ product feature recipes.
- **[125 runnable example apps](https://github.com/hideyukiMORI/NENE2-examples)** — each a self-contained JSON API with tests, built as field trials of the framework itself.
- **Powers the NeNe product family** — 12+ open-source, self-hosted business tools for small teams (invoicing, records, deal tracking, contact management, and more), all built on this framework and sharing its fail-closed security baseline.
- **Documented decisions** — ADRs for every architectural choice, an OpenAPI 3.1 contract, six-language documentation, and an MCP server listed on Smithery.

## Theme

- **API first**: define behavior through clear HTTP boundaries and OpenAPI contracts.
- **Simple HTML**: keep server HTML minimal, predictable, and easy to replace with SPA shells.
- **Frontend ready**: provide a React + TypeScript starter direction while keeping the frontend layer replaceable.
- **AI readable**: prefer explicit directories, small classes, typed boundaries, and documented decisions.
- **LLM delivery ready**: keep API, MCP, auth, database, and handoff docs aligned for small client-style projects.
- **Modern PHP**: use strict types, PSR-oriented style, dependency injection, automated tests, and static analysis.

## Current Capabilities

The foundation currently includes:

- PSR-7 / PSR-15 / PSR-17 HTTP runtime with explicit routing and middleware.
- OpenAPI contract, Swagger UI, and runtime contract tests for shipped JSON endpoints.
- RFC 9457 Problem Details error responses.
- Typed app and database configuration through `.env` loading boundaries.
- PSR-11 dependency injection with explicit runtime service wiring.
- PDO connection, query executor, transaction manager, SQLite tests, and opt-in MySQL verification through Docker Compose.
- Bearer JWT middleware with allowlist, blocklist, and prefix path options; `CompositeAuthMiddleware` for three-tier access models (public / Bearer / API key).
- Fail-closed JWT secret resolution (`GuardedJwtSecretResolver`): production never accepts a development signing key, and an empty secret is treated as unset rather than silently trusted.
- API-key middleware with path and method filters for machine-client endpoints.
- Server-rendered HTML via `NativePhpViewRenderer` and `HtmlResponseFactory` — thin HTML coexists with JSON API routes.
- Local MCP server support for read/write tools aligned with OpenAPI, with an authentication guard on write operations.
- Opt-in installer toolkit (`Nene2\Install`) for shared-hosting setup: preflight and config validation, release manifest handling, database schema application, and an unbranded reference renderer that stays dormant until wired.
- Audit logging base (`Nene2\Audit`): append-only audit events recorded in the same transaction as the business mutation they describe.
- React + TypeScript + Vite starter kept optional and decoupled from backend runtime behavior.

## Articles

- [I built a tiny PHP framework for AI-readable business APIs](https://dev.to/hideyukimori/i-built-a-tiny-php-framework-for-ai-readable-business-apis-48eo) — DEV Community introduction to NENE2's API-first, OpenAPI, and MCP-ready design.
- [MCP should not mean letting AI touch your database](https://dev.to/hideyukimori/mcp-should-not-mean-letting-ai-touch-your-database-57p1) — DEV Community article on keeping MCP tools behind documented HTTP/API boundaries.
- [I am building self-hosted business tools for small teams in Japan](https://dev.to/hideyukimori/i-am-building-self-hosted-business-tools-for-small-teams-in-japan-4i26) — DEV Community overview of the NeNe OSS product family built on NENE2.

## Installation

NENE2 is available on [Packagist](https://packagist.org/packages/hideyukimori/nene2).

The recommended way to start a new project is to clone the repository directly — it ships with Docker, `.env.example`, and all configuration you need out of the box:

```bash
git clone https://github.com/hideyukiMORI/NENE2.git my-project
cd my-project
```

If you want to use NENE2 as a Composer dependency in an existing project:

```bash
composer require hideyukimori/nene2
```

---

## Quick Start

Build the PHP runtime, install dependencies, and run the standard backend checks:

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
```

Start the local web runtime:

```bash
docker compose up -d app
```

The web entry point is served from `public_html/` at `http://localhost:8200/`.

Useful local URLs:

- API health: `http://localhost:8200/health`
- Example endpoint: `http://localhost:8200/examples/ping`
- OpenAPI: `http://localhost:8200/openapi.php`
- Swagger UI: `http://localhost:8200/docs/`

Run optional real MySQL verification when database adapter behavior should be checked against a service database:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Domain Layer Example

`src/Example/Note/` and `src/Example/Tag/` are **reference implementations** — they demonstrate how to use the framework, but are not part of the public API stability guarantee (see [ADR 0009](docs/adr/0009-v1.0-public-api-scope.md)). Copy and adapt the patterns into your own application; do not import these classes as library dependencies.

`src/Example/Note/` implements a full Note CRUD with:

| Layer | File(s) |
|---|---|
| Route + handler | `GetNoteByIdHandler`, `CreateNoteHandler`, `UpdateNoteHandler`, `DeleteNoteHandler`, `ListNotesHandler` |
| Use case (domain) | `GetNoteByIdUseCase`, `CreateNoteUseCase`, `UpdateNoteUseCase`, `DeleteNoteUseCase`, `ListNotesUseCase` |
| Repository interface | `NoteRepositoryInterface` |
| PDO adapter | `PdoNoteRepository` |
| Exception mapping | `NoteNotFoundException` → `NoteNotFoundExceptionHandler` → 404 Problem Details |
| OpenAPI | `docs/openapi/openapi.yaml` — `GET/POST/PUT/DELETE /examples/notes` paths |
| Tests | `tests/Example/Note/` — unit, HTTP-level, PDO integration |

All Note endpoints are live at `http://localhost:8200/examples/notes` after `docker compose up -d app`.

---

## Development Principles

NENE2 optimizes for fast, calm development. The codebase should be easy for a solo developer, a team, or an AI agent to understand without hidden conventions.

- Keep domain and use-case code decoupled from HTTP, database, template, and CLI details.
- Use PSR-7 / PSR-15 / PSR-17 as the HTTP runtime direction.
- Use PSR-11 as the DI boundary, with explicit wiring first.
- Use typed config objects at runtime; keep `.env` at the loading boundary.
- Make behavior testable before adding framework magic.
- Treat OpenAPI as the public API contract and keep MCP integrations behind the same API boundary.
- Keep template engines optional; server HTML should stay thin and replaceable.
- Prefer small, boring primitives over clever abstractions.
- Record workflow, roadmap, and implementation decisions in `docs/` rather than only in chat.

## Repository Layout

NENE2 uses a single repository with Composer at the root, PHP framework code in `src/`, tests in `tests/`, a web document root in `public_html/`, and optional React + TypeScript frontend source in `frontend/`.

```text
.
├── composer.json
├── src/                 # NENE2 framework core
│   ├── Auth/
│   ├── Config/
│   ├── Database/
│   ├── DependencyInjection/
│   ├── Error/
│   ├── Http/
│   ├── Log/
│   ├── Mcp/
│   ├── Middleware/
│   ├── Routing/
│   ├── Validation/
│   ├── View/
│   ├── Example/Note/    # canonical domain layer example (full CRUD)
│   └── Example/Tag/     # second entity example
├── tests/               # PHPUnit / architecture / contract tests
├── config/              # framework default config or examples
├── database/            # migrations, seeds, and schema docs
├── templates/           # native PHP templates and thin server HTML source
├── public_html/         # web document root
│   └── assets/          # built frontend assets
├── frontend/            # React/TypeScript/Vite source
│   └── src/
├── docs/
├── LICENSE
└── README.md
```

See `docs/development/project-layout.md` for the design details and placement rules.

## Development Commands

For a full step-by-step walkthrough from clone to running API, see `docs/development/setup.md`.

NENE2 targets PHP `>=8.4.1 <9.0`. Docker is the standard development runtime, so the host OS does not need to provide that PHP version.

```bash
docker compose run --rm app composer check
```

Composer lives at the repository root and provides the first backend verification commands:

```bash
composer validate
composer test
composer analyse
composer check
composer test:database
composer test:database:mysql
```

See `docs/development/php-runtime.md` and `docs/development/docker.md` for runtime and tooling details.

NENE2's quality baseline includes PHP-CS-Fixer for backend style checks and npm, ESLint, TypeScript, and Prettier for the React frontend starter. The frontend starter targets active Node.js LTS, commits `package-lock.json`, and keeps dependencies modern through update automation. Framework public APIs should use PHPDoc or TSDoc where comments explain contracts or extension rules. See `docs/development/quality-tools.md`, `docs/development/frontend-integration.md`, and `docs/development/documentation-comments.md`.

## How-to guides

262 task-focused guides covering authentication, security, database patterns, API design, background jobs, and 100+ product feature recipes.

**[Full guide index →](docs/howto/README.md)**

Common entry points:

- [Add a custom route](docs/howto/add-custom-route.md)
- [Add a database-backed endpoint](docs/howto/add-database-endpoint.md)
- [Add JWT authentication](docs/howto/add-jwt-authentication.md)
- [Add pagination](docs/howto/add-pagination.md)
- [Add rate limiting](docs/howto/add-rate-limiting.md)
- [Deploy to production](docs/howto/deploy-production.md)

## Reference Implementations

**[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)** — 125 field-trial applications built with `hideyukimori/nene2` as a Composer dependency. Each directory is a self-contained, runnable JSON API corresponding to one howto guide.

```bash
git clone https://github.com/hideyukiMORI/NENE2-examples.git
cd NENE2-examples/deduplog   # or any other example
composer install
vendor/bin/phpunit
```

## Delivery Starter Docs

Start with these docs when adapting NENE2 for a small client-style API:

- Direction: `docs/integrations/llm-delivery-starter.md`
- Client project start guide: `docs/development/client-project-start.md`
- Endpoint workflow: `docs/development/endpoint-scaffold.md`
- Local MCP server: `docs/integrations/local-mcp-server.md`
- Local MCP client configuration: `docs/integrations/local-mcp-client-configuration.md`
- MCP tool policy: `docs/integrations/mcp-tools.md`
- Authentication boundary: `docs/development/authentication-boundary.md`
- Machine-client smoke workflow: `docs/development/machine-client-smoke.md`
- Database test strategy: `docs/development/test-database-strategy.md`
- Patch release policy: `docs/development/release-v0.1.x-policy.md`

**Public field trial reference (optional):** [`hideyukiMORI/sakura-exhibition-nene2-field-trial`](https://github.com/hideyukiMORI/sakura-exhibition-nene2-field-trial) — client-style demo forked from **`v0.1.1`** with exhibition-shaped read-only APIs, OpenAPI, tests, local MCP, and field-trial reports. **Not affiliated with any real event;** see that repository’s `README.md`.

## Project Workflow

NENE2 uses GitHub Issues as the source of work and local Markdown files as the project memory.

1. Create or reuse a focused GitHub Issue.
2. Create an Issue-numbered branch from `main`, such as `docs/1-initial-governance`.
3. Update code and docs together, keeping the change small.
4. Commit with Conventional Commits and include the Issue number.
5. Push, open a PR, merge after checks, and return local `main` to a clean state.

See `docs/CONTRIBUTING.md`, `docs/workflow.md`, and `AGENTS.md` before changing the project.

## AI / LLM Integration

NENE2 is designed to be AI-readable and usable as a tool by AI agents.

- **[`llms.txt`](./llms.txt)** — machine-readable project summary for LLM crawlers ([llmstxt.org](https://llmstxt.org) standard).
- **[Smithery](https://smithery.ai/server/hideyukiMORI/nene2)** — NENE2 MCP server listed in the Smithery registry ([`smithery.yaml`](./smithery.yaml)).
- **[AGENTS.md](./AGENTS.md)** — entry point for AI agents working in this repository.
- **OpenAPI contract** — `GET /openapi.php` or `docs/openapi/openapi.yaml` — the authoritative API contract for LLM tool use.
- **Local MCP server** — `composer mcp` validates the MCP tool catalog; `docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php` starts the local server.
- **Reference implementations** — **[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)**: 125 runnable field-trial apps covering every major howto pattern (auth, security, queues, social features, and more). Each app is `composer install && phpunit`-ready.

## License

NENE2 is released under the MIT License.
