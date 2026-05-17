# Your First API in 10 Minutes

This tutorial walks you from zero to a running JSON API using NENE2.

By the end you will have:
- a local API that responds to HTTP requests
- a `/hello` endpoint that returns JSON
- an understanding of how requests flow through the framework

**Who this is for**: Developers who know JavaScript or Python but have not used PHP before. If you have used Express or FastAPI, the concepts here map directly.

**Time**: about 10 minutes.

---

## What you need

| Tool | Why | Check |
|---|---|---|
| PHP 8.4 | runs the application | `php --version` |
| Composer | PHP package manager (like npm) | `composer --version` |
| A terminal | all commands run here | — |

> **Docker alternative**: if you would rather not install PHP locally, Docker works too.
> See [Docker-based setup](#docker-based-setup) at the bottom of this page.

---

## Step 1 — Create a project directory

```bash
mkdir my-api && cd my-api
```

Think of this as `mkdir my-app && cd my-app` in a Node.js project.

---

## Step 2 — Install NENE2

```bash
composer init --name="yourname/my-api" --no-interaction
composer require hideyukimori/nene2:^0.4
```

`composer require` is the PHP equivalent of `npm install`. It downloads NENE2 and its dependencies into `vendor/`.

After this your directory looks like:

```
my-api/
  vendor/        ← installed packages (like node_modules/)
  composer.json  ← package metadata (like package.json)
  composer.lock  ← locked versions (like package-lock.json)
```

---

## Step 3 — Create a `.env` file

```bash
cat > .env << 'EOF'
APP_ENV=local
APP_DEBUG=true
APP_NAME="My API"
DB_ADAPTER=sqlite
EOF
```

`.env` works the same as in Node.js. The framework reads it automatically at startup.

---

## Step 4 — Create the front controller

Create `public/index.php`:

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

**What this does** (line by line):

- `require .../vendor/autoload.php` — loads all installed packages, same as `import` in JS
- `$psr17 = new Psr17Factory()` — creates HTTP object factories (think: request/response builders)
- `RuntimeApplicationFactory` — wires the full middleware pipeline
- `routeRegistrars` — where you add your own routes (more on this in the HOWTO docs)
- `$router->get('/hello', ...)` — registers a GET route, like `app.get('/hello', ...)` in Express
- `$json->create([...])` — builds a JSON response from a PHP array

---

## Step 5 — Start the server

```bash
php -S localhost:8080 -t public
```

This is PHP's built-in development server. It is the equivalent of `npm run dev` — not for production, but fine for local development.

You should see:

```
PHP 8.4.x Development Server (http://localhost:8080) started
```

---

## Step 6 — Call the API

Open a new terminal and run:

```bash
curl http://localhost:8080/hello
```

You should see:

```json
{
    "message": "Hello, world!",
    "status": "ok"
}
```

Try the built-in health endpoint too:

```bash
curl http://localhost:8080/health
```

```json
{
    "status": "ok",
    "service": "My API"
}
```

That is your first API running. Let us look at what else is included.

---

## Step 7 — See error handling in action

NENE2 returns [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457) for all errors. Call a route that does not exist:

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

Every error response has a `type` URI, a `title`, and an HTTP `status`. This is the standard format used across all NENE2 error responses.

---

## What just happened

Here is the request flow for `GET /hello`:

```
HTTP request
  → RequestIdMiddleware      adds X-Request-Id header
  → SecurityHeadersMiddleware adds X-Content-Type-Options etc.
  → CorsMiddleware           handles CORS preflight
  → ErrorHandlerMiddleware   catches any unhandled exceptions
  → RequestSizeLimitMiddleware rejects oversized payloads
  → Router                   matches /hello → your handler
  → your handler             returns {"message": "Hello, world!"}
HTTP response
```

All of this happens automatically. Your handler only needs to return a response — the framework handles headers, error formatting, and request correlation.

---

## Next steps

- **Add a path parameter** (like `/hello/{name}`): see [Add a custom route](../howto/add-custom-route.md)
- **Connect a database**: see [Add a database-backed endpoint](../howto/add-database-endpoint.md)
- **See the full API documentation**: start the server and open `http://localhost:8080/openapi.php`

---

## Docker-based setup

If you prefer Docker over a local PHP installation:

```bash
mkdir my-api && cd my-api
```

Create a minimal `compose.yaml`:

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

Then install Composer inside the container:

```bash
docker compose run --rm app bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar require hideyukimori/nene2:^0.4"
```

Follow Steps 3–4 above to create `.env` and `public/index.php`, then:

```bash
docker compose up -d
curl http://localhost:8080/hello
```

For a more complete Docker setup with MySQL support, see the [NENE2 repository setup guide](../development/setup.md).
