# Test Database Strategy

NENE2 database adapter tests should be deterministic by default and should not require a developer-specific database server.

## Default Strategy

Framework-maintained database adapter tests use SQLite in-memory databases first.

Reasons:

- tests run inside the existing PHP container
- no local MySQL or PostgreSQL credentials are required
- each test can create its own schema
- tests stay fast enough for `composer check`
- adapters remain easy to inspect and reason about

The default command for focused database adapter checks is:

```bash
docker compose run --rm app composer test:database
```

`composer check` still runs the full PHPUnit suite, including database adapter tests.

## Test Shape

Database adapter tests should:

- create schema inside the test
- use tiny deterministic data
- avoid production-like credentials
- avoid relying on migration state unless the test is specifically about migrations
- prefer typed config objects over raw environment variables
- keep SQL expectations close to the adapter being tested

## External Databases

MySQL verification is available through Docker Compose for adapter behavior that SQLite cannot cover.

Start the service and run the opt-in command:

```bash
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql
```

This path verifies PDO MySQL connection creation, parameterized query execution, and transaction rollback against a real MySQL service.

External database tests stay opt-in until CI has a documented service container and safe credentials. They do not block the default local `composer check` path.

The Docker Compose defaults are local-only development credentials. Override them through environment variables when needed, and do not commit real database secrets.

## Migration Tests

Migration tests should be separate from repository adapter tests.

When migration tests are introduced, they should define:

- the database service used in CI
- how schemas are reset between runs
- whether seeds are allowed
- which Composer command runs them
- what happens when a migration is intentionally irreversible
