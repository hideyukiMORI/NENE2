# PostgreSQL verwenden

NENE2s `PdoConnectionFactory` unterstützt den `pgsql`-Adapter von Haus aus. Diese Anleitung behandelt die Einrichtungsschritte und PostgreSQL-spezifische Muster, die sich von MySQL und SQLite unterscheiden.

## Docker Compose-Einrichtung

Einen `postgres`-Service hinzufügen und die Umgebungsvariablen für den `app`-Service setzen:

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
      DB_CHARSET: utf8    # für pgsql ignoriert — siehe Abschnitt zur Zeichenkodierung
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

## Dockerfile — pdo_pgsql-Erweiterung

Die `pdo_pgsql`-Erweiterung ist im `php:8.4-cli`-Basis-Image nicht standardmäßig aktiviert. Sie zum `Dockerfile` hinzufügen:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
```

## Phinx-Migrationskonfiguration

`charset` aus der Phinx-Umgebungskonfiguration bei Verwendung von `pgsql` weglassen — PostgreSQL verwendet diesen Schlüssel nicht:

```php
// phinx.php
$database->environment => [
    'adapter' => $database->adapter,   // 'pgsql'
    'host'    => $database->host,
    'name'    => $database->name,
    'user'    => $database->user,
    'pass'    => $database->password,
    'port'    => $database->port,
    // 'charset' => ...  ← für pgsql weglassen
],
```

Phinx mappt seine Spaltentypen automatisch auf PostgreSQL-Typen. Häufige Zuordnungen:

| Phinx-Typ | PostgreSQL-Typ |
|-----------|----------------|
| `integer` (Primärschlüssel) | `SERIAL` |
| `string` | `VARCHAR(n)` |
| `text` | `TEXT` |
| `datetime` | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| `boolean` | `BOOLEAN` |

## ID nach INSERT ermitteln — RETURNING id verwenden

`DatabaseQueryExecutorInterface::lastInsertId()` gibt auf PostgreSQL `0` zurück, weil `PDO::lastInsertId()` ein Sequenzname-Argument erfordert, das NENE2 nicht verfolgt.

`fetchOne()` mit `RETURNING id` im INSERT-SQL verwenden, um den generierten Primärschlüssel in einem einzigen Roundtrip abzurufen:

```php
// ✓ PostgreSQL-kompatibles Muster
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`RETURNING id` ist standard PostgreSQL-SQL und wird auch von SQLite >= 3.35 (2021) unterstützt. Es wird von MySQL/MariaDB **nicht** unterstützt — Repository-Implementierungen DB-spezifisch halten, wenn beide Zieldatenbanken unterstützt werden sollen.

### Warum nicht lastInsertId()?

PostgreSQL-Sequenzen sind standardmäßig `{table}_{column}_seq` benannt (z. B. `reviews_id_seq`). `PDO::lastInsertId('reviews_id_seq')` würde funktionieren, aber `DatabaseQueryExecutorInterface` stellt keinen Sequenzname-Parameter bereit. `RETURNING id` ist sauberer und vermeidet die Kopplung.

## Testdaten zwischen Tests zurücksetzen

Testisolation erfordert das Zurücksetzen der Tabelle zwischen jedem Testfall. Das korrekte SQL unterscheidet sich je nach Adapter:

```php
protected function setUp(): void
{
    // PostgreSQL — setzt die SERIAL-Sequenz auf 1 zurück
    $this->executor->execute('TRUNCATE reviews RESTART IDENTITY CASCADE');

    // MySQL — TRUNCATE setzt AUTO_INCREMENT implizit zurück
    // $this->executor->execute('TRUNCATE reviews');

    // SQLite — TRUNCATE wird nicht unterstützt; DELETE + Sequenz-Reset verwenden
    // $this->executor->execute('DELETE FROM reviews');
    // $this->executor->execute("DELETE FROM sqlite_sequence WHERE name = 'reviews'");
}
```

`TRUNCATE … RESTART IDENTITY CASCADE` ist PostgreSQL-spezifisch. `RESTART IDENTITY` setzt die Sequenz zurück, sodass das nächste INSERT `id = 1` zurückgibt und Test-Assertions deterministisch bleiben. `CASCADE` kürzt abhängige Tabellen in derselben Anweisung.

## Zeichenkodierung — DB_CHARSET wird für pgsql ignoriert

Der `pgsql`-DSN enthält keinen `charset`-Parameter. `DB_CHARSET` / `DatabaseConfig::charset` wird für den `pgsql`-Adapter stillschweigend ignoriert — nur der `mysql`-Adapter verwendet ihn im DSN.

PostgreSQL bestimmt die Client-Kodierung aus `server_encoding` zum Verbindungszeitpunkt. Mit dem Standard-Locale erstellte Datenbanken verwenden **UTF-8**, was fast immer das Gewünschte ist.

Um eine bestimmte Kodierung zu erzwingen, `DATABASE_URL` mit dem `options`-Parameter verwenden:

```
DATABASE_URL=pgsql://myapp:secret@db:5432/myapp?options=--client_encoding%3DUTF8
```

Oder nach dem Verbindungsaufbau einen `SET`-Befehl ausgeben (nicht direkt über `PdoDatabaseQueryExecutor` möglich — dafür wäre eine benutzerdefinierte Connection Factory erforderlich).
