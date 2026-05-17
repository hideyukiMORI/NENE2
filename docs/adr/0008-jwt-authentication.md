# ADR 0008: JWT Authentication Direction

## Status

accepted

## Context

NENE2 already ships an API-key middleware path (`ApiKeyAuthenticationMiddleware`) for machine clients.
Applications that serve human users — or service-to-service flows that need short-lived, scoped tokens — need a Bearer token path.

The options evaluated:

| Option | Coupling | Flexibility | Notes |
|--------|----------|-------------|-------|
| Hard-code `firebase/php-jwt` into the middleware | High | Low | Locks the framework to one library |
| Expose a `TokenVerifierInterface`, ship no concrete implementation | None | High | Applications plug in any JWT library or identity provider |
| Build a full OAuth 2.0 server | Very high | N/A | Far beyond framework scope |

**Recommended concrete library** (for applications that adopt JWT): `firebase/php-jwt` — actively maintained, minimal API surface, supports RS256 / ES256 / HS256.

## Decision

1. Define `Nene2\Auth\TokenVerifierInterface` with a single `verify(string $token): array<string, mixed>` method that either returns claims or throws `TokenVerificationException`.
2. Implement `Nene2\Auth\BearerTokenMiddleware` as a PSR-15 middleware that:
   - Reads the `Authorization: Bearer <token>` header.
   - Delegates verification to `TokenVerifierInterface`.
   - On success: forwards the request with `nene2.auth.claims` and `nene2.auth.credential_type = bearer` request attributes set.
   - On failure: returns a 401 Problem Details response with a `WWW-Authenticate: Bearer` challenge header (RFC 6750).
3. Ship **no concrete JWT verifier** in the framework core. Applications wire their preferred library through the interface.
4. Add a `firebase/php-jwt` integration note to `authentication-boundary.md` as the recommended starting point.

### Middleware placement (pipeline position 6)

```
1. Error handling
2. Request id
3. Security headers
4. CORS
5. Request size limit
6. Authentication / authorization  ← BearerTokenMiddleware or ApiKeyAuthenticationMiddleware
7. OpenAPI or request validation
8. Routing / handler dispatch
```

### Request attributes set on success

| Attribute | Type | Value |
|-----------|------|-------|
| `nene2.auth.credential_type` | `string` | `"bearer"` |
| `nene2.auth.claims` | `array<string, mixed>` | Verified JWT payload |

## Consequences

**Benefits**
- The framework core stays library-agnostic; the interface is the only coupling.
- Applications can use any JWT library, JWKS endpoint adapter, or opaque token verifier.
- The middleware is testable in isolation: pass a stub `TokenVerifierInterface`.

**Costs / follow-up work**
- Applications must wire a concrete `TokenVerifierInterface` implementation before using the middleware.
- OpenAPI security scheme (`bearerAuth`) should be added when the middleware is wired to real routes.
- Scope enforcement is not in scope for this ADR; it belongs in a future `AuthorizationMiddleware` or handler-level guard.

## Related

- Issue: `#282`
- PR: `#283`
- Supersedes: none
- Superseded by: none
- See also: `docs/development/authentication-boundary.md`
