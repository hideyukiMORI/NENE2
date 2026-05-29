---
title: "How to pass request-scoped state between middleware and handlers"
category: getting-started
tags: [middleware, request-context, dependency-injection, holder-pattern]
difficulty: intermediate
related: [bearer-token-middleware, add-jwt-authentication, add-custom-route]
---

# How to pass request-scoped state between middleware and handlers

Some middlewares extract a value from the incoming request — a tenant ID, a decoded JWT claim, a
trace context — and route handlers need that value downstream. This guide shows the recommended
pattern using `RequestScopedHolder`.

## The holder pattern

`RequestScopedHolder<T>` is a small mutable container. Inject **one shared instance** into both
the middleware that writes it and the handler (or repository) that reads it:

```php
use Nene2\Http\RequestScopedHolder;

// Shared instance — wired once at the composition root.
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// Middleware writes it.
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// Route handler reads it.
$routeRegistrar = new TaskRouteRegistrar($repository, $teamId, $json);
```

Inside the middleware:

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $raw = $request->getHeaderLine('X-Team-Id');
    // ... validate ...
    $this->teamId->set((int) $raw);   // write
    return $handler->handle($request);
}
```

Inside the route handler:

```php
$id = $this->teamId->get();  // read — throws LogicException if middleware did not run
```

## Why not PSR-7 request attributes?

PSR-7 request attributes are immutable — each `withAttribute()` call returns a new instance. To
pass an attribute from middleware to a handler you must thread the new request object through the
entire call chain, which NENE2's dispatcher already does. Using `withAttribute()` is fine when the
downstream code receives the `$request` directly.

`RequestScopedHolder` is the right tool when the downstream consumer does **not** receive the
`$request` — for example, a repository that only knows about your domain types and cannot accept a
PSR-7 request as a dependency.

## Stacking multiple middlewares

Pass a list to `RuntimeApplicationFactory::$authMiddleware` to run several middlewares in sequence:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // runs first
        new BearerTokenMiddleware($probs, $verifier), // runs second
    ],
))->create();
```

Both middlewares share the same pipeline position (after the request size limit, before rate
limiting). The first item in the list processes the request before the second.

## Safety: PHP shared-nothing model

In PHP-FPM and CLI, every request runs in a fresh process. The `RequestScopedHolder` value set
during request A is never visible to request B because each process exits after handling one
request. The holder is safe to use as-is under this model.

### Async runtimes (Swoole, ReactPHP, FrankenPHP worker mode)

When multiple requests share the same PHP process, a holder written during request A will retain
its value into request B unless explicitly cleared. Call `reset()` at the start (or end) of each
request cycle:

```php
// Example Swoole request handler
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // clear previous request's value
    $psrRequest = /* convert */;
    $psrResponse = $app->handle($psrRequest);
    // emit $psrResponse ...
});
```

NENE2 currently targets PHP-FPM / CLI and does not ship built-in async support. If you run an
async runtime, you are responsible for resetting shared holders between requests.
