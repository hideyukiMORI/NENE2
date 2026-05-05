# Database Migration Policy

NENE2 should support database-backed applications without making the framework core depend on a database.

## Position

Database migrations are part of the standard application layout, but the migration runner is not part of the initial framework core.

The standard NENE2 approach is:

- Keep framework core database-independent.
- Provide predictable directories for application schema work.
- Use Phinx as the first migration tool.
- Prefer an existing migration tool over a custom runner.
- Keep migration history readable to humans and AI agents.

## Standard Directories

```text
database/
├── migrations/      # versioned schema changes
├── seeds/           # local/dev seed data
└── schema/          # snapshots, ERD, generated schema docs
```

## Tool Direction

Phinx is the first migration runner for NENE2 because it is small, framework-independent, and easy to understand. This decision is recorded in `docs/adr/0004-use-phinx-migration-runner.md`.

Doctrine Migrations remains a valid option if NENE2 later adopts Doctrine DBAL or needs stronger schema abstraction.

Do not build a custom migration runner first. Migration ordering, rollback, multi-environment state, and failed deployments are easy places to create long-term risk.

## Commands

Migration commands are exposed through Composer:

```bash
docker compose run --rm app composer migrations:status
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer migrations:rollback
docker compose run --rm app composer migrations:seed
```

`composer check` does not run migrations because it should not require a configured database.

## Configuration

Phinx configuration lives in `phinx.php`.

Supported environment variables:

- `DATABASE_URL`
- `DB_ENV`
- `DB_ADAPTER`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `DB_CHARSET`

Production credentials must come from environment variables or platform secrets. Do not commit local `.env` files with database credentials.

## Naming

Migration names should be explicit and time-sortable.

Recommended pattern:

```text
YYYYMMDDHHMMSS_describe_change.php
```

Examples:

```text
20260504120000_create_users_table.php
20260504123000_add_status_to_jobs.php
```

## Rollback

Every migration should define a rollback strategy when the tool supports it.

If a change cannot be safely rolled back, document why in the migration and in the related PR. Destructive data changes should be split from schema changes when practical.

## Seeds

`database/seeds/` is for local and development seed data. Seed data must not contain production secrets or private data.

Seeds should be deterministic and small enough for tests or local setup to understand.

## Schema Snapshots

`database/schema/` is for generated schema documentation, ERD exports, or snapshots used to review the current database shape.

Generated schema files should be reproducible. If a schema file is too noisy to review, document the generation command before committing it.

## Future Implementation

The database adapter milestone should decide:

- transaction policy
- test database strategy

Until that milestone, the directories are placeholders and the docs are the source of truth.
