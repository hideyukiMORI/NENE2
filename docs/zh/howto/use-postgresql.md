# 如何使用 PostgreSQL

NENE2 的 `PdoConnectionFactory` 原生支持 `pgsql` 适配器。本指南涵盖配置步骤以及与 MySQL 和 SQLite 默认行为不同的 PostgreSQL 特定模式。

## Docker Compose 配置

添加 `postgres` 服务并为 `app` 服务设置环境变量：

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
      DB_CHARSET: utf8    # pgsql 忽略此项——详见下方字符集章节
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

## Dockerfile — pdo_pgsql 扩展

`pdo_pgsql` 扩展在 `php:8.4-cli` 基础镜像中默认未启用。将其添加到 `Dockerfile`：

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
```

## Phinx 迁移配置

使用 `pgsql` 时，在 Phinx 环境配置中省略 `charset`——PostgreSQL 不使用此键：

```php
// phinx.php
$database->environment => [
    'adapter' => $database->adapter,   // 'pgsql'
    'host'    => $database->host,
    'name'    => $database->name,
    'user'    => $database->user,
    'pass'    => $database->password,
    'port'    => $database->port,
    // 'charset' => ...  ← pgsql 省略此项
],
```

Phinx 会自动将其列类型映射到 PostgreSQL 类型。常用映射：

| Phinx 类型 | PostgreSQL 类型 |
|---|---|
| `integer`（主键） | `SERIAL` |
| `string` | `VARCHAR(n)` |
| `text` | `TEXT` |
| `datetime` | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| `boolean` | `BOOLEAN` |

## INSERT 后获取 ID——使用 RETURNING id

`DatabaseQueryExecutorInterface::lastInsertId()` 在 PostgreSQL 上返回 `0`，因为 `PDO::lastInsertId()` 需要一个 NENE2 不跟踪的序列名参数。

在 INSERT SQL 中使用 `RETURNING id` 配合 `fetchOne()` 在单次往返中获取生成的主键：

```php
// ✓ PostgreSQL 兼容模式
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`RETURNING id` 是标准 PostgreSQL SQL，SQLite ≥ 3.35（2021）也支持。**不**支持 MySQL/MariaDB——如果需要同时兼容两者，请保持 repository 实现的 DB 特异性。

### 为什么不用 lastInsertId()？

PostgreSQL 序列默认命名为 `{table}_{column}_seq`（例如 `reviews_id_seq`）。`PDO::lastInsertId('reviews_id_seq')` 可以工作，但 `DatabaseQueryExecutorInterface` 不暴露序列名参数。`RETURNING id` 更简洁，避免了这种耦合。

## 在测试间重置测试数据

测试隔离需要在每个测试用例之间重置表。不同适配器的正确 SQL 有所不同：

```php
protected function setUp(): void
{
    // PostgreSQL——将 SERIAL 序列重置为 1
    $this->executor->execute('TRUNCATE reviews RESTART IDENTITY CASCADE');

    // MySQL——TRUNCATE 隐式重置 AUTO_INCREMENT
    // $this->executor->execute('TRUNCATE reviews');

    // SQLite——不支持 TRUNCATE；使用 DELETE + 序列重置
    // $this->executor->execute('DELETE FROM reviews');
    // $this->executor->execute("DELETE FROM sqlite_sequence WHERE name = 'reviews'");
}
```

`TRUNCATE … RESTART IDENTITY CASCADE` 是 PostgreSQL 特有的。`RESTART IDENTITY` 重置序列，使下一次插入返回 `id = 1`，保持测试断言的确定性。`CASCADE` 在同一语句中截断依赖表。

## 字符编码——pgsql 忽略 DB_CHARSET

`pgsql` DSN 不包含 `charset` 参数。对于 `pgsql` 适配器，`DB_CHARSET` / `DatabaseConfig::charset` 会被静默忽略——只有 `mysql` 适配器在 DSN 中使用它。

PostgreSQL 在连接时根据 `server_encoding` 确定客户端编码。使用默认 locale 创建的数据库使用 **UTF-8**，这通常正是所需的。

要强制指定编码，使用带 `options` 参数的 `DATABASE_URL`：

```
DATABASE_URL=pgsql://myapp:secret@db:5432/myapp?options=--client_encoding%3DUTF8
```

或在连接建立后执行 `SET` 命令（无法直接通过 `PdoDatabaseQueryExecutor` 实现——需要自定义连接工厂）。
