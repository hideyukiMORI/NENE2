# ADR 0004: Use Phinx as the First Migration Runner

## Status

accepted

## Context

NENE2 needs a predictable migration path for database-backed applications, but framework core should remain database-independent. The project already defines `database/migrations/`, `database/seeds/`, and `database/schema/` as standard directories.

The migration runner should be small, framework-independent, easy to inspect, and replaceable if a later database adapter layer needs a different strategy.

## Decision

Use Phinx as the first migration runner for NENE2 application migrations.

Phinx is added as a development dependency and configured through `phinx.php`. Migration commands are exposed through Composer scripts, while database connection values come from environment variables such as `DATABASE_URL`, `DB_ADAPTER`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.

Doctrine Migrations remains a future option if NENE2 later adopts Doctrine DBAL or needs stronger schema abstraction.

## Consequences

Benefits:

- NENE2 avoids building a custom migration runner.
- Migration files stay in the documented `database/migrations/` directory.
- Framework core remains independent from database runtime code.
- Applications get a known tool with status, migrate, rollback, and seed commands.

Costs and follow-up:

- Phinx introduces CakePHP database components as development dependencies.
- Actual database adapters and test database strategy still need focused follow-up work.
- Production database credentials must be provided through environment or platform secrets.

## Related

- Issue: `#75`
- PR: `#000`
- Supersedes: none
- Superseded by: none
