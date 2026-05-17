# Déployer en production

Ce guide couvre les étapes minimales pour exécuter une application basée sur NENE2 en production : construction d'une image de production, gestion sécurisée des variables d'environnement, et placement d'un proxy inverse.

**Prérequis**: Votre application fonctionne localement avec `docker compose up -d app`.

---

## 1. Construire une image Docker de production

L'image de développement monte le répertoire source et inclut les outils de développement. Pour la production, construisez une image autonome avec uniquement les dépendances d'exécution.

Créez `docker/php/Dockerfile.prod` :

```dockerfile
# Étape 1 — installation des dépendances
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# Étape 2 — image d'exécution
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

Construire et pousser :

```bash
docker build -f docker/php/Dockerfile.prod -t my-app:latest .
docker push my-registry/my-app:latest
```

---

## 2. Gérer les variables d'environnement de façon sécurisée

Ne jamais inclure un fichier `.env` dans une image de production. Injectez les variables d'environnement via la gestion des secrets de votre plateforme.

### Variables requises

| Variable | Valeur de production |
|----------|---------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_HOST` | Votre hôte de base de données géré |
| `DB_PASSWORD` | Depuis le gestionnaire de secrets |
| `NENE2_MACHINE_API_KEY` | Depuis le gestionnaire de secrets |

---

## 3. Placer un proxy inverse en amont

Le conteneur Apache de NENE2 n'est pas conçu pour faire face directement à Internet. Placez Nginx, Caddy ou un équilibreur de charge cloud en amont.

### Exemple Nginx

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

### Exemple Caddy

```caddyfile
api.example.com {
    reverse_proxy localhost:8080
}
```

---

## 4. Liste de contrôle de sécurité production

- [ ] `APP_ENV=production` — désactive les détails d'erreur de développement
- [ ] `APP_DEBUG=false` — supprime les traces de pile dans les réponses HTTP
- [ ] Identifiants de base de données depuis un gestionnaire de secrets
- [ ] `NENE2_MACHINE_API_KEY` valeur aléatoire forte (≥ 32 caractères)
- [ ] Port du conteneur lié à l'adresse de bouclage uniquement
- [ ] TLS terminé au niveau du proxy inverse

---

## 5. Vérifier après déploiement

```bash
curl -fsS https://api.example.com/health
curl -fsS -H 'X-NENE2-API-Key: <key>' https://api.example.com/machine/health
```

---

## 6. URIs de type Problem Details

NENE2 utilise `https://nene2.dev/problems/...` comme domaine de substitution. Avant la mise en production, enregistrez ce domaine ou remplacez l'URL de base dans `ProblemDetailsResponseFactory`.
