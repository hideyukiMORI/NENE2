# 部署到生产环境

本指南涵盖在生产环境中运行基于 NENE2 的应用程序的最低步骤：构建生产镜像、安全管理环境变量以及在前端放置反向代理。

**前提条件**：您的应用程序可通过 `docker compose up -d app` 在本地正常运行。

---

## 1. 构建生产 Docker 镜像

开发镜像挂载源目录并包含开发工具。对于生产环境，请构建仅包含运行时依赖项的独立镜像。

创建 `docker/php/Dockerfile.prod`：

```dockerfile
# 阶段 1 — 安装依赖
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# 阶段 2 — 运行时镜像
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

构建并推送：

```bash
docker build -f docker/php/Dockerfile.prod -t my-app:latest .
docker push my-registry/my-app:latest
```

---

## 2. 安全管理环境变量

切勿在生产镜像中包含 `.env` 文件。通过平台的密钥管理注入环境变量。

### 必需变量

| 变量 | 生产值 |
|------|--------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_HOST` | 托管数据库主机名 |
| `DB_PASSWORD` | 来自密钥存储 — 切勿硬编码 |
| `NENE2_MACHINE_API_KEY` | 来自密钥存储 — 切勿硬编码 |

---

## 3. 在前端放置反向代理

NENE2 的 Apache 容器不适合直接面对互联网。请在前端放置 Nginx、Caddy 或云负载均衡器。

### Nginx 示例

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

### Caddy 示例

```caddyfile
api.example.com {
    reverse_proxy localhost:8080
}
```

---

## 4. 生产安全检查清单

- [ ] `APP_ENV=production` — 禁用开发错误详情
- [ ] `APP_DEBUG=false` — 抑制 HTTP 响应中的堆栈跟踪
- [ ] 数据库凭据来自密钥存储
- [ ] `NENE2_MACHINE_API_KEY` 为强随机值（≥ 32 字符）
- [ ] 容器端口仅绑定到回环地址
- [ ] TLS 在反向代理处终止

---

## 5. 部署后验证

```bash
curl -fsS https://api.example.com/health
curl -fsS -H 'X-NENE2-API-Key: <key>' https://api.example.com/machine/health
```

---

## 6. Problem Details 类型 URI

NENE2 使用 `https://nene2.dev/problems/...` 作为占位域名。在投产前，请注册该域名或在 `ProblemDetailsResponseFactory` 中替换基础 URL。
