# NENE2

PHP micro-framework: JSON APIs first, minimal server HTML, easy React starter integration, structure friendly to AI tooling.

NENE2 is a small, modern PHP framework foundation extracted from the lessons of the original NeNe framework and the 9lick.me modernization work. It is designed for projects that want to ship JSON APIs quickly, keep server-rendered HTML thin, and add a React frontend starter without turning the backend into frontend build glue.

The current `v0.3.x` direction is a practical starter for LLM-assisted client delivery: a maintainer can clone the repository, run a local API, share an OpenAPI contract, expose safe read-only MCP tools through the API boundary, and verify database behavior in Docker.

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
- API-key middleware for the first protected machine-client path.
- Local MCP server support for read-only OpenAPI-aligned tools.
- React + TypeScript + Vite starter kept optional and decoupled from backend runtime behavior.

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

> **Note:** NENE2 is currently `0.x.y`. The public API is still stabilising.
> Expect breaking changes between minor versions until `v1.0.0`.

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

The web entry point is served from `public_html/` at `http://localhost:8080/`.

Useful local URLs:

- API health: `http://localhost:8080/health`
- Example endpoint: `http://localhost:8080/examples/ping`
- OpenAPI: `http://localhost:8080/openapi.php`
- Swagger UI: `http://localhost:8080/docs/`

Run optional real MySQL verification when database adapter behavior should be checked against a service database:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

## Domain Layer Example

`src/Example/Note/` is the canonical reference for how each framework layer works together end-to-end. It implements a full Note CRUD with:

| Layer | File(s) |
|---|---|
| Route + handler | `GetNoteByIdHandler`, `CreateNoteHandler`, `UpdateNoteHandler`, `DeleteNoteHandler`, `ListNotesHandler` |
| Use case (domain) | `GetNoteByIdUseCase`, `CreateNoteUseCase`, `UpdateNoteUseCase`, `DeleteNoteUseCase`, `ListNotesUseCase` |
| Repository interface | `NoteRepositoryInterface` |
| PDO adapter | `PdoNoteRepository` |
| Exception mapping | `NoteNotFoundException` → `NoteNotFoundExceptionHandler` → 404 Problem Details |
| OpenAPI | `docs/openapi/openapi.yaml` — `GET/POST/PUT/DELETE /examples/notes` paths |
| Tests | `tests/Example/Note/` — unit, HTTP-level, PDO integration |

All Note endpoints are live at `http://localhost:8080/examples/notes` after `docker compose up -d app`.

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
│   ├── Config/
│   ├── DependencyInjection/
│   ├── Http/
│   ├── Log/
│   ├── Routing/
│   ├── Middleware/
│   ├── Error/
│   └── Example/Note/    # canonical domain layer example (full CRUD)
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

NENE2's planned quality baseline adds PHP-CS-Fixer for backend style checks and npm, ESLint, TypeScript, and Prettier for the React frontend starter. The frontend starter targets active Node.js LTS, commits `package-lock.json`, and keeps dependencies modern through update automation. Framework public APIs should use PHPDoc or TSDoc where comments explain contracts or extension rules. See `docs/development/quality-tools.md`, `docs/development/frontend-integration.md`, and `docs/development/documentation-comments.md`.

## Delivery Starter Docs

Start with these docs when adapting NENE2 for a small client-style API:

- Direction: `docs/integrations/llm-delivery-starter.md`
- Current milestone: `docs/milestones/2026-05-client-delivery-hardening.md`
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

## License

NENE2 is released under the MIT License.
