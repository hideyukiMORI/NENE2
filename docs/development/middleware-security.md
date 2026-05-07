# Middleware and Security Baseline Policy

NENE2 uses PSR-15 middleware as the standard HTTP cross-cutting boundary.

## Position

Middleware should make HTTP behavior composable without hiding application flow.

The standard direction is:

- Use PSR-15 for middleware and request handlers.
- Keep middleware order explicit.
- Include API-oriented security baseline policies early.
- Keep authentication, authorization, rate limiting, and CSRF as clear extension points.
- Keep local development easy while requiring explicit production configuration.

This Issue defines the policy only. Middleware implementations should be added in later Issues.

## Baseline Scope

The initial API baseline should cover:

- error handling
- request id
- CORS policy
- security headers
- request size policy

Authentication, authorization, rate limiting, and CSRF are important, but should be implemented through focused follow-up Issues.

## Middleware Order

The default middleware order should be explicit and documented.

Recommended first pipeline:

```text
1. Error handling
2. Request id
3. Security headers
4. CORS
5. Request size limit
6. Authentication / authorization
7. OpenAPI or request validation
8. Routing / handler dispatch
```

Error handling should wrap the whole pipeline so every failure can be converted to a stable Problem Details response.

Route-specific request validation should stay in controllers, handlers, DTO mappers, or use cases rather than global middleware. See `docs/development/request-validation.md`.

## Request ID

Every request should have a request id.

Policy:

- Accept a safe incoming request id header when configured.
- Generate one when missing.
- Add it to response headers.
- Include it in logs once logging is implemented.

Request id logging and observability policy is defined in `docs/development/observability.md`.

Recommended header:

```text
X-Request-Id
```

## Security Headers

Security headers should be added by middleware so HTML, API, and docs responses get consistent protection.

Initial candidates:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer-when-downgrade`
- `X-Frame-Options: SAMEORIGIN`
- `Permissions-Policy` with conservative defaults

Content Security Policy should be introduced carefully because Swagger UI and future frontend assets may require explicit script/style policies.

## CORS

Local development may be permissive. Production must use an explicit allowlist.

Policy:

- local: allow common localhost origins when configured
- test: use deterministic configured origins
- production: reject cross-origin requests unless origin is allowlisted
- credentials must be disabled unless explicitly configured

CORS should be config-driven, not hard-coded in middleware.

## Request Size

Request size limits should be configurable and enforced before body parsing when practical.

The first policy should define:

- default max body size
- behavior for missing or invalid `Content-Length`
- Problem Details response for payload too large

The exact defaults belong to the config implementation Issue.

## Authentication and Authorization

Authentication and authorization are extension points, not mandatory core behavior.

API-key and token boundary policy is defined in `docs/development/authentication-boundary.md`.

Planned schemes:

- API key for machine clients and MCP tools
- bearer token for user or service authentication
- session-based auth for applications that need server sessions

The first implemented API-key path uses `X-NENE2-API-Key` and protects `/machine/health` when `NENE2_MACHINE_API_KEY` is configured.

OpenAPI security schemes should be added when a scheme is implemented.

## CSRF

CSRF protection should not be globally forced for JSON APIs.

Policy:

- state-changing browser form routes may require CSRF
- token or API-key based JSON APIs usually do not use CSRF
- session-based browser routes should document CSRF expectations

## Rate Limiting

Rate limiting should be an adapter-driven middleware, not a hard dependency of framework core.

Future implementation should decide:

- in-memory local adapter
- Redis or external store adapter
- rate limit response shape using Problem Details
- headers for remaining quota and reset time

## OpenAPI Relationship

OpenAPI should document:

- security schemes
- 401 / 403 / 413 / 429 Problem Details responses
- CORS behavior when it becomes part of the public API contract

Do not document aspirational security behavior before middleware implements it.

## Non-Goals for the First Middleware Step

- Built-in user system.
- Mandatory session handling.
- Global CSRF for all JSON APIs.
- Production rate limiter before an adapter decision.
- Hard-coded production CORS origins.
