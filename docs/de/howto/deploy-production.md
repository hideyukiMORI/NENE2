# In Produktion deployen

Diese Anleitung beschreibt die Mindestschritte für den Betrieb einer NENE2-basierten Anwendung in der Produktion: Erstellen eines Produktions-Images, sichere Verwaltung von Umgebungsvariablen und Platzieren eines Reverse-Proxys.

**Voraussetzung**: Ihre Anwendung funktioniert lokal mit `docker compose up -d app`.

---

## 1. Produktions-Docker-Image erstellen

Das Entwicklungs-Image mountet das Quellverzeichnis und enthält Entwicklungswerkzeuge. Für die Produktion erstellen Sie ein eigenständiges Image mit nur den Runtime-Abhängigkeiten.

Erstellen Sie `docker/php/Dockerfile.prod`:

```dockerfile
# Stage 1 — Abhängigkeiten installieren
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# Stage 2 — Runtime-Image
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

Erstellen und pushen:

```bash
docker build -f docker/php/Dockerfile.prod -t my-app:latest .
docker push my-registry/my-app:latest
```

---

## 2. Umgebungsvariablen sicher verwalten

Fügen Sie niemals eine `.env`-Datei in ein Produktions-Image ein. Injizieren Sie Umgebungsvariablen über das Secret-Management Ihrer Plattform.

### Erforderliche Variablen

| Variable | Produktionswert |
|----------|----------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_HOST` | Ihr verwalteter Datenbankhost |
| `DB_PASSWORD` | Aus dem Secret Store — niemals hartcodieren |
| `NENE2_MACHINE_API_KEY` | Aus dem Secret Store — niemals hartcodieren |

---

## 3. Reverse-Proxy vorschalten

Der NENE2-Apache-Container ist nicht dafür ausgelegt, direkt dem Internet ausgesetzt zu sein. Schalten Sie Nginx, Caddy oder einen Cloud-Load-Balancer vor.

### Nginx-Beispiel

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

### Caddy-Beispiel

```caddyfile
api.example.com {
    reverse_proxy localhost:8080
}
```

---

## 4. JWT-Authentifizierung in Produktion

> **Warnung**: `LocalBearerTokenVerifier` (die eingebaute HMAC-HS256-Implementierung) ist
> **ausschließlich für lokale Entwicklung und Tests** gedacht. Sie verwendet keine externe
> Bibliothek und besitzt nicht die für die produktive JWT-Validierung erforderliche
> Sicherheitshärtung (Schlüsselrotation, asymmetrische RS256-Schlüssel, Widerruf, Toleranz
> gegenüber Uhrenabweichungen über die einfachen `exp`/`nbf`-Prüfungen hinaus usw.).
>
> Bevor Sie eine Anwendung mit Bearer-JWT-Authentifizierung deployen:
>
> 1. Implementieren Sie `TokenVerifierInterface` und `TokenIssuerInterface` mit einer
>    produktionstauglichen Bibliothek wie [`firebase/php-jwt`](https://github.com/firebase/php-jwt)
>    oder [`lcobuzi/jwt`](https://github.com/lcobuzi/jwt).
> 2. Verwenden Sie asymmetrische Schlüssel (RS256 oder ES256), damit die API Token verifizieren
>    kann, ohne den Signaturschlüssel zu besitzen.
> 3. Halten Sie `NENE2_LOCAL_JWT_SECRET` aus Ihrer Produktionsumgebung heraus — er gehört nur in
>    `.env`-Dateien für die Entwicklung.
>
> Die vollständige Begründung finden Sie in [ADR 0008](../adr/0008-jwt-authentication.md).

---

## 5. Produktions-Sicherheits-Checkliste

- [ ] `APP_ENV=production` — deaktiviert Entwicklungs-Fehlerdetails
- [ ] `APP_DEBUG=false` — unterdrückt Stack-Traces in HTTP-Antworten
- [ ] Datenbank-Zugangsdaten aus einem Secret Store
- [ ] `NENE2_MACHINE_API_KEY` ist ein starker Zufallswert (≥ 32 Zeichen)
- [ ] JWT: `LocalBearerTokenVerifier` durch eine Produktionsbibliothek ersetzt (siehe §4 oben)
- [ ] `NENE2_LOCAL_JWT_SECRET` ist in Produktion **nicht** gesetzt
- [ ] Container-Port nur an Loopback-Adresse gebunden
- [ ] TLS am Reverse-Proxy terminiert

---

## 6. Nach dem Deployment verifizieren

```bash
curl -fsS https://api.example.com/health
curl -fsS -H 'X-NENE2-API-Key: <key>' https://api.example.com/machine/health
```

---

## 7. Problem Details Typ-URIs

NENE2 verwendet `https://nene2.dev/problems/...` als offizielle Framework-Domain. Die eingebauten Fehlertyp-Seiten werden dort automatisch aufgelöst — für den Standardfehlerumfang ist keine Konfiguration erforderlich.

Wenn Ihr Projekt **eigene Problemtypen** definiert (z.B. `payment-failed`) und diese URIs unter Ihrer eigenen Domain auflösen sollen, setzen Sie `PROBLEM_DETAILS_BASE_URL` in `.env`:

```ini
PROBLEM_DETAILS_BASE_URL=https://problems.example.com/
```
