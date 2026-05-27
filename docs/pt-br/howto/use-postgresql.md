# Como usar PostgreSQL

O `PdoConnectionFactory` do NENE2 suporta o adapter `pgsql` nativamente. Este guia aborda
os passos de configuração e padrões específicos do PostgreSQL que diferem dos padrões do MySQL e SQLite.

## Configuração do Docker Compose

Adicione um serviço `postgres` e defina as variáveis de ambiente para o serviço `app`:

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
      DB_CHARSET: utf8    # ignorado para pgsql — veja a seção de charset abaixo
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

## Dockerfile — extensão pdo_pgsql

A extensão `pdo_pgsql` não está habilitada por padrão na imagem base `php:8.4-cli`. Adicione-a ao
seu `Dockerfile`:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
```

## Configuração de migração Phinx

Omita `charset` da configuração de ambiente do Phinx ao usar `pgsql` — o PostgreSQL não usa
essa chave:

```php
// phinx.php
$database->environment => [
    'adapter' => $database->adapter,   // 'pgsql'
    'host'    => $database->host,
    'name'    => $database->name,
    'user'    => $database->user,
    'pass'    => $database->password,
    'port'    => $database->port,
    // 'charset' => ...  ← omitir para pgsql
],
```

O Phinx mapeia seus tipos de coluna para tipos PostgreSQL automaticamente. Mapeamentos comuns:

| Tipo Phinx | Tipo PostgreSQL |
|---|---|
| `integer` (chave primária) | `SERIAL` |
| `string` | `VARCHAR(n)` |
| `text` | `TEXT` |
| `datetime` | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| `boolean` | `BOOLEAN` |

## Obter o ID após INSERT — use RETURNING id

`DatabaseQueryExecutorInterface::lastInsertId()` retorna `0` no PostgreSQL porque
`PDO::lastInsertId()` requer um argumento de nome de sequência que o NENE2 não rastreia.

Use `fetchOne()` com `RETURNING id` no SQL do INSERT para recuperar a chave primária gerada em
uma única viagem de ida e volta:

```php
// ✓ Padrão compatível com PostgreSQL
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`RETURNING id` é SQL padrão do PostgreSQL e também é suportado pelo SQLite ≥ 3.35 (2021). **Não**
é suportado pelo MySQL/MariaDB — mantenha suas implementações de repositório específicas por banco de dados se precisar
suportar ambos.

### Por que não lastInsertId()?

Sequências PostgreSQL têm o nome `{tabela}_{coluna}_seq` por padrão (por exemplo, `reviews_id_seq`).
`PDO::lastInsertId('reviews_id_seq')` funcionaria, mas `DatabaseQueryExecutorInterface` não
expõe um parâmetro de nome de sequência. `RETURNING id` é mais limpo e evita o acoplamento.

## Resetar dados de teste entre testes

O isolamento de testes requer resetar a tabela entre cada caso de teste. O SQL correto difere por
adapter:

```php
protected function setUp(): void
{
    // PostgreSQL — reseta a sequência SERIAL para 1
    $this->executor->execute('TRUNCATE reviews RESTART IDENTITY CASCADE');

    // MySQL — TRUNCATE reseta AUTO_INCREMENT implicitamente
    // $this->executor->execute('TRUNCATE reviews');

    // SQLite — TRUNCATE não é suportado; use DELETE + reset de sequência
    // $this->executor->execute('DELETE FROM reviews');
    // $this->executor->execute("DELETE FROM sqlite_sequence WHERE name = 'reviews'");
}
```

`TRUNCATE … RESTART IDENTITY CASCADE` é específico do PostgreSQL. `RESTART IDENTITY` reseta a
sequência para que o próximo insert retorne `id = 1`, mantendo as asserções dos testes determinísticas.
`CASCADE` trunca tabelas dependentes na mesma instrução.

## Codificação de caracteres — DB_CHARSET é ignorado para pgsql

O DSN `pgsql` não inclui um parâmetro `charset`. `DB_CHARSET` / `DatabaseConfig::charset`
é silenciosamente ignorado para o adapter `pgsql` — apenas o adapter `mysql` o usa no DSN.

O PostgreSQL determina a codificação do cliente a partir de `server_encoding` no momento da conexão. Bancos de dados
criados com a localidade padrão usam **UTF-8**, que é quase sempre o que você deseja.

Para forçar uma codificação específica, use `DATABASE_URL` com o parâmetro `options`:

```
DATABASE_URL=pgsql://myapp:secret@db:5432/myapp?options=--client_encoding%3DUTF8
```

Ou emita um comando `SET` após a conexão ser estabelecida (não possível através de
`PdoDatabaseQueryExecutor` diretamente — você precisaria de uma fábrica de conexão customizada).
