# ADR 0019: Authorization header fallback middleware (`X-Authorization` mirror)

## Status

accepted

## Context

Some shared-hosting front proxies strip the standard `Authorization` header before
the request reaches PHP. This was observed in production on HETEML (Tier A fleet
concern): custom headers pass through, `Authorization` does not, so neither the
usual `.htaccess` rewrite trick (`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`)
nor `CGIPassAuth on` can recover it — the header is gone before Apache sees it.
On such hosts every Bearer-authenticated endpoint fails with 401 `missing_token`.

The fleet already solved both halves of this per product:

- **Frontend**: `@hideyukimori/nene2-client` (v1.1.0, nene2-js #102) mirrors the
  token into `X-Authorization: Bearer <token>` on **every** request, alongside the
  standard header.
- **Backend**: nene-clear implemented `AuthorizationHeaderFallback` (nene-clear #265)
  — adopt the mirror only when `Authorization` is absent — and proved it in
  production; nene-vault's `public_html/.htaccess` (nene-vault #118) documents the
  same assumption for hosts where the rewrite trick *does* work.

Leaving the backend half as per-product hand-written code repeats the failure mode
that motivated ADR 0013 (guarded JWT secret resolution): security-adjacent logic
duplicated across ~12 products, where a bug fixed in one copy is not fixed in the
others. NENE2 is the correct home for the canonical receiver, distributed via
`composer update`.

### The trade-off: enable by default or opt in?

Functionally the fallback is backward compatible: the standard header always wins,
and the mirror is adopted only when `Authorization` is absent or empty. An
always-on default would therefore not change behavior for any request that carries
`Authorization`.

It would, however, change the **trust boundary**. Deployments exist where an
upstream gateway strips `Authorization` *deliberately* — delegated authentication
at the proxy, or a WAF filtering inbound credentials. Auto-enabling the fallback
would silently hand those deployments a client-controlled bypass header. NENE2's
conventions consistently resolve this kind of ambiguity toward explicitness:
"prefer explicit, typed, testable code over hidden framework behavior" (AGENTS.md),
HSTS opt-in (#1447), the opt-in database preflight endpoint (#1419), the opt-in
demo module (ADR 0017), and the strict opt-in parse of `DEMO_MODE` / 
`NENE2_ALLOW_DEV_SECRET` (ADR 0013).

## Decision

### 1. `Nene2\Middleware\AuthorizationHeaderFallbackMiddleware` is public, stable API

A `final readonly` PSR-15 middleware. When `Authorization` is absent or empty
(`getHeaderLine() === ''`) and `X-Authorization` is non-empty, the mirror value is
copied into `Authorization`. Otherwise the request passes through untouched.
Method- and path-independent: the client sends the mirror on every request, and
downstream auth middleware decides which paths require a token.

The class is covered by the ADR 0009 stability guarantee (the `Nene2\Middleware`
row: "All shipped middleware classes"). A static `apply(ServerRequestInterface): ServerRequestInterface`
helper exposes the same transform for hand-rolled front controllers (nene-clear's
original shape).

### 2. The header name is fixed

`X-Authorization` is a constant (`FALLBACK_HEADER`), not a constructor parameter.
It is a fleet-wide wiring contract with `@hideyukimori/nene2-client`, and making it
configurable would invite drift between products for zero benefit — a host that
strips `Authorization` does not care what the mirror is called.

### 3. Pipeline integration is opt-in

`RuntimeApplicationFactory` gains a trailing constructor parameter
`bool $enableAuthorizationHeaderFallback = false` (backward compatible, same shape
as `$enableHsts`). When enabled, the middleware runs at the **start of the auth
stage** — after the request size limit, before `ApiKeyAuthenticationMiddleware`
and any injected `$authMiddleware` — so every credential-reading middleware sees
the restored header. The documented middleware order (request id → logging →
security headers → CORS → error handling → size limit → **auth** → throttle →
routing) is unchanged; the fallback is the first step of stage 7.

Opt-in is the deliberate safe-side choice per the trade-off above: products deployed
on proxy-stripping hosts flip one flag; everyone else keeps the standard trust
boundary. The alternative (auto-enable, document an escape hatch) was rejected
because the failure mode of a wrong default is silent credential acceptance, not a
broken feature.

## Consequences

- Products delete their hand-written fallbacks (nene-clear
  `src/Http/AuthorizationHeaderFallback.php`) and pass
  `enableAuthorizationHeaderFallback: true` — or keep their own wiring and reuse
  the middleware directly. Fixes and hardening now ship via `composer update`.
- Deployments that enable the flag accept that `X-Authorization` is
  credential-equivalent to `Authorization`: proxies and logging in front of the
  app must treat it with the same confidentiality (NENE2's own logging already
  never records credential headers), and gateways that deliberately strip
  `Authorization` must strip `X-Authorization` too before enabling the flag.
- The mirror is adopted verbatim (`Bearer <token>` included); token validation
  remains entirely the job of the existing auth middleware, so a garbage mirror
  fails exactly like a garbage standard header.

## Related

- ADR 0013 (guarded JWT secret resolution — the same "one canonical copy,
  distributed via composer" rationale), ADR 0009 (public API scope)
- Issue: `#1557`
- Field evidence: nene-clear #265 (production HETEML), nene-vault #118 (`.htaccess`),
  nene2-js #102 (client mirror)
- Supersedes: none
- Superseded by: none
