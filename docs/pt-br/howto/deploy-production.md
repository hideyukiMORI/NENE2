# Implantar em produção

Este guia cobre as etapas mínimas para executar uma aplicação baseada em NENE2 em produção: construção de uma imagem de produção, gerenciamento seguro de variáveis de ambiente e colocação de um proxy reverso na frente.

**Pré-requisito**: Sua aplicação está funcionando localmente com `docker compose up -d app`.

---

## 1. Construir uma imagem Docker de produção

A imagem de desenvolvimento monta o diretório de origem e inclui ferramentas de desenvolvimento. Para produção, construa uma imagem autossuficiente com apenas as dependências de runtime.

Crie `docker/php/Dockerfile.prod`:

```dockerfile
# Etapa 1 — instalar dependências
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# Etapa 2 — imagem de runtime
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

Construir e enviar:

```bash
docker build -f docker/php/Dockerfile.prod -t my-app:latest .
docker push my-registry/my-app:latest
```

---

## 2. Gerenciar variáveis de ambiente com segurança

Nunca inclua um arquivo `.env` em uma imagem de produção. Injete variáveis de ambiente através do gerenciamento de segredos da sua plataforma.

### Variáveis obrigatórias

| Variável | Valor de produção |
|----------|------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_HOST` | Seu host de banco de dados gerenciado |
| `DB_PASSWORD` | Do gerenciador de segredos — nunca codifique diretamente |
| `NENE2_MACHINE_API_KEY` | Do gerenciador de segredos — nunca codifique diretamente |

---

## 3. Colocar um proxy reverso na frente

O contêiner Apache do NENE2 não é projetado para enfrentar a internet diretamente. Coloque Nginx, Caddy ou um balanceador de carga em nuvem na frente.

### Exemplo Nginx

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

### Exemplo Caddy

```caddyfile
api.example.com {
    reverse_proxy localhost:8080
}
```

---

## 4. Lista de verificação de segurança de produção

- [ ] `APP_ENV=production` — desabilita detalhes de erro de desenvolvimento
- [ ] `APP_DEBUG=false` — suprime rastreamentos de pilha nas respostas HTTP
- [ ] Credenciais do banco de dados vêm de um gerenciador de segredos
- [ ] `NENE2_MACHINE_API_KEY` é um valor aleatório forte (≥ 32 caracteres)
- [ ] Porta do contêiner vinculada apenas ao endereço de loopback
- [ ] TLS terminado no proxy reverso

---

## 5. Verificar após implantação

```bash
curl -fsS https://api.example.com/health
curl -fsS -H 'X-NENE2-API-Key: <key>' https://api.example.com/machine/health
```

---

## 6. URIs de tipo Problem Details

NENE2 usa `https://nene2.dev/problems/...` como domínio de espaço reservado. Antes de ir para produção, registre esse domínio ou substitua a URL base em `ProblemDetailsResponseFactory`.
