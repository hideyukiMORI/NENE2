# Deploy to Production

This guide covers the minimum steps to run a NENE2-based application in a production environment: building a production image, managing environment variables securely, and placing a reverse proxy in front.

**Prerequisite**: Your application is working locally with `docker compose up -d app`.

---

## 1. Build a production Docker image

The development image mounts the source directory and includes dev tools. For production, build a self-contained image with only the runtime dependencies.

Create `docker/php/Dockerfile.prod`:

```dockerfile
# Stage 1 — install dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# Stage 2 — runtime image
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

# Copy application source
COPY public_html/ public_html/
COPY src/         src/
COPY config/      config/
COPY templates/   templates/

# Copy production vendor from stage 1
COPY --from=vendor /app/vendor vendor/

# Copy composer files (needed for autoloader)
COPY composer.json composer.lock ./

EXPOSE 80
```

Build and push:

```bash
docker build -f docker/php/Dockerfile.prod -t my-app:latest .
docker push my-registry/my-app:latest
```

---

## 2. Manage environment variables securely

Never ship a `.env` file in a production image. Inject environment variables through your platform's secret management.

### Required variables

| Variable | Production value |
|----------|-----------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_ADAPTER` | `mysql` |
| `DB_HOST` | Your managed database hostname |
| `DB_PORT` | `3306` |
| `DB_NAME` | Your database name |
| `DB_USER` | Your database username |
| `DB_PASSWORD` | From secret store — never hardcode |
| `NENE2_MACHINE_API_KEY` | From secret store — never hardcode |

### Example: Docker Compose with secrets file

```yaml
# compose.prod.yaml
services:
  app:
    image: my-registry/my-app:latest
    env_file:
      - .env.production     # not committed — injected by CI/CD or secret manager
    ports:
      - "127.0.0.1:8080:80"   # bind to loopback only — Nginx in front
    restart: unless-stopped
```

```bash
# .env.production (on the server, not in git)
APP_ENV=production
APP_DEBUG=false
DB_HOST=db.internal
DB_NAME=myapp
DB_USER=myapp
DB_PASSWORD=<from secret manager>
NENE2_MACHINE_API_KEY=<from secret manager>
```

### Platform-specific injection

| Platform | Method |
|----------|--------|
| Docker Swarm | `docker secret create` + `secrets:` in compose |
| Kubernetes | `Secret` resource + `envFrom` |
| AWS ECS | Task definition secrets from Parameter Store / Secrets Manager |
| Railway / Render / Fly.io | Environment variable settings in dashboard |

---

## 3. Place a reverse proxy in front

The NENE2 Apache container is not designed to face the internet directly. Put Nginx, Caddy, or a cloud load balancer in front.

### Nginx example

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

### Caddy example

```caddyfile
api.example.com {
    reverse_proxy localhost:8080
}
```

Caddy automatically provisions TLS certificates via Let's Encrypt.

---

## 4. Production security checklist

Before accepting traffic, verify:

- [ ] `APP_ENV=production` — disables development error details
- [ ] `APP_DEBUG=false` — suppresses stack traces in HTTP responses
- [ ] Database credentials come from a secret store, not from `.env` in the image
- [ ] `NENE2_MACHINE_API_KEY` is a strong random value (≥ 32 characters)
- [ ] The container port is bound to loopback (`127.0.0.1:8080`) or a private network, not `0.0.0.0`
- [ ] TLS is terminated at the reverse proxy
- [ ] `X-Forwarded-For` / `X-Real-IP` headers are set by the proxy only
- [ ] Apache `ServerTokens` and `ServerSignature` are set to `Off` (add to `Dockerfile.prod`)

---

## 5. Verify after deployment

```bash
# Health check
curl -fsS https://api.example.com/health

# Machine client smoke check
curl -fsS -H 'X-NENE2-API-Key: <key>' https://api.example.com/machine/health
```

Expected responses:

```json
{ "status": "ok", "service": "NENE2" }
```

---

## 6. Problem Details type URIs

NENE2 error responses include a `type` URI such as:

```
https://nene2.dev/problems/validation-failed
```

`nene2.dev` is a placeholder domain. Before going to production, choose one of:

**Option A — register nene2.dev**: If you maintain the framework, register the domain and host problem documentation there.

**Option B — use your own domain**: Replace the base URL in `ProblemDetailsResponseFactory`:

```php
// src/Error/ProblemDetailsResponseFactory.php
private const string TYPE_BASE = 'https://problems.example.com/';
```

The `type` URI should be stable. Changing it after launch breaks clients that switch on the `type` value.

---

## Further reading

- [Tutorial: Your first API](../tutorial/first-api.md)
- [HOWTO: Add a database-backed endpoint](add-database-endpoint.md)
- [Development: Setup guide](../development/setup.md)
- [Explanation: Why Problem Details?](../explanation/why-problem-details.md)
