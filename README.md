# NENE2

PHP micro-framework: JSON APIs first, minimal server HTML, easy React/Vue SPA integration, structure friendly to AI tooling.

NENE2 is a small, modern PHP framework foundation extracted from the lessons of the original NeNe framework and the 9lick.me modernization work. It is designed for projects that want to ship JSON APIs quickly, keep server-rendered HTML thin, and add React or Vue without turning the backend into frontend build glue.

## Theme

- **API first**: define behavior through clear HTTP boundaries and OpenAPI contracts.
- **Simple HTML**: keep server HTML minimal, predictable, and easy to replace with SPA shells.
- **Frontend ready**: support React or Vue as optional frontend layers rather than framework lock-in.
- **AI readable**: prefer explicit directories, small classes, typed boundaries, and documented decisions.
- **Modern PHP**: use strict types, PSR-oriented style, dependency injection, automated tests, and static analysis.

## Development Principles

NENE2 optimizes for fast, calm development. The codebase should be easy for a solo developer, a team, or an AI agent to understand without hidden conventions.

- Keep domain and use-case code decoupled from HTTP, database, template, and CLI details.
- Use PSR-7 / PSR-15 / PSR-17 as the HTTP runtime direction.
- Make behavior testable before adding framework magic.
- Treat OpenAPI as the public API contract and keep MCP integrations behind the same API boundary.
- Keep template engines optional; server HTML should stay thin and replaceable.
- Prefer small, boring primitives over clever abstractions.
- Record workflow, roadmap, and implementation decisions in `docs/` rather than only in chat.

## Repository Layout

NENE2 uses a single repository with Composer at the root, PHP framework code in `src/`, tests in `tests/`, a web document root in `public_html/`, and optional frontend source in `frontend/`.

```text
.
├── composer.json
├── src/                 # NENE2 framework core
│   ├── Http/
│   ├── Routing/
│   ├── Middleware/
│   └── Error/
├── tests/               # PHPUnit / architecture / contract tests
├── config/              # framework default config or examples
├── database/            # migrations, seeds, and schema docs
├── templates/           # native PHP templates and thin server HTML source
├── public_html/         # web document root
│   └── assets/          # built frontend assets
├── frontend/            # React/Vue/Vite source
│   └── src/
├── docs/
├── LICENSE
└── README.md
```

See `docs/development/project-layout.md` for the design details and placement rules.

## PHP Tooling

NENE2 targets PHP `>=8.4.1 <9.0`. Docker is the standard development runtime, so the host OS does not need to provide that PHP version.

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

The web entry point is served from `public_html/` at `http://localhost:8080/`.

OpenAPI and Swagger UI are available in Docker development:

- OpenAPI: `http://localhost:8080/openapi.php`
- Swagger UI: `http://localhost:8080/docs/`

Composer lives at the repository root and provides the first verification commands:

```bash
composer validate
composer test
composer analyse
composer check
```

See `docs/development/php-runtime.md` and `docs/development/docker.md` for runtime and tooling details.

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
