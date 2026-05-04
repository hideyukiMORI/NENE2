# Backend API Self-Review

Use this checklist for API endpoints, controllers, handlers, request validation, responses, and HTTP-facing behavior.

Source policies:

- `docs/development/http-runtime.md`
- `docs/development/request-validation.md`
- `docs/development/api-error-responses.md`
- `docs/integrations/openapi.md`
- `docs/development/coding-standards.md`

## Checklist

- [ ] Public request or response behavior is reflected in OpenAPI when it is stable behavior.
- [ ] Controllers or handlers map request data to readonly DTOs or command objects before calling use cases.
- [ ] Use cases do not receive raw PSR-7 requests, superglobals, or unstructured request arrays.
- [ ] Request validation failures use Problem Details `validation-failed` with structured `errors`.
- [ ] Public error responses do not expose stack traces, SQL, file paths, secrets, or private identifiers.
- [ ] HTTP behavior remains compatible with PSR-7 / PSR-15 / PSR-17 boundaries.
- [ ] The narrowest useful PHP verification was run, usually `composer check`.
- [ ] PR notes mention this checklist when the change is API-facing.
