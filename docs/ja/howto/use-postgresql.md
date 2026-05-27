# ハウツー: PostgreSQL の使用方法

NENE2 の `PdoConnectionFactory` は `pgsql` アダプターをすぐに使える形でサポートします。このガイドでは、MySQL や SQLite のデフォルトと異なるセットアップ手順と PostgreSQL 固有のパターンについて説明します。

## Docker Compose のセットアップ

`postgres` サービスを追加し、`app` サービスの環境変数を設定してください:

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
      DB_CHARSET: utf8    # pgsql では無視されます — 後述の文字セットセクションを参照
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

## Dockerfile — pdo_pgsql 拡張

`pdo_pgsql` 拡張は `php:8.4-cli` ベースイメージではデフォルトで有効ではありません。`Dockerfile` に追加してください:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
```

## Phinx マイグレーション設定

`pgsql` を使用する場合、Phinx の環境設定から `charset` を省略してください — PostgreSQL はこのキーを使用しません:

```php
// phinx.php
$database->environment => [
    'adapter' => $database->adapter,   // 'pgsql'
    'host'    => $database->host,
    'name'    => $database->name,
    'user'    => $database->user,
    'pass'    => $database->password,
    'port'    => $database->port,
    // 'charset' => ...  ← pgsql では省略
],
```

Phinx はカラム型を PostgreSQL 型に自動的にマッピングします。よく使うマッピング:

| Phinx 型 | PostgreSQL 型 |
|---|---|
| `integer`（主キー） | `SERIAL` |
| `string` | `VARCHAR(n)` |
| `text` | `TEXT` |
| `datetime` | `TIMESTAMP(0) WITHOUT TIME ZONE` |
| `boolean` | `BOOLEAN` |

## INSERT 後の ID 取得 — RETURNING id を使用する

`DatabaseQueryExecutorInterface::lastInsertId()` は PostgreSQL では `0` を返します。`PDO::lastInsertId()` には NENE2 が追跡しないシーケンス名の引数が必要なためです。

INSERT SQL に `RETURNING id` を付けた `fetchOne()` を使用して、単一のラウンドトリップで生成された主キーを取得してください:

```php
// ✓ PostgreSQL 対応パターン
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`RETURNING id` は標準的な PostgreSQL SQL であり、SQLite ≥ 3.35（2021 年）でもサポートされています。MySQL/MariaDB では**サポートされていません** — 両方をターゲットにする場合はリポジトリ実装を DB 固有に保ってください。

### なぜ lastInsertId() を使わないのか?

PostgreSQL のシーケンスはデフォルトで `{table}_{column}_seq` という名前です（例: `reviews_id_seq`）。`PDO::lastInsertId('reviews_id_seq')` は機能しますが、`DatabaseQueryExecutorInterface` はシーケンス名パラメーターを公開していません。`RETURNING id` の方がすっきりしており、結合を避けられます。

## テスト間のデータリセット

テストの分離には各テストケース間でテーブルをリセットする必要があります。正しい SQL はアダプターによって異なります:

```php
protected function setUp(): void
{
    // PostgreSQL — SERIAL シーケンスを 1 にリセットする
    $this->executor->execute('TRUNCATE reviews RESTART IDENTITY CASCADE');

    // MySQL — TRUNCATE は AUTO_INCREMENT を暗黙的にリセットする
    // $this->executor->execute('TRUNCATE reviews');

    // SQLite — TRUNCATE はサポートされていない; DELETE + シーケンスリセットを使用する
    // $this->executor->execute('DELETE FROM reviews');
    // $this->executor->execute("DELETE FROM sqlite_sequence WHERE name = 'reviews'");
}
```

`TRUNCATE … RESTART IDENTITY CASCADE` は PostgreSQL 固有です。`RESTART IDENTITY` はシーケンスをリセットし、次の挿入が `id = 1` を返すようにして、テストのアサーションを決定論的に保ちます。`CASCADE` は同じ文で依存テーブルを切り捨てます。

## 文字エンコーディング — pgsql では DB_CHARSET が無視される

`pgsql` DSN には `charset` パラメーターが含まれません。`DB_CHARSET` / `DatabaseConfig::charset` は `pgsql` アダプターでは無視されます — `mysql` アダプターのみが DSN でこれを使用します。

PostgreSQL は接続時に `server_encoding` からクライアントエンコーディングを決定します。デフォルトロケールで作成されたデータベースは **UTF-8** を使用し、ほとんどの場合これが期待する設定です。

特定のエンコーディングを強制するには、`DATABASE_URL` に `options` パラメーターを使用してください:

```
DATABASE_URL=pgsql://myapp:secret@db:5432/myapp?options=--client_encoding%3DUTF8
```

または接続確立後に `SET` コマンドを実行します（`PdoDatabaseQueryExecutor` からは直接できません — カスタム接続ファクトリーが必要です）。
