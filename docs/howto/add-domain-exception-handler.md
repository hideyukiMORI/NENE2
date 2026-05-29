---
title: "How to add a domain exception handler"
category: getting-started
tags: [error-handling, exceptions, middleware, domain]
difficulty: intermediate
related: [add-database-endpoint, add-health-check]
---

# How to add a domain exception handler

When a route handler throws a domain exception (e.g. `OrderNotFoundException`, `InsufficientStockException`),
NENE2's `ErrorHandlerMiddleware` delegates to the first registered `DomainExceptionHandlerInterface`
that declares it can handle that exception type. This keeps route handlers free of
try/catch blocks and keeps error serialisation in one place.

## 1. Define the domain exception

```php
// src/Order/OrderNotFoundException.php
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Order #{$id} not found.");
    }
}
```

## 2. Implement DomainExceptionHandlerInterface

```php
// src/Order/OrderNotFoundExceptionHandler.php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class OrderNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $probs,
    ) {}

    public function supports(Throwable $exception): bool
    {
        return $exception instanceof OrderNotFoundException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->probs->create(
            request: $request,        // ← required: used to populate 'instance' in the response
            type: 'not-found',        // ← slug only — the factory prepends the base URL
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### Common mistakes

**Missing `$request`** — `ProblemDetailsResponseFactory::create()` requires the PSR-7 request
as its first argument. Omitting it causes a runtime `ArgumentCountError`.

**Full URL in `type`** — `type` takes a slug (e.g. `'not-found'`), not the full URI.
The factory prepends `https://nene2.dev/problems/` (or the configured base URL) automatically.
Passing the full URL produces a doubled path like
`https://nene2.dev/problems/https://nene2.dev/problems/not-found`.

**Correct signature:**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // slug
    title: 'Not Found',  // human-readable title
    status: 404,         // HTTP status code
    detail: '...',       // optional detail string
);
```

## 3. Register the handler in RuntimeApplicationFactory

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // handlers are tried in order — first match wins
    ],
))->create();
```

## 4. Throw from the route handler

```php
$router->get('/orders/{id}', static function (ServerRequestInterface $request) use ($orders): ResponseInterface {
    /** @var array<string, string> $params */
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    $order = $orders->findById($id) ?? throw new OrderNotFoundException($id);

    return $json->create(['id' => $order->id]);
});
```

`ErrorHandlerMiddleware` catches the exception, walks the `$domainExceptionHandlers` list, calls
`supports()` on each, and delegates to the first match. If no handler matches, the exception
is treated as an unexpected server error (500).

## Response shape

A 404 response produced by the example above:

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "detail": "Order #42 not found.",
    "instance": "/orders/42"
}
```

`instance` is populated automatically from `$request->getUri()->getPath()`.

## Troubleshooting: getting 500 instead of your expected error code

If a domain exception produces a **500 Internal Server Error** instead of the expected
4xx response, the most common cause is a missing or mis-registered handler:

1. **Handler not added to `domainExceptionHandlers`** — double-check that the handler
   class is included in the array passed to `RuntimeApplicationFactory`.
2. **`supports()` method mismatch** — ensure `supports()` checks for the exact exception
   class that is actually thrown. If the thrown exception is a subclass and `supports()`
   uses `instanceof ExactClass`, child-class exceptions will still match. But if the
   class hierarchy is inverted (handler checks a parent, exception is a different branch),
   no handler will match.
3. **Handler registered but wrong order** — handlers are tried in order. If a catch-all
   handler appears first and its `supports()` is too broad, it may swallow exceptions
   that a later handler should handle.

A quick diagnostic: temporarily add `error_log(get_class($exception))` before the
`supports()` check to print the actual exception class name.
