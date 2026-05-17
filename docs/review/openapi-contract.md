# OpenAPI Contract Self-Review

Use this checklist for OpenAPI documents, examples, contract validation, and API contract tooling.

Source policies:

- `docs/integrations/openapi.md`
- `docs/development/api-error-responses.md`
- `docs/development/request-validation.md`
- `docs/development/middleware-security.md`
- `docs/development/language-policy.md`

## Checklist

- [ ] Shipped public JSON behavior is documented in `docs/openapi/openapi.yaml`.
- [ ] OpenAPI text, operation summaries, examples, and schema descriptions are in English.
- [ ] Stable error responses use shared Problem Details schemas where applicable.
- [ ] Problem Details examples use `https://nene2.dev/problems/{problem-name}`.
- [ ] Documented examples match implemented response shapes.
- [ ] New `$ref` values resolve through `composer openapi`.
- [ ] Aspirational auth, CORS, or security behavior is not documented before implementation.
- [ ] The narrowest useful verification was run, usually `composer openapi` or `composer check`.

## Security Schemes

- [ ] Each `securityScheme` entry (`ApiKeyAuth`, `bearerAuth`) matches a wired middleware implementation — no scheme is declared without matching behavior.
- [ ] Protected paths declare `security:` at the operation level with the correct scheme.
- [ ] The `401` response (`$ref: '#/components/responses/Unauthorized'`) is listed for every operation that requires authentication.
- [ ] Example values for API keys or tokens are clearly placeholder values, not real secrets.
- [ ] `bearerAuth` operations that are new follow the Bearer token checklist in `docs/review/middleware-security.md`.
