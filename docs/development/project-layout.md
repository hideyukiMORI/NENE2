# Project Layout

This document defines the standard NENE2 repository layout. It is the source of truth for where Composer, PHP code, tests, document root files, and frontend source code should live.

## Standard Layout

```text
NENE2/
├── composer.json
├── src/                 # NENE2 framework core
├── tests/               # PHPUnit / architecture / contract tests
├── config/              # framework default config or examples
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
- framework consumers can recognize the repository as a normal Composer package.

Do not place the primary Composer project inside `backend/` unless NENE2 later becomes a multi-package monorepo. If that happens, document the package split before moving Composer files.

### `src/` Contains Framework Core

`src/` is for reusable NENE2 framework code:

- HTTP abstractions
- routing and dispatch
- application/service boundaries
- configuration loading
- response rendering
- integration interfaces

Application-specific examples can be added later under `examples/` if needed, but should not be mixed into the framework core.

### `tests/` Mirrors Framework Behavior

`tests/` is the home for PHP verification:

- unit tests
- integration tests
- HTTP or OpenAPI contract tests
- architecture tests when introduced

Test files should make framework behavior safe to refactor. They should not depend on frontend build output unless the specific test is about asset integration.

### `config/` Is Non-Secret Configuration

`config/` contains default configuration, examples, or framework-level config definitions. Secrets belong in environment variables or ignored local files, never in committed config.

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
