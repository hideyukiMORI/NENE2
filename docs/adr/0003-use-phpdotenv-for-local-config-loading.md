# ADR 0003: Use phpdotenv for Local Config Loading

## Status

accepted

## Context

NENE2 needs a typed configuration boundary so runtime code can avoid direct access to raw environment arrays, `getenv()`, `$_ENV`, and `$_SERVER`.

Local and test environments also need a predictable way to load `.env` files without making production depend on committed local files.

## Decision

Use `vlucas/phpdotenv` as the first local and test dotenv loader.

Add typed config objects in `src/Config/` and keep raw environment reads inside the config loading layer.

The first implementation will load:

- committed safe defaults
- optional `.env` values through phpdotenv
- real environment variables
- explicit test overrides

Later sources override earlier values.

Do not require `.env` in production. Production should rely on real environment variables or platform secrets.

Alternatives considered:

- Reading `$_ENV` or `$_SERVER` directly in bootstrap code: lower dependency footprint, but it spreads raw configuration access and weakens the typed boundary.
- Symfony Dotenv: mature and well maintained, but broader Symfony integration is not needed for the first NENE2 config step.
- Custom `.env` parsing: avoids a dependency, but dotenv parsing is easy to get subtly wrong and is not the framework feature NENE2 should spend complexity on.

## Consequences

NENE2 gains a small, familiar dotenv loading path for local development while keeping runtime code dependent on typed config objects.

The config loader remains replaceable because phpdotenv usage is isolated behind `src/Config/`.

Future config groups can be added without passing raw arrays through the application.

## Related

- Issue: `#47`
- PR: `#000`
- Supersedes: none
- Superseded by: none
