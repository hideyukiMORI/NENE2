# Coding Standards

NENE2 should feel modern, small, and predictable. These rules are the source of truth for implementation style.

## PHP Baseline

- Target current stable PHP first and keep PHP 8.3/8.4 features in view.
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

## API and HTML

- JSON APIs are the primary product surface.
- OpenAPI should describe public request, response, and error shapes.
- Server HTML should stay minimal and easy to replace with a SPA shell.
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

## Static Analysis and Formatting

Planned quality tools:

- PHPUnit for automated tests
- PHPStan or Psalm for static analysis
- PHP-CS-Fixer or PHPCS for style enforcement
- OpenAPI validation for API contracts

When a tool is introduced, document its command and expected level here.

## AI Readability

- Name files and classes after their role.
- Keep functions short enough to inspect without jumping through many layers.
- Prefer explicit return types and simple data shapes.
- Record architecture decisions in `docs/` when they affect future implementation.
