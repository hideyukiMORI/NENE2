# Coding Standards

NENE2 should feel modern, small, and predictable. These rules are the source of truth for implementation style.

## PHP Baseline

- Target PHP `>=8.4.1 <9.0`. See `docs/development/php-runtime.md`.
- New PHP files must use `declare(strict_types=1);`.
- Follow PSR-12 unless a narrower project rule says otherwise.
- Do not add large file-level copyright or project banners by default.
- Prefer immutable value objects and readonly properties where they clarify intent.
- Use native types, enums, and small DTOs instead of unstructured arrays at boundaries.
- Avoid framework magic that hides control flow from tests, static analysis, or AI tools.
- Keep public project docs, API contracts, OpenAPI text, and public error metadata in English. See `docs/development/language-policy.md`.

## Architecture

- Keep use cases independent from HTTP, database, templates, CLI, and frontend assets.
- Depend on interfaces at infrastructure boundaries when it reduces coupling.
- Select concrete packages using the documented package selection criteria and ADR triggers. See `docs/development/package-selection.md`.
- Prefer constructor injection for required dependencies.
- Use typed config objects at runtime instead of passing raw arrays through the application.
- Use readonly DTOs or command objects for use case input boundaries.
- Keep `getenv()`, `$_ENV`, and `$_SERVER` access inside the config loading boundary.
- Use PSR-11 as the container boundary.
- Use PSR-3 as the logging boundary when logging is needed.
- Prefer explicit factories and service providers over autowiring by default.
- Do not use the container as a service locator inside domain or use-case code.
- Keep controllers thin: parse input, call a use case, return a response.
- Keep persistence details inside repositories or adapters.
- Treat public API schemas as contracts, not incidental output.
- Keep request validation layered: middleware for HTTP-wide concerns, controllers or handlers for DTO mapping, and use cases for business invariants.

## HTTP Runtime

- Use PSR-7 for request and response messages.
- Use PSR-15 for middleware and request handlers.
- Use PSR-17 for factories.
- Keep routing explicit and route tables readable.
- Keep middleware order explicit and documented.
- Treat CORS, security headers, request id, and request size limits as API baseline middleware concerns.
- Keep `public_html/index.php` as the front controller.
- Do not introduce controller resolver magic before the DI policy is settled.
- See `docs/development/http-runtime.md`.
- See `docs/development/middleware-security.md`.

## API and HTML

- JSON APIs are the primary product surface.
- OpenAPI should describe public request, response, and error shapes.
- Request validation should use layered validation and readonly DTOs before calling use cases.
- Server HTML should stay minimal and easy to replace with a SPA shell.
- Native PHP templates are the first standard HTML path; full template engines are optional adapters.
- Keep server HTML source in `templates/` and view abstractions in `src/View/`.
- React is the first framework-maintained frontend starter direction.
- React/Vue integration should remain optional for framework consumers and isolated from backend domain logic.

## Error Handling and Logging

- Use explicit domain or application exceptions when callers can act on them.
- Do not swallow exceptions silently.
- Keep user-facing error responses stable and documented.
- Use RFC 9457 Problem Details for public JSON API errors.
- Use `application/problem+json` for Problem Details responses.
- Do not leak stack traces, SQL, file paths, secrets, or private identifiers in public error responses.
- Return request validation failures as `validation-failed` Problem Details with structured `errors`.
- Prefer structured logs with request context once logging is introduced.
- Use request ids to correlate responses, logs, error handling, and future metrics.
- Do not log secrets, tokens, passwords, or private payloads.
- See `docs/development/api-error-responses.md`.
- See `docs/development/request-validation.md`.
- See `docs/development/observability.md`.

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

## Configuration

- Commit only non-secret config examples and defaults.
- Ignore local `.env` files and commit only `.env.example` when environment shape needs documentation.
- Prefer `vlucas/phpdotenv` as the first local/test dotenv candidate, but adopt it only when the config loader is implemented.
- Production should use real environment variables or platform secrets.
- See `docs/development/configuration.md`.

## Static Analysis and Formatting

Adopted quality tools:

- PHPUnit for automated tests
- PHPStan for static analysis
- PHP-CS-Fixer for formatting and style checks

Planned quality tools:

- ESLint, TypeScript, and Prettier for the React frontend starter
- OpenAPI validation for API contracts

When a tool is introduced, document its command and expected level here.

See `docs/development/quality-tools.md`.

## Documentation Comments

- Write PHPDoc for public framework APIs, interfaces, extension points, middleware, typed config objects, and behavior that users depend on.
- Write TSDoc for exported frontend starter utilities, hooks, types, and API client helpers.
- Do not use PHPDoc or TSDoc to repeat native types or obvious implementation details.
- Keep licensing metadata at repository level through `LICENSE`, `composer.json`, and future frontend package metadata.
- See `docs/development/documentation-comments.md`.

## AI Readability

- Name files and classes after their role.
- Keep functions short enough to inspect without jumping through many layers.
- Prefer explicit return types and simple data shapes.
- Record architecture decisions in `docs/` when they affect future implementation.
- Use ADRs for major decisions that need long-term traceability. See `docs/development/adr.md`.
- Use self-review checklists before push or PR when a task matches a checklist. See `docs/development/self-review.md`.
