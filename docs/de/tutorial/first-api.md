# Ihre erste API in 10 Minuten

Dieses Tutorial führt Sie von null bis zu einer laufenden JSON API mit NENE2.

Am Ende werden Sie haben:
- eine lokale API, die auf HTTP-Anfragen antwortet
- einen `/hello`-Endpoint, der JSON zurückgibt
- ein Verständnis dafür, wie Anfragen durch das Framework fließen

**Für wen**: Entwickler, die JavaScript oder Python kennen, aber PHP noch nicht verwendet haben. Wenn Sie Express oder FastAPI genutzt haben, lassen sich die Konzepte direkt übertragen.

**Zeit**: etwa 10 Minuten.

---

## Was Sie benötigen

| Werkzeug | Warum | Überprüfung |
|---|---|---|
| PHP 8.4 | führt die Anwendung aus | `php --version` |
| Composer | PHP-Paketmanager (wie npm) | `composer --version` |
| Ein Terminal | alle Befehle werden hier ausgeführt | — |

> **Docker-Alternative**: Wenn Sie PHP lieber nicht lokal installieren möchten, funktioniert Docker ebenfalls.
> Siehe [Docker-basiertes Setup](#docker-basiertes-setup) am Ende dieser Seite.

---

## Schritt 1 — Projektverzeichnis erstellen

```bash
mkdir my-api && cd my-api
```

Dies entspricht `mkdir my-app && cd my-app` in einem Node.js-Projekt.

---

## Schritt 2 — NENE2 installieren

```bash
composer init --name="yourname/my-api" --no-interaction
composer require hideyukimori/nene2:^0.4
```

`composer require` ist das PHP-Äquivalent von `npm install`. Es lädt NENE2 und seine Abhängigkeiten in `vendor/` herunter.

Danach sieht Ihr Verzeichnis so aus:

```
my-api/
  vendor/        ← installierte Pakete (wie node_modules/)
  composer.json  ← Paket-Metadaten (wie package.json)
  composer.lock  ← fixierte Versionen (wie package-lock.json)
```

---

## Schritt 3 — Eine `.env`-Datei erstellen

```bash
cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
APP_NAME="My API"
DB_ADAPTER=sqlite
EOF
```

`.env` funktioniert genauso wie in Node.js. Das Framework liest sie beim Start automatisch.

---

## Schritt 4 — Den Front Controller erstellen

Erstellen Sie `public/index.php`:

```php
<?php
declare(strict_types=1);

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/hello', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['message' => 'Hello, world!', 'status' => 'ok']);
            });
        },
    ],
))->create();

$request  = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
$response = $app->handle($request);

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
http_response_code($response->getStatusCode());
echo $response->getBody();
```

**Was das bewirkt** (Zeile für Zeile):

- `require .../vendor/autoload.php` — lädt alle installierten Pakete, wie `import` in JS
- `$psr17 = new Psr17Factory()` — erstellt HTTP-Objekt-Factories (denken Sie: Request/Response-Builder)
- `RuntimeApplicationFactory` — verdrahtet die komplette Middleware-Pipeline
- `routeRegistrars` — hier fügen Sie Ihre eigenen Routen hinzu (mehr dazu in den HOWTO-Docs)
- `$router->get('/hello', ...)` — registriert eine GET-Route, wie `app.get('/hello', ...)` in Express
- `$json->create([...])` — erstellt eine JSON-Antwort aus einem PHP-Array

---

## Schritt 5 — Den Server starten

```bash
php -S localhost:8080 -t public
```

Dies ist PHPs eingebauter Entwicklungsserver. Er entspricht `npm run dev` — nicht für Produktion, aber gut für lokale Entwicklung.

Sie sollten sehen:

```
PHP 8.4.x Development Server (http://localhost:8080) started
```

---

## Schritt 6 — Die API aufrufen

Öffnen Sie ein neues Terminal und führen Sie aus:

```bash
curl http://localhost:8080/hello
```

Sie sollten sehen:

```json
{
    "message": "Hello, world!",
    "status": "ok"
}
```

Probieren Sie auch den eingebauten Health-Endpoint:

```bash
curl http://localhost:8080/health
```

```json
{
    "status": "ok",
    "service": "My API"
}
```

Das ist Ihre erste laufende API. Schauen wir, was sonst noch enthalten ist.

---

## Schritt 7 — Fehlerbehandlung in Aktion

NENE2 gibt [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457) für alle Fehler zurück. Rufen Sie eine Route auf, die nicht existiert:

```bash
curl http://localhost:8080/missing
```

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "instance": "/missing"
}
```

Jede Fehlerantwort hat einen `type`-URI, einen `title` und einen HTTP-`status`. Dies ist das Standardformat, das in allen NENE2-Fehlerantworten verwendet wird.

---

## Was gerade passiert ist

Hier ist der Request-Flow für `GET /hello`:

```
HTTP-Anfrage
  → RequestIdMiddleware      fügt X-Request-Id-Header hinzu
  → SecurityHeadersMiddleware fügt X-Content-Type-Options usw. hinzu
  → CorsMiddleware           behandelt CORS-Preflight
  → ErrorHandlerMiddleware   fängt unbehandelte Ausnahmen
  → RequestSizeLimitMiddleware weist zu große Payloads ab
  → Router                   matched /hello → Ihr Handler
  → Ihr Handler              gibt {"message": "Hello, world!"} zurück
HTTP-Antwort
```

All das passiert automatisch. Ihr Handler muss nur eine Antwort zurückgeben — das Framework kümmert sich um Header, Fehlerformatierung und Request-Korrelation.

---

## Nächste Schritte

- **Einen Pfadparameter hinzufügen** (wie `/hello/{name}`): siehe [Eine Route hinzufügen](../howto/add-custom-route.md)
- **Eine Datenbank verbinden**: siehe [Datenbankendpunkt hinzufügen](../howto/add-database-endpoint.md)
- **Die vollständige API-Dokumentation sehen**: starten Sie den Server und öffnen Sie `http://localhost:8080/openapi.php`

---

## Docker-basiertes Setup

Wenn Sie Docker einer lokalen PHP-Installation vorziehen:

```bash
mkdir my-api && cd my-api
```

Erstellen Sie eine minimale `compose.yaml`:

```yaml
services:
  app:
    image: php:8.4-apache
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
    working_dir: /var/www/html
```

Installieren Sie dann Composer innerhalb des Containers:

```bash
docker compose run --rm app bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar require hideyukimori/nene2:^0.4"
```

Folgen Sie den Schritten 3–4 oben, um `.env` und `public/index.php` zu erstellen, dann:

```bash
docker compose up -d
curl http://localhost:8080/hello
```

Für ein vollständigeres Docker-Setup mit MySQL-Unterstützung, siehe den [NENE2-Repository-Setup-Guide](../development/setup.md).
