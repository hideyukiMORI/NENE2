# Machine-Client Smoke Workflow

This workflow verifies the first protected machine-client API path in local Docker development.

It uses a local-only placeholder key. Do not commit real API keys or local `.env` files.

## Target

The protected smoke endpoint is:

```text
GET /machine/health
```

It requires:

```text
X-NENE2-API-Key
```

The expected success response includes:

```json
{
  "status": "ok",
  "service": "NENE2",
  "credential_type": "api_key"
}
```

## Start With a Local Key

Start or recreate the local app service with a disposable key:

```bash
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
```

This passes the key through `compose.yaml` into the app container for local testing only.

## Verify Missing Credentials

Call the protected path without a key:

```bash
curl -i http://localhost:8080/machine/health
```

Expected result:

- HTTP status: `401 Unauthorized`
- `Content-Type`: `application/problem+json; charset=utf-8`
- Problem Details `type`: `https://nene2.dev/problems/unauthorized`
- no secret value in the response

## Verify Valid Credentials

Call the same path with the configured key:

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

Expected result:

- HTTP status: `200 OK`
- `Content-Type`: `application/json; charset=utf-8`
- JSON body includes `"credential_type": "api_key"`
- response includes `X-Request-Id`

## Cleanup

Unset the shell variable when the smoke check is done:

```bash
unset NENE2_MACHINE_API_KEY
```

If you want to return the running app service to public-only local development, recreate it without the key:

```bash
docker compose up -d app
```

## Safety Notes

- Use placeholder values such as `local-dev-key` only for local smoke checks.
- Do not add real credentials to `.env.example`, committed docs, screenshots, logs, or MCP metadata.
- Do not reuse the local smoke key in production.
- Production machine-client authentication needs explicit owner, scope, rotation, storage, and audit policy before use.

## Related Work

- Authentication boundary: `docs/development/authentication-boundary.md`
- Middleware security: `docs/development/middleware-security.md`
- Client project start guide: `docs/development/client-project-start.md`
- OpenAPI contract: `docs/openapi/openapi.yaml`
- Runtime tests: `tests/HttpRuntimeTest.php`
- GitHub Issue: `#154`
