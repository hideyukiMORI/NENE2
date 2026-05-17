# Authentication Boundary Policy

NENE2 treats authentication as an explicit application boundary, not hidden framework magic.

This policy defines the first API-key and token direction for machine clients, MCP tools, and future authentication middleware.

## Position

Authentication and authorization are explicit middleware boundaries.

The default direction is:

- API keys are for machine clients and MCP tools.
- Bearer tokens are for user or service authentication when an application adopts them.
- Session authentication belongs to applications that need server-side browser sessions.
- OpenAPI security schemes should be added only when matching middleware behavior exists.
- Secrets must never be committed, logged, or exposed through MCP metadata.

The first implemented middleware path is an API-key check for machine-client endpoints using:

```text
X-NENE2-API-Key
```

The key value is loaded from `NENE2_MACHINE_API_KEY` when configured. Leave it unset for public-only local development, and set it outside the repository when testing protected routes.

Local smoke workflow for the first protected path is documented in `docs/development/machine-client-smoke.md`.

## API Keys

API keys are long-lived credentials for non-human clients.

Use API keys for:

- local MCP tools that call local HTTP APIs
- service-to-service inspection tools
- machine clients that need stable scoped access

API keys should have:

- an owner
- an environment
- a scope list
- creation time
- last-used time when storage exists
- rotation or revocation path

Do not put raw API keys in OpenAPI examples, MCP tool catalogs, logs, screenshots, or committed configuration.

## Bearer Tokens

Bearer tokens are request credentials sent in the `Authorization` header.

Use bearer tokens for:

- short-lived user tokens
- service tokens with explicit scopes
- future OAuth or first-party token flows

Bearer tokens should be treated as secrets even when they are short-lived.

The framework should not prescribe one token format before an authentication adapter exists. JWT, opaque tokens, or external identity provider tokens can be supported by adapters later.

## JWT Authentication (ADR 0008)

NENE2 provides `BearerTokenMiddleware` as a PSR-15 middleware that reads `Authorization: Bearer <token>` and delegates verification to a `TokenVerifierInterface`.

The framework ships **no concrete JWT verifier**. Applications wire their preferred library through the interface. The recommended starting point is `firebase/php-jwt`:

```bash
composer require firebase/php-jwt
```

### Wiring example

```php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;

$verifier = new class ($secret) implements TokenVerifierInterface {
    public function __construct(private readonly string $secret) {}

    public function verify(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            throw new TokenVerificationException($e->getMessage(), previous: $e);
        }
    }
};

// Pipeline position 6 — after CORS, before routing
$pipeline->pipe(new BearerTokenMiddleware($problemDetails, $verifier));
```

### Request attributes set on success

| Attribute | Value |
|-----------|-------|
| `nene2.auth.credential_type` | `"bearer"` |
| `nene2.auth.claims` | `array<string, mixed>` — decoded JWT payload |

### Failure responses

Missing or invalid tokens return HTTP 401 with `application/problem+json` and a `WWW-Authenticate: Bearer` challenge header (RFC 6750).

See `docs/adr/0008-jwt-authentication.md` for the full decision record.

## Scopes

Scopes describe allowed capabilities.

Initial scope naming should stay small and readable:

- `read:system`
- `read:health`
- `read:docs`
- `write:*` only after write tools are designed
- `admin:*` only after admin policy is documented

MCP tools should declare the minimum scopes they require before production use.

## Local Development

Local development may use placeholder credentials only when they are clearly non-secret and documented as examples.

Local tooling may:

- call public local HTTP endpoints without credentials when the endpoint is intentionally public
- use test-only API keys generated outside the repository
- document required environment variable names without values

Local tooling must not:

- read `.env` values through MCP tools
- print credentials in command output
- depend on a developer's private credential storage
- bypass authentication in a way that resembles production behavior

## Production Expectations

Production authentication requires explicit design before implementation.

Before enabling production credentials, document:

- credential type
- owner and rotation process
- allowed environments
- required scopes
- storage backend
- audit fields
- failure behavior for missing, invalid, expired, or insufficient credentials

Credential validation failures should use Problem Details responses and must not reveal whether a secret value exists.

## Logging and Observability

Logs may include:

- request id
- credential type
- credential owner id when safe
- scope names
- authentication result
- failure reason category

Logs must not include:

- raw API keys
- bearer tokens
- cookies
- authorization headers
- credential hashes when they could aid attacks

## OpenAPI and MCP

OpenAPI security schemes should match implemented middleware.

When added, OpenAPI should describe:

- credential location
- required scopes
- `401` and `403` Problem Details responses
- examples without real secrets

MCP metadata should reference required scopes, not raw credentials.

Write, admin, and destructive MCP tools require authentication, authorization, audit, request id propagation, and confirmation behavior before implementation.
