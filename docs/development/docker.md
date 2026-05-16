# Docker Development

NENE2 uses Docker as the standard development runtime so contributors do not need to install the required PHP version on the host OS.

## Services

`compose.yaml` defines two services:

- `app`: PHP 8.4 Apache container with Composer installed. Apache serves `public_html/` as the document root.
- `mysql`: MySQL 8.4 container for real database adapter verification. Not required for normal development or CI — SQLite covers the default test path.

## First Setup

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
```

## Verification

```bash
docker compose run --rm app composer validate --strict
docker compose run --rm app composer test
docker compose run --rm app composer analyse
docker compose run --rm app composer check
```

Use these Docker commands as the default verification path when the host PHP version is older than NENE2's runtime requirement.

## Web Server

```bash
docker compose up -d app
```

Then open:

```text
http://localhost:8080/
```

API documentation endpoints:

```text
http://localhost:8080/openapi.php
http://localhost:8080/docs/
```

Stop it with:

```bash
docker compose down
```

## MySQL (Optional)

The `app` service connects to SQLite by default. The `mysql` service exists for verifying real database adapter behavior — migrations, write operations, and `lastInsertId()` across adapters.

Start MySQL and run migrations:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
```

Verify the MySQL adapter:

```bash
docker compose run --rm app composer test:database:mysql
```

Stop MySQL when done:

```bash
docker compose stop mysql
```

### SQLite vs MySQL

| | SQLite | MySQL |
|---|---|---|
| When to use | Default development and CI | Adapter verification, production-like schema testing |
| Requires separate container | No | Yes (`docker compose up -d mysql`) |
| Migration needed | No (in-memory per test) | Yes (`composer migrations:migrate`) |
| Test command | `composer test:database` | `composer test:database:mysql` |

The framework's lazy PDO connection means starting the `app` service without MySQL does not cause errors — a connection is only opened when the first query runs.

## Runtime Boundary

Only `public_html/` is exposed by Apache. Source code, `vendor/`, tests, config, and frontend source stay outside the document root.
