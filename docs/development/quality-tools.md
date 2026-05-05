# Quality Tools Policy

NENE2 keeps quality checks explicit, fast, and easy to run in Docker.

## Position

Quality tools are part of the framework design. They should make changes safer without forcing application users into one frontend or deployment stack.

The standard direction is:

- Backend framework development uses PHP, Composer, PHPUnit, PHPStan, and PHP-CS-Fixer.
- Frontend starter development uses React, TypeScript, Vite, npm, ESLint, and Prettier.
- OpenAPI validation should be added as the API contract grows.
- Commands should be predictable and documented before they become required in CI.
- Framework users may replace the frontend stack, but NENE2's own starter and examples should use the documented baseline.

This Issue defines the policy only. Tool installation and configuration should be added in focused follow-up Issues.

## Backend PHP Baseline

Adopted tools:

- PHPUnit for automated tests.
- PHPStan for static analysis.
- PHP-CS-Fixer for PSR-12 oriented formatting and style checks.

Planned tools:

- OpenAPI validation for public API contracts.
- Rector for modernization only when a focused migration need exists.

PHP-CS-Fixer is the preferred first formatter because it gives a simple check/fix workflow and fits the project's "easy to repair" development style.

Composer scripts:

```json
{
  "scripts": {
    "test": "phpunit",
    "analyse": "phpstan analyse",
    "cs": "php-cs-fixer fix --dry-run --diff",
    "cs:fix": "php-cs-fixer fix",
    "check": [
      "@test",
      "@analyse",
      "@cs"
    ]
  }
}
```

Do not add a tool to `composer check` until its config is committed and local Docker execution is verified.

## Frontend TypeScript Baseline

NENE2's own frontend starter direction is React + TypeScript.

The framework should not force React on applications built with NENE2. Users may choose Vue, Nuxt, Next, plain TypeScript, or another frontend stack. However, framework-maintained examples, starter files, and internal validation should use one default stack to avoid fragmented maintenance.

Planned frontend baseline:

- React for the first official starter.
- TypeScript for frontend source.
- Vite for local development and production builds.
- npm as the official package manager.
- Active Node.js LTS as the version baseline.
- ESLint with TypeScript support for linting.
- Prettier for formatting.
- `tsc --noEmit` for type checking unless the starter adopts a tool that provides an equivalent check.
- `package-lock.json` committed for reproducible installs.

Recommended future frontend scripts:

```json
{
  "scripts": {
    "type-check": "tsc --noEmit",
    "lint": "eslint .",
    "format": "prettier --check .",
    "format:fix": "prettier --write .",
    "check": "npm run type-check && npm run lint && npm run format"
  }
}
```

See `docs/development/frontend-integration.md` for npm, Node.js, lockfile, build output, and dependency update policy.

## OpenAPI Validation

OpenAPI validation should become part of the standard check path after shared schemas and stable endpoints exist.

The first validation should verify:

- the OpenAPI document parses successfully
- shared schemas are valid
- documented examples are structurally valid

Runtime response validation belongs to later contract test work.

## Command Shape

The intended split is:

```bash
composer check
npm run check --prefix frontend
```

Docker remains the standard backend runtime. Frontend commands may run on the host or in a future Node container, but the chosen approach must be documented before CI depends on it.

CI should start with backend `composer check` and expand only after each tool has committed configuration and local verification commands. See `docs/development/release-ci.md`.

## Non-Goals

- Forcing React on framework consumers.
- Adding every quality tool before there is code for it to check.
- Introducing Rector as a general-purpose automatic refactoring step.
- Making frontend checks required before `frontend/` exists.
