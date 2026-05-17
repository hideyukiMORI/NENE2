# 本番環境へデプロイする

このガイドでは NENE2 ベースのアプリケーションを本番環境で動かすための最低限の手順を説明します。本番用イメージのビルド、環境変数の安全な管理、リバースプロキシの配置が主な内容です。

**前提条件**: `docker compose up -d app` でローカル動作を確認済みであること。

---

## 1. 本番用 Docker イメージをビルドする

開発用イメージはソースディレクトリをマウントし開発ツールを含んでいます。本番ではランタイム依存のみを含む自己完結型イメージをビルドします。

`docker/php/Dockerfile.prod` を作成します。

```dockerfile
# Stage 1 — 依存関係のインストール
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# Stage 2 — ランタイムイメージ
FROM php:8.4-apache AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html/public_html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/nene2.conf \
    && a2enconf nene2 \
    && sed -ri -e 's!/var/www/html!/var/www/html/public_html!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public_html!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY public_html/ public_html/
COPY src/         src/
COPY config/      config/
COPY templates/   templates/
COPY --from=vendor /app/vendor vendor/
COPY composer.json composer.lock ./

EXPOSE 80
```

ビルドとプッシュ:

```bash
docker build -f docker/php/Dockerfile.prod -t my-app:latest .
docker push my-registry/my-app:latest
```

---

## 2. 環境変数を安全に管理する

本番イメージに `.env` ファイルを含めないでください。プラットフォームのシークレット管理を通じて環境変数を注入します。

### 必須変数

| 変数 | 本番での値 |
|------|-----------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_ADAPTER` | `mysql` |
| `DB_HOST` | マネージドデータベースのホスト名 |
| `DB_PORT` | `3306` |
| `DB_NAME` | データベース名 |
| `DB_USER` | データベースユーザー名 |
| `DB_PASSWORD` | シークレットストアから — 直接書かない |
| `NENE2_MACHINE_API_KEY` | シークレットストアから — 直接書かない |

### Docker Compose + シークレットファイルの例

```yaml
# compose.prod.yaml
services:
  app:
    image: my-registry/my-app:latest
    env_file:
      - .env.production   # コミットしない — CI/CD またはシークレットマネージャーから注入
    ports:
      - "127.0.0.1:8080:80"   # ループバックのみ — Nginx が前段
    restart: unless-stopped
```

```bash
# .env.production（サーバー上、git には含めない）
APP_ENV=production
APP_DEBUG=false
DB_HOST=db.internal
DB_NAME=myapp
DB_USER=myapp
DB_PASSWORD=<シークレットマネージャーから>
NENE2_MACHINE_API_KEY=<シークレットマネージャーから>
```

---

## 3. リバースプロキシを前段に配置する

NENE2 の Apache コンテナは直接インターネットに向けることを想定していません。Nginx、Caddy、またはクラウドロードバランサーを前段に配置します。

### Nginx の例

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    ssl_certificate     /etc/ssl/certs/api.example.com.crt;
    ssl_certificate_key /etc/ssl/private/api.example.com.key;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 30s;
    }
}

server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}
```

### Caddy の例

```caddyfile
api.example.com {
    reverse_proxy localhost:8080
}
```

Caddy は Let's Encrypt 経由で TLS 証明書を自動発行します。

---

## 4. 本番セキュリティチェックリスト

トラフィックを受け入れる前に確認します。

- [ ] `APP_ENV=production` — 開発用エラー詳細を無効化
- [ ] `APP_DEBUG=false` — HTTP レスポンスのスタックトレースを抑制
- [ ] データベース認証情報はシークレットストアから取得（イメージに含めない）
- [ ] `NENE2_MACHINE_API_KEY` は強力なランダム値（32 文字以上）
- [ ] コンテナポートはループバック（`127.0.0.1:8080`）または内部ネットワークにバインド（`0.0.0.0` ではない）
- [ ] TLS はリバースプロキシで終端
- [ ] `X-Forwarded-For` / `X-Real-IP` ヘッダーはプロキシのみが設定
- [ ] Apache の `ServerTokens` と `ServerSignature` を `Off` に設定（`Dockerfile.prod` に追加）

---

## 5. デプロイ後の確認

```bash
# ヘルスチェック
curl -fsS https://api.example.com/health

# マシンクライアントスモークチェック
curl -fsS -H 'X-NENE2-API-Key: <key>' https://api.example.com/machine/health
```

期待されるレスポンス:

```json
{ "status": "ok", "service": "NENE2" }
```

---

## 6. Problem Details の type URI について

NENE2 のエラーレスポンスは次のような `type` URI を含みます。

```
https://nene2.dev/problems/validation-failed
```

`nene2.dev` はプレースホルダードメインです。本番稼働前に以下のいずれかを選択してください。

**オプション A — nene2.dev を登録する**: フレームワークをメンテナンスする場合、ドメインを登録して問題ドキュメントをホストします。

**オプション B — 独自ドメインを使用する**: `ProblemDetailsResponseFactory` のベース URL を置き換えます。

```php
// src/Error/ProblemDetailsResponseFactory.php
private const string TYPE_BASE = 'https://problems.example.com/';
```

`type` URI は安定させてください。リリース後に変更すると、`type` 値でスイッチしているクライアントが壊れます。

---

## 関連ドキュメント

- [チュートリアル: 最初の API を動かす](../tutorial/first-api.md)
- [HOWTO: DB 付きエンドポイントを追加する](add-database-endpoint.md)
- [開発ガイド: セットアップ](../development/setup.md)
- [解説: なぜ Problem Details？](../explanation/why-problem-details.md)
