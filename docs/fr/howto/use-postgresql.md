# Comment utiliser PostgreSQL

Le `PdoConnectionFactory` de NENE2 supporte l'adaptateur `pgsql` nativement. Ce guide couvre les étapes de configuration et les patterns spécifiques à PostgreSQL qui diffèrent des valeurs par défaut de MySQL et SQLite.

## Configuration Docker Compose

Ajouter un service `postgres` et définir les variables d'environnement pour le service `app` :

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
      DB_CHARSET: utf8    # ignoré pour pgsql — voir la section charset ci-dessous
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

## Dockerfile — extension pdo_pgsql

L'extension `pdo_pgsql` n'est pas activée par défaut dans l'image de base `php:8.4-cli`. L'ajouter à votre `Dockerfile` :

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
```

## Configuration des migrations Phinx

Omettre `charset` de la configuration d'environnement Phinx lors de l'utilisation de `pgsql` — PostgreSQL n'utilise pas cette clé :

```php
// phinx.php
$database->environment => [
    'adapter' => $database->adapter,   // 'pgsql'
    'host'    => $database->host,
    'name'    => $database->name,
    'user'    => $database->user,
    'pass'    => $database->password,
    'port'    => $database->port,
    // 'charset' => ...  ← omettre pour pgsql
],
```

Phinx mappe ses types de colonnes vers les types PostgreSQL automatiquement. Mappages courants :

| Type Phinx | Type PostgreSQL |
|---|---|
| `integer` (clé primaire) | `SERIAL` |
| `string` | `VARCHAR(n)` |
| `text` | `TEXT` |
| `datetime` | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| `boolean` | `BOOLEAN` |

## Obtenir l'ID après INSERT — utiliser RETURNING id

`DatabaseQueryExecutorInterface::lastInsertId()` retourne `0` sur PostgreSQL parce que
`PDO::lastInsertId()` nécessite un argument de nom de séquence que NENE2 ne suit pas.

Utiliser `fetchOne()` avec `RETURNING id` dans votre SQL INSERT pour récupérer la clé primaire générée
en un seul aller-retour :

```php
// Pattern compatible PostgreSQL
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`RETURNING id` est du SQL PostgreSQL standard et est également supporté par SQLite ≥ 3.35 (2021). Il n'est **pas** supporté par MySQL/MariaDB — gardez vos implémentations de repository spécifiques à la DB si vous devez cibler les deux.

### Pourquoi pas lastInsertId() ?

Les séquences PostgreSQL sont nommées `{table}_{colonne}_seq` par défaut (ex. `reviews_id_seq`).
`PDO::lastInsertId('reviews_id_seq')` fonctionnerait, mais `DatabaseQueryExecutorInterface` n'expose pas de paramètre de nom de séquence. `RETURNING id` est plus propre et évite le couplage.

## Réinitialiser les données de test entre les tests

L'isolation des tests nécessite de réinitialiser la table entre chaque cas de test. Le SQL correct diffère selon l'adaptateur :

```php
protected function setUp(): void
{
    // PostgreSQL — réinitialise la séquence SERIAL à 1
    $this->executor->execute('TRUNCATE reviews RESTART IDENTITY CASCADE');

    // MySQL — TRUNCATE réinitialise AUTO_INCREMENT implicitement
    // $this->executor->execute('TRUNCATE reviews');

    // SQLite — TRUNCATE n'est pas supporté ; utiliser DELETE + réinitialisation de séquence
    // $this->executor->execute('DELETE FROM reviews');
    // $this->executor->execute("DELETE FROM sqlite_sequence WHERE name = 'reviews'");
}
```

`TRUNCATE … RESTART IDENTITY CASCADE` est spécifique à PostgreSQL. `RESTART IDENTITY` réinitialise la séquence pour que le prochain insert retourne `id = 1`, gardant les assertions de test déterministes.
`CASCADE` tronque les tables dépendantes dans la même instruction.

## Encodage de caractères — DB_CHARSET est ignoré pour pgsql

Le DSN `pgsql` n'inclut pas de paramètre `charset`. `DB_CHARSET` / `DatabaseConfig::charset`
est silencieusement ignoré pour l'adaptateur `pgsql` — seul l'adaptateur `mysql` l'utilise dans le DSN.

PostgreSQL détermine l'encodage client depuis `server_encoding` au moment de la connexion. Les bases de données créées avec la locale par défaut utilisent **UTF-8**, ce qui est presque toujours ce que vous voulez.

Pour forcer un encodage spécifique, utiliser `DATABASE_URL` avec le paramètre `options` :

```
DATABASE_URL=pgsql://myapp:secret@db:5432/myapp?options=--client_encoding%3DUTF8
```

Ou émettre une commande `SET` après l'établissement de la connexion (non possible directement via
`PdoDatabaseQueryExecutor` — vous auriez besoin d'une factory de connexion personnalisée).
