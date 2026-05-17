# Middleware and Security Self-Review

Use this checklist for middleware, request id, CORS, security headers, auth boundaries, logging context, and request-size behavior.

Source policies:

- `docs/development/middleware-security.md`
- `docs/development/observability.md`
- `docs/development/request-validation.md`
- `docs/development/api-error-responses.md`

## Checklist

- [ ] Middleware order remains explicit and easy to trace.
- [ ] Error handling still wraps failures that should become Problem Details responses.
- [ ] Request id behavior preserves safe incoming values, generates missing values, and returns `X-Request-Id`.
- [ ] CORS behavior is config-driven and production does not allow wildcard origins by accident.
- [ ] Security headers do not break documented local docs or frontend behavior without explanation.
- [ ] Route-specific business validation is not placed in global middleware.
- [ ] Secrets, tokens, cookies, authorization headers, and raw request bodies are not logged by default.
- [ ] Auth, rate limit, metrics, or error tracking integrations remain adapter-driven unless an ADR says otherwise.
- [ ] The narrowest useful PHP verification was run, usually `composer check`.

## API-Key Authentication

- [ ] `ApiKeyAuthenticationMiddleware` uses `hash_equals` for constant-time comparison.
- [ ] `protectedPaths` lists only paths that must be machine-client–gated.
- [ ] `WWW-Authenticate: ApiKey` challenge header is present on 401 responses.
- [ ] The API key value is never logged, committed, or returned in a response.

## Bearer Token Authentication (ADR 0008)

- [ ] `BearerTokenMiddleware` receives a `TokenVerifierInterface` — no concrete JWT library is imported in the middleware itself.
- [ ] `protectedPaths` is set explicitly when only some routes require Bearer auth; empty means all routes.
- [ ] On missing or invalid token, the response is a 401 Problem Details with `WWW-Authenticate: Bearer` (RFC 6750).
- [ ] Bearer tokens are not logged, even partially.
- [ ] `nene2.auth.claims` and `nene2.auth.credential_type` request attributes are set on success and consumed only where needed.
- [ ] `LocalBearerTokenVerifier` is used only in local/test environments; production wires a library-backed verifier.
- [ ] `NENE2_LOCAL_JWT_SECRET` is set outside the repository and not committed.
- [ ] If the protected endpoint is new, its OpenAPI path declares `security: [{bearerAuth: []}]`.
