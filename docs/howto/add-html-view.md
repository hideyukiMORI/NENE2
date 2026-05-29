---
title: "Add HTML Views"
category: getting-started
tags: [html, views, templates, server-rendering]
difficulty: beginner
related: [add-health-check, add-database-endpoint]
---

# Add HTML Views

This guide shows how to add server-rendered HTML responses to a NENE2 application
using `NativePhpViewRenderer` and `HtmlResponseFactory`.

**Prerequisite**: You have a working NENE2 application with at least one route. If not,
start with [Add a custom route](./add-custom-route.md).

---

## Overview

NENE2 ships a minimal, zero-dependency HTML rendering layer:

| Class | Role |
|---|---|
| `NativePhpViewRenderer` | Renders `.php` templates in an isolated scope |
| `HtmlEscaper` | Escapes values for safe HTML output (`htmlspecialchars` / UTF-8 / full quotes) |
| `HtmlResponseFactory` | Wraps rendered HTML in a `text/html; charset=utf-8` PSR-7 response |
| `TemplateNotFoundException` | Thrown when a template file is missing or the path is invalid |

HTML responses coexist with JSON endpoints — add them to the same application without
removing any existing routes.

---

## 1. Create your templates

NENE2 expects native PHP template files under a single root directory. The conventional
location is `templates/` at the project root.

```
my-app/
├── templates/
│   ├── layout.php       (optional shared layout)
│   ├── home.php
│   └── notes/
│       ├── index.php
│       └── show.php
```

Each template receives:

- `$e` — the escaping helper (`HtmlEscaper::escape()`). Always use it for user-supplied data.
- All keys from the `$data` array passed to `render()` as individual variables.

**`templates/home.php`**

```php
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title><?= $e($title) ?></title></head>
<body>
  <h1><?= $e($title) ?></h1>
  <p><?= $e($description) ?></p>
</body>
</html>
```

**`templates/notes/index.php`**

```php
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Notes</title></head>
<body>
  <h1>Notes</h1>
  <ul>
    <?php foreach ($notes as $note): ?>
      <li><a href="/notes/<?= $e($note['id']) ?>"><?= $e($note['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</body>
</html>
```

> **Security**: always call `$e(...)` around any value that originated from user input,
> a database, or an external system. Omitting it introduces XSS vulnerabilities.

---

## 2. Wire the renderer in your ServiceProvider

Register `NativePhpViewRenderer` and `HtmlResponseFactory` in your application's
`ServiceProviderInterface`:

```php
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class AppServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                NativePhpViewRenderer::class,
                static function (ContainerInterface $container): NativePhpViewRenderer {
                    // Resolve the template root relative to your project root.
                    return new NativePhpViewRenderer(dirname(__DIR__) . '/templates');
                },
            )
            ->set(
                HtmlResponseFactory::class,
                static function (ContainerInterface $container): HtmlResponseFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory   = $container->get(StreamFactoryInterface::class);
                    $renderer        = $container->get(NativePhpViewRenderer::class);

                    return new HtmlResponseFactory($responseFactory, $streamFactory, $renderer);
                },
            );
    }
}
```

---

## 3. Use HtmlResponseFactory in a handler

Inject `HtmlResponseFactory` into your handler and call `create()`:

```php
use Nene2\View\HtmlResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HomeHandler
{
    public function __construct(private HtmlResponseFactory $html) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('home.php', [
            'title'       => 'Welcome',
            'description' => 'A NENE2-powered application.',
        ]);
    }
}
```

`create()` signature:

```php
public function create(
    string $template,           // path relative to templateRoot
    array  $data    = [],       // variables exposed to the template
    int    $status  = 200,      // HTTP status code
    array  $headers = [],       // additional response headers
): ResponseInterface
```

---

## 4. Register the route

```php
// In your route registrar callable passed to RuntimeApplicationFactory:
$router->get('/', new HomeHandler($container->get(HtmlResponseFactory::class)));
$router->get('/notes', new NoteListHandler($container->get(HtmlResponseFactory::class)));
```

---

## 5. Handle TemplateNotFoundException

`NativePhpViewRenderer::render()` throws `TemplateNotFoundException` when:

- The template file does not exist.
- The template path is empty.
- The template path contains `..` (directory traversal is blocked).

Register a domain exception handler to return a meaningful HTTP response:

```php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\View\TemplateNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TemplateNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(private ProblemDetailsResponseFactory $problemDetails) {}

    public function handles(\Throwable $exception): bool
    {
        return $exception instanceof TemplateNotFoundException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create($request, 'not-found', 'Not Found', 404);
    }
}
```

Pass it to `RuntimeApplicationFactory`:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [new TemplateNotFoundExceptionHandler($problemDetails)],
    routeRegistrars: [$registerRoutes],
))->create();
```

---

## 6. Mix HTML and JSON endpoints

JSON and HTML endpoints coexist on the same `Router`. There is no special configuration:

```php
$registerRoutes = static function (Router $router) use ($container): void {
    // JSON API
    $router->get('/api/notes',     $container->get(NoteListJsonHandler::class));
    $router->post('/api/notes',    $container->get(NoteCreateHandler::class));

    // HTML views
    $router->get('/',              $container->get(HomeHandler::class));
    $router->get('/notes',         $container->get(NoteListHtmlHandler::class));
    $router->get('/notes/{id}',    $container->get(NoteShowHtmlHandler::class));
};
```

---

## 7. Testing HTML handlers

Test the full response without mocking the renderer — just supply a real template directory:

```php
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

public function testHomeReturnsHtml(): void
{
    $templateRoot = sys_get_temp_dir() . '/test-templates-' . bin2hex(random_bytes(4));
    mkdir($templateRoot);
    file_put_contents($templateRoot . '/home.php', '<h1><?= $e($title) ?></h1>');

    $psr17   = new Psr17Factory();
    $factory = new HtmlResponseFactory($psr17, $psr17, new NativePhpViewRenderer($templateRoot));
    $handler = new HomeHandler($factory);

    $response = $handler(new ServerRequest('GET', '/'));

    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    self::assertStringContainsString('<h1>Welcome</h1>', (string) $response->getBody());

    unlink($templateRoot . '/home.php');
    rmdir($templateRoot);
}
```

---

## Design notes

- Templates run in a closure scope — they cannot access `$this` or class internals.
  Variables are injected via `extract()` with `EXTR_SKIP` (existing variables are not
  overwritten).
- `HtmlEscaper` uses `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5` — both `"` and `'` are
  escaped, and invalid UTF-8 sequences are silently substituted rather than discarded.
- Directory traversal (`../`) is blocked at the path-resolution step; no file-system access
  happens before the check.

See [ADR 0009](../adr/0009-v1.0-public-api-scope.md) for the stability guarantee covering
`Nene2\View\*`.

---

## Next steps

- [Add a custom route](./add-custom-route.md) — wire handlers into the router
- [Add rate limiting](./add-rate-limiting.md) — protect HTML endpoints from abuse
- [Add JWT authentication](./add-jwt-authentication.md) — restrict HTML pages to authenticated users
