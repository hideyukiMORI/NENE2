---
title: "Recover Bearer Auth Behind Proxies That Strip Authorization"
category: auth
tags: [authorization, bearer, middleware, shared-hosting, proxy, security]
difficulty: intermediate
related: [use-bearer-auth, bearer-token-middleware, deploy-production]
---

# Recover Bearer Auth Behind Proxies That Strip `Authorization`

Some shared-hosting front proxies remove the standard `Authorization` header before the
request ever reaches PHP (observed in production on HETEML-class hosting). Custom headers
pass through, `Authorization` does not — so the usual recovery tricks fail too:

- `.htaccess` `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` — useless,
  Apache never sees the header;
- `CGIPassAuth on` — same reason.

The result is that every Bearer-protected endpoint answers 401 `missing_token` even though
the browser sent a perfectly valid token.

NENE2 ships a standard two-part fix (see ADR 0019):

1. **Frontend**: `@hideyukimori/nene2-client` (≥ 1.1.0) mirrors the token into
   `X-Authorization: Bearer <token>` on every request, alongside the standard header.
2. **Backend**: `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` adopts the mirror
   **only when `Authorization` is absent or empty**. Hosts that deliver the standard
   header are byte-for-byte unaffected.

---

## Enable it in the default pipeline

One opt-in flag on `RuntimeApplicationFactory`:

```php
$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [/* ... */],
    authMiddleware:  $bearerMiddleware,
    enableAuthorizationHeaderFallback: true, // off by default
))->create();
```

When enabled, the fallback runs at the start of the auth stage — before the machine
API-key check and before any injected auth middleware — so every credential-reading
middleware sees the restored header. It is method- and path-independent.

## Or wire it manually

In a hand-assembled pipeline, place it anywhere before your auth middleware:

```php
$stack = [
    // ... request id, logging, security headers, CORS, error handling ...
    new AuthorizationHeaderFallbackMiddleware(),
    $bearerMiddleware,
];
```

Outside a PSR-15 pipeline, the transform is available as a static helper:

```php
$request = AuthorizationHeaderFallbackMiddleware::apply($request);
```

---

## When you must NOT enable it

Enabling the fallback makes `X-Authorization` credential-equivalent to `Authorization`.
That is exactly right on hosts that strip the header *accidentally* — and exactly wrong
where an upstream strips it *deliberately*:

- a gateway that performs authentication itself and forwards a trusted identity;
- a WAF that filters inbound credentials from untrusted clients.

In those setups the mirror would be a client-controlled bypass. Either keep the flag off,
or make the upstream strip `X-Authorization` too.

Also treat `X-Authorization` with the same confidentiality as `Authorization` in access
logs and intermediate proxies.

## Notes

- The header name is **fixed** (`AuthorizationHeaderFallbackMiddleware::FALLBACK_HEADER`,
  `X-Authorization`). It is a fleet-wide wiring contract with the frontend client, not a
  tuning knob.
- The mirror value is adopted verbatim (`Bearer <token>` included). Token validation stays
  entirely in your auth middleware — a garbage mirror fails exactly like a garbage
  standard header.
- Precedence is always: non-empty `Authorization` wins; the mirror is only consulted when
  the standard header is absent or empty.
