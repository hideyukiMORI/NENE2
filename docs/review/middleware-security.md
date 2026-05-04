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
