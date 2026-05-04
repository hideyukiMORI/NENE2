# Project Layout

This document defines the standard NENE2 repository layout. It is the source of truth for where Composer, PHP code, tests, document root files, and frontend source code should live.

## Standard Layout

```text
NENE2/
├── composer.json
├── compose.yaml
├── docker/              # development containers
├── src/                 # NENE2 framework core
│   ├── DependencyInjection/ # container contracts, providers, and wiring helpers
│   ├── Error/           # exception to response mapping
│   ├── Http/            # PSR HTTP helpers and response concerns
│   ├── Middleware/      # PSR-15 middleware pipeline
│   ├── Routing/         # route definitions and dispatch policy
│   └── View/            # view rendering interfaces and adapters
├── tests/               # PHPUnit / architecture / contract tests
├── config/              # framework default config or examples
├── database/            # application migrations, seeds, and schema docs
│   ├── migrations/
│   ├── seeds/
│   └── schema/
├── templates/           # native PHP templates and thin server HTML source
├── public_html/         # web document root
│   ├── index.php        # front controller
│   └── assets/          # built frontend assets
├── frontend/            # React/Vue/Vite source
│   ├── package.json
│   └── src/
├── docs/
├── .cursor/rules/
└── README.md
```

## Design Decisions

### Composer Lives at the Repository Root

NENE2 is a PHP framework project first, so `composer.json` belongs at the repository root.

This keeps the project natural for Composer, Packagist, static analysis, and test tools:

- `src/` is the framework package source.
- `tests/` is the package test suite.
- `vendor/` is installed once at the project root.
- `composer.lock` is committed for reproducible framework development tooling.
- framework consumers can recognize the repository as a normal Composer package.

Do not place the primary Composer project inside `backend/` unless NENE2 later becomes a multi-package monorepo. If that happens, document the package split before moving Composer files.

### `src/` Contains Framework Core

`src/` is for reusable NENE2 framework code:

- HTTP abstractions
- routing and dispatch
- middleware pipeline
- exception to response mapping
- dependency injection and wiring helpers
- application/service boundaries
- configuration loading
- response rendering
- view rendering interfaces and adapters
- integration interfaces

Application-specific examples can be added later under `examples/` if needed, but should not be mixed into the framework core.

HTTP runtime follows PSR-7 / PSR-15 / PSR-17 first. See `docs/development/http-runtime.md`.

Dependency injection follows PSR-11 with explicit wiring first. See `docs/development/dependency-injection.md`.

### `tests/` Mirrors Framework Behavior

`tests/` is the home for PHP verification:

- unit tests
- integration tests
- HTTP or OpenAPI contract tests
- architecture tests when introduced

Test files should make framework behavior safe to refactor. They should not depend on frontend build output unless the specific test is about asset integration.

### `config/` Is Non-Secret Configuration

`config/` contains default configuration, examples, or framework-level config definitions. Secrets belong in environment variables or ignored local files, never in committed config.

### `database/` Contains Application Schema Work

`database/` contains migrations, seeds, and schema documentation for applications built with NENE2. Framework core should stay database-independent.

The first migration tool candidate is Phinx, but no runner is adopted until the database adapter layer is introduced. See `docs/development/database-migrations.md`.

### `templates/` Contains Thin Server HTML Source

`templates/` contains native PHP templates and thin server-rendered HTML source. It is not part of the document root and should not contain business logic.

Template engines are optional adapters, not default dependencies. See `docs/development/view-rendering.md`.

### `public_html/` Is the Document Root

`public_html/` is the only directory intended to be exposed by a web server.

It should contain:

- `index.php` as the front controller once runtime code exists
- built frontend assets under `public_html/assets/`
- other public static files that are safe to serve directly

It should not contain:

- `src/`
- `vendor/`
- `.env`
- frontend source files
- tests
- private config

The name `public_html` is intentionally compatible with common hosting terminology, but NENE2 still follows the modern rule: expose only the document root, keep the project root private.

### `frontend/` Contains Source, Not Public Files

`frontend/` is for optional React, Vue, TypeScript, and Vite source code. Its build output may be written to `public_html/assets/`, but source files and `node_modules/` must stay outside the document root.

NENE2 should not force React or Vue at the framework level. The first starter may choose one, but this layout keeps the frontend replaceable.

## Future Extension Points

These directories may be added later when the need is concrete:

- `examples/` for sample applications
- `docs/openapi/` for API contracts
- `bin/` for CLI entry points
- `storage/` for local runtime output in application templates
- `packages/` only if NENE2 becomes a true multi-package repository

Add them through Issues and document why they exist before broad use.

## Docker

`docker/` contains development container definitions. Docker is the standard way to run NENE2 with the required PHP version without changing the host OS.

The first container serves `public_html/` through Apache and runs Composer, PHPUnit, and PHPStan in the same PHP runtime.
