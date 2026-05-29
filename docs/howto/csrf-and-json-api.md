---
title: "CSRF and JSON APIs"
category: security
tags: [csrf, cors, json-api, security, bearer-token]
difficulty: intermediate
related: [enforce-resource-ownership, mass-assignment-defence]
---

# CSRF and JSON APIs

## CORS ≠ CSRF protection

This is one of the most common security misconceptions in web API development.

**CORS** (Cross-Origin Resource Sharing) controls whether a browser will let JavaScript on one origin *read the response* from another origin. The server adds `Access-Control-Allow-Origin` headers; the browser enforces the policy.

**CSRF** (Cross-Site Request Forgery) is an attack where a malicious page tricks the victim's browser into sending a state-changing request to a trusted site — using the victim's session cookies.

NENE2's `CorsMiddleware` handles CORS. It does **not** block requests from unknown origins. A request with `Origin: https://evil.example.com` passes through and reaches your handler unchanged — this is expected behavior. CORS is a browser safeguard that limits what *JavaScript can read*, not what the *server accepts*.

```
# These all reach your handler — CorsMiddleware does NOT block them
curl -X POST https://api.example.com/orders \
  -H "Origin: https://evil.example.com" \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'

curl -X POST https://api.example.com/orders \
  -H "Content-Type: application/json" \
  -d '{"item":"Widget","quantity":1}'
# (no Origin header — e.g., server-to-server calls)
```

## Why JSON APIs are more resistant to CSRF than form-based APIs

Classic CSRF exploits HTML forms (`<form method="POST">`). Browsers send form submissions with `Content-Type: application/x-www-form-urlencoded` or `multipart/form-data` — the browser will include session cookies automatically.

A request with `Content-Type: application/json` is **not a "simple request"** under the CORS spec. The browser sends a preflight `OPTIONS` first. If your CORS configuration doesn't list the attacker's origin, the browser blocks the preflight — the actual request never arrives.

However, **this only protects browser-based attacks**. A server or a `fetch()` call with explicit headers can send `Content-Type: application/json` to your API without restriction. CORS preflights are enforced by browsers, not servers.

## The real protection: Bearer JWT

NENE2's standard authentication uses Bearer JWTs in the `Authorization` header:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

CSRF attacks work by abusing cookies — the browser attaches them automatically to cross-site requests. The `Authorization` header is **never** sent automatically. A malicious page cannot include a victim's JWT because JavaScript on `https://evil.example.com` cannot read the token from `https://app.example.com`.

If you use Bearer JWT authentication and never put tokens in cookies, you are not vulnerable to CSRF by design. No additional CSRF token or `SameSite` attribute is needed.

## If you use cookie-based sessions

If your application uses `Set-Cookie` for session management (instead of Bearer JWT), you need explicit CSRF protection:

### Option 1: SameSite cookies (simplest)

```php
Set-Cookie: session=...; SameSite=Strict; Secure; HttpOnly
```

`SameSite=Strict` prevents the browser from including the cookie on cross-site requests. `SameSite=Lax` is also a reasonable default that still blocks cross-site `POST`.

### Option 2: Origin header validation middleware

Reject requests whose `Origin` doesn't match your allowlist:

```php
final class OriginEnforcementMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Non-browser calls (curl, server-to-server) have no Origin — allow them
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!in_array($origin, $this->allowedOrigins, strict: true)) {
            // Return a 403 Problem Details response
            // ...
        }

        return $handler->handle($request);
    }
}
```

Register it after CORS in the middleware stack (see CLAUDE.md section 5 for ordering).

### Option 3: CSRF token

Generate a per-session token, store it server-side, include it in forms as a hidden field, and verify it on every state-changing request. This is the traditional approach but adds complexity.

## Summary

| Scenario | CSRF risk | Recommended mitigation |
|---|---|---|
| Bearer JWT in `Authorization` header | None — header not sent automatically | No action needed |
| Cookie session, SameSite=Strict | Very low | Keep `SameSite=Strict` |
| Cookie session, no SameSite | High | Add `SameSite` or Origin enforcement |
| API key in custom header | None — custom headers not sent automatically | No action needed |

The simplest path: use NENE2's built-in Bearer JWT authentication and avoid cookie-based sessions for API endpoints.
