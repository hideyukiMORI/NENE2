# HTTP Runtime and Routing Policy

NENE2 uses a PSR-first HTTP runtime design.

## Position

HTTP is the framework backbone. NENE2 should use widely understood PHP standards instead of inventing incompatible request, response, and middleware abstractions.

The standard direction is:

- PSR-7 for HTTP messages.
- PSR-15 for middleware and request handlers.
- PSR-17 for request, response, and stream factories.
- Small NENE2 helpers and adapters around PSR interfaces.
- Explicit routing and dispatch rules that remain easy for humans and AI agents to trace.

This Issue defines the policy and directories only. Runtime packages and implementations should be added in a later Issue.

## Standard Directories

```text
src/
├── Http/          # PSR HTTP helpers, response factories, and HTTP concerns
├── Routing/       # route definitions, match results, and dispatch policy
├── Middleware/    # PSR-15 middleware pipeline and middleware contracts
└── Error/         # exception to response mapping and error handling policy
```

## PSR Boundaries

### PSR-7

NENE2 should accept and return PSR-7 messages at the HTTP boundary.

Controllers and handlers should not depend on superglobals directly. The front controller can translate server state into a PSR-7 server request through a PSR-17 compatible factory.

### PSR-15

Middleware should follow PSR-15:

- middleware receives a server request
- middleware delegates to a request handler
- middleware returns a response

This keeps authentication, logging, error handling, CORS, and OpenAPI validation composable.

Middleware and security baseline policy is defined in `docs/development/middleware-security.md`.

Request validation uses a layered policy: middleware handles HTTP-wide concerns, controllers or handlers map to DTOs, and use cases protect business invariants. See `docs/development/request-validation.md`.

Logging and observability should use PSR-3, request id propagation, and adapter-driven integrations. See `docs/development/observability.md`.

### PSR-17

Response and stream creation should go through factories rather than direct implementation classes. This keeps the concrete PSR-7 implementation replaceable.

## Routing Policy

Routing should be explicit and small:

- method and path matching are the first required features
- route handlers should be callables, invokable classes, or request handlers
- path parameters use `{name}` full path segments and are exposed as request attributes
- route groups and middleware attachment can be added incrementally
- attribute routing is not a default requirement

NENE2 may adopt a small router implementation later, but the framework should keep the route table readable.

## Front Controller Policy

`public_html/index.php` is the front controller.

The first runtime implementation should move the current smoke response behind the HTTP runtime:

1. require Composer autoload
2. create a PSR-7 server request
3. pass it through error handling and middleware
4. dispatch it through routing
5. emit the PSR-7 response

The current direct smoke endpoint can remain until the first runtime skeleton is implemented.

## Response Policy

JSON APIs are the primary response surface. HTML responses are supported for thin pages, Swagger UI, SPA shells, and small operational screens.

OpenAPI should describe public JSON responses. HTML responses should stay minimal and documented when they become stable behavior.

## Error Handling Policy

Error handling should be centralized in `src/Error/`.

The first implementation should distinguish:

- domain/application exceptions that map to stable public responses
- not found and method not allowed routing failures
- validation failures
- unexpected exceptions that become generic server errors

The public JSON error shape will be defined in the Problem Details Issue.

NENE2 uses RFC 9457 Problem Details as the standard public JSON API error shape. See `docs/development/api-error-responses.md`.

## Non-Goals for the First Runtime

- Full controller resolver magic.
- Attribute routing as a required default.
- Autowiring or container-driven dispatch before the DI policy is settled.
- OpenAPI runtime validation before the validation policy is settled.
- Replacing all frontend or static file behavior.

## Future Implementation Issues

After this policy, follow-up work should decide:

- concrete PSR-7 / PSR-17 implementation
- router package or minimal internal router
- middleware dispatcher implementation
- response emitter
- front controller migration
- HTTP contract tests
