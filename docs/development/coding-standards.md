# Coding Standards

NENE2 should feel modern, small, and predictable. These rules are the source of truth for implementation style.

## PHP Baseline

- Target PHP `>=8.4.1 <9.0`. See `docs/development/php-runtime.md`.
- New PHP files must use `declare(strict_types=1);`.
- Follow PSR-12 unless a narrower project rule says otherwise.
- Prefer immutable value objects and readonly properties where they clarify intent.
- Use native types, enums, and small DTOs instead of unstructured arrays at boundaries.
- Avoid framework magic that hides control flow from tests, static analysis, or AI tools.

## Architecture

- Keep use cases independent from HTTP, database, templates, CLI, and frontend assets.
- Depend on interfaces at infrastructure boundaries when it reduces coupling.
- Prefer constructor injection for required dependencies.
- Keep controllers thin: parse input, call a use case, return a response.
- Keep persistence details inside repositories or adapters.
- Treat public API schemas as contracts, not incidental output.

## HTTP Runtime

- Use PSR-7 for request and response messages.
- Use PSR-15 for middleware and request handlers.
- Use PSR-17 for factories.
- Keep routing explicit and route tables readable.
- Keep `public_html/index.php` as the front controller.
- Do not introduce controller resolver magic before the DI policy is settled.
- See `docs/development/http-runtime.md`.

## API and HTML

- JSON APIs are the primary product surface.
- OpenAPI should describe public request, response, and error shapes.
- Server HTML should stay minimal and easy to replace with a SPA shell.
- Native PHP templates are the first standard HTML path; full template engines are optional adapters.
- Keep server HTML source in `templates/` and view abstractions in `src/View/`.
- React/Vue integration should be optional and isolated from backend domain logic.

## Error Handling and Logging

- Use explicit domain or application exceptions when callers can act on them.
- Do not swallow exceptions silently.
- Keep user-facing error responses stable and documented.
- Prefer structured logs with request context once logging is introduced.
- Do not log secrets, tokens, passwords, or private payloads.

## Testing

Testing is part of the design.

- Unit test use cases and domain behavior without a database when practical.
- Add integration tests around adapters and persistence only when they prove important contracts.
- Add HTTP or contract tests for public API behavior.
- Keep tests deterministic and small.
- Prefer test data builders or fixtures over large hidden setup.

## Database

- Keep framework core database-independent.
- Store application migration files in `database/migrations/`.
- Store local/dev seed data in `database/seeds/`.
- Store schema snapshots or generated schema docs in `database/schema/`.
- Prefer Phinx as the first migration tool candidate, but adopt it only when the database adapter layer is introduced.
- See `docs/development/database-migrations.md`.

## Static Analysis and Formatting

Adopted quality tools:

- PHPUnit for automated tests
- PHPStan for static analysis

Planned quality tools:

- PHP-CS-Fixer or PHPCS for style enforcement
- OpenAPI validation for API contracts

When a tool is introduced, document its command and expected level here.

## AI Readability

- Name files and classes after their role.
- Keep functions short enough to inspect without jumping through many layers.
- Prefer explicit return types and simple data shapes.
- Record architecture decisions in `docs/` when they affect future implementation.
