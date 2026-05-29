---
title: "How to use PostgreSQL"
category: database
tags: [postgresql, pdo, adapter, database]
difficulty: beginner
---

# How to use PostgreSQL

NENE2's `PdoConnectionFactory` supports the `pgsql` adapter out of the box. This guide covers
the setup steps and PostgreSQL-specific patterns that differ from the MySQL and SQLite defaults.

## Docker compose setup

Add a `postgres` service and set the environment variables for the `app` service:

```yaml
# compose.yaml
services:
  app:
    environment:
      DB_ADAPTER: pgsql
      DB_HOST: db
      DB_PORT: "5432"
      DB_NAME: myapp
      DB_USER: myapp
      DB_PASSWORD: secret
      DB_CHARSET: utf8    # ignored for pgsql — see the charset section below
    depends_on:
      db:
        condition: service_healthy

  db:
    image: postgres:17
    environment:
      POSTGRES_DB: myapp
      POSTGRES_USER: myapp
      POSTGRES_PASSWORD: secret
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U myapp -d myapp"]
      interval: 5s
      timeout: 10s
      retries: 10
```

## Dockerfile — pdo_pgsql extension

The `pdo_pgsql` extension is not enabled by default in the `php:8.4-cli` base image. Add it to
your `Dockerfile`:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
```

## Phinx migration configuration

Omit `charset` from the Phinx environment config when using `pgsql` — PostgreSQL does not use
this key:

```php
// phinx.php
$database->environment => [
    'adapter' => $database->adapter,   // 'pgsql'
    'host'    => $database->host,
    'name'    => $database->name,
    'user'    => $database->user,
    'pass'    => $database->password,
    'port'    => $database->port,
    // 'charset' => ...  ← omit for pgsql
],
```

Phinx maps its column types to PostgreSQL types automatically. Common mappings:

| Phinx type | PostgreSQL type |
|---|---|
| `integer` (primary key) | `SERIAL` |
| `string` | `VARCHAR(n)` |
| `text` | `TEXT` |
| `datetime` | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| `boolean` | `BOOLEAN` |

## Getting the ID after INSERT — use RETURNING id

`DatabaseQueryExecutorInterface::lastInsertId()` returns `0` on PostgreSQL because
`PDO::lastInsertId()` requires a sequence name argument that NENE2 does not track.

Use `fetchOne()` with `RETURNING id` in your INSERT SQL to retrieve the generated primary key in
a single round trip:

```php
// ✓ PostgreSQL-compatible pattern
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`RETURNING id` is standard PostgreSQL SQL and is also supported by SQLite ≥ 3.35 (2021). It is
**not** supported by MySQL/MariaDB — keep your repository implementations DB-specific if you need
to target both.

### Why not lastInsertId()?

PostgreSQL sequences are named `{table}_{column}_seq` by default (e.g. `reviews_id_seq`).
`PDO::lastInsertId('reviews_id_seq')` would work, but `DatabaseQueryExecutorInterface` does not
expose a sequence-name parameter. `RETURNING id` is cleaner and avoids the coupling.

## Resetting test data between tests

Test isolation requires resetting the table between each test case. The correct SQL differs by
adapter:

```php
protected function setUp(): void
{
    // PostgreSQL — resets the SERIAL sequence to 1
    $this->executor->execute('TRUNCATE reviews RESTART IDENTITY CASCADE');

    // MySQL — TRUNCATE resets AUTO_INCREMENT implicitly
    // $this->executor->execute('TRUNCATE reviews');

    // SQLite — TRUNCATE is not supported; use DELETE + sequence reset
    // $this->executor->execute('DELETE FROM reviews');
    // $this->executor->execute("DELETE FROM sqlite_sequence WHERE name = 'reviews'");
}
```

`TRUNCATE … RESTART IDENTITY CASCADE` is PostgreSQL-specific. `RESTART IDENTITY` resets the
sequence so the next insert returns `id = 1`, keeping test assertions deterministic.
`CASCADE` truncates dependent tables in the same statement.

## Character encoding — DB_CHARSET is ignored for pgsql

The `pgsql` DSN does not include a `charset` parameter. `DB_CHARSET` / `DatabaseConfig::charset`
is silently ignored for the `pgsql` adapter — only the `mysql` adapter uses it in the DSN.

PostgreSQL determines the client encoding from `server_encoding` at connection time. Databases
created with the default locale use **UTF-8**, which is almost always what you want.

To force a specific encoding, use `DATABASE_URL` with the `options` parameter:

```
DATABASE_URL=pgsql://myapp:secret@db:5432/myapp?options=--client_encoding%3DUTF8
```

Or issue a `SET` command after the connection is established (not possible through
`PdoDatabaseQueryExecutor` directly — you would need a custom connection factory).
