# Why PSR Standards?

NENE2 is built on PSR-7, PSR-15, and PSR-17 rather than custom HTTP abstractions. This page explains the reasoning.

## What these standards cover

| Standard | What it defines |
|----------|----------------|
| PSR-7 | `RequestInterface`, `ResponseInterface`, `StreamInterface` — the shape of HTTP messages |
| PSR-15 | `MiddlewareInterface`, `RequestHandlerInterface` — how middleware and handlers compose |
| PSR-17 | Factory interfaces for creating PSR-7 objects |

## Why not a custom abstraction?

A custom `Request` class is quick to write and easy to control. The cost surfaces later:

- Every new HTTP library needs a custom adapter.
- Middleware written for one project cannot move to another.
- Testing requires either a running HTTP server or the custom class itself.

PSR-7 objects are immutable value objects. A handler that accepts `ServerRequestInterface` and returns `ResponseInterface` makes no assumption about the framework that calls it.

## Why immutable messages?

PSR-7 messages are immutable: `withHeader()`, `withBody()`, and similar methods return a new instance instead of mutating the existing one. This eliminates a class of bugs where middleware silently modifies a request that a later handler inspects.

```php
// Each middleware gets a clean copy — the original is unchanged
$request = $request->withAttribute('request_id', $id);
```

## Why PSR-15 middleware?

PSR-15 defines the middleware contract as a single method:

```php
public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $next
): ResponseInterface
```

This means:

- Any PSR-15 middleware can slot into any PSR-15 pipeline.
- The pipeline order is explicit code, not a hidden framework lifecycle.
- Unit-testing a middleware only needs a mock `RequestHandlerInterface`, not a running server.

## Concrete package choice

NENE2 uses **Nyholm PSR-7** for message objects and **Relay** for the middleware dispatcher (see ADR 0001). These are lightweight packages that implement the standards without adding framework-specific APIs on top.

## Trade-offs

| Benefit | Cost |
|---------|------|
| Interoperable middleware | More verbose than a fluent custom API |
| Immutable messages reduce bugs | Object creation on every `with*` call |
| Testable without a server | Requires understanding PSR interfaces |

For a small JSON API framework, the interoperability and testability benefits outweigh the verbosity cost.
