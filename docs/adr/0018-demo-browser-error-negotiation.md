# ADR 0018: Browser-Facing HTML Error Negotiation for the Demo Start Route

## Status

accepted

## Context

`GET /demo/{template}` (ADR 0017) is the one NENE2 route that real people open
in a browser: a sales prospect clicking a referral link. Its errors, however,
were RFC 9457 Problem Details JSON like every other framework error — correct
for API clients, but a non-technical visitor who hits the per-IP throttle reads
raw JSON as "the site is broken". This happened in nene-invoice production on
2026-07-10: a residual throttle count from a verification run served the
product owner's browser a bare 429 JSON document.

Invoice fixed it product-side (nene-invoice #612/#613/#615): a wrapper that
replaces 4xx/5xx with a branded Japanese explanation page when the client's
`Accept` contains `text/html`. Doing so surfaced three upstream problems:

1. **`DemoRouteRegistrar` was typed to the concrete `StartDisposableDemoHandler`**,
   so invoice had to re-introduce its own registrar just to wrap the handler —
   exactly the duplication the module was built to end.
2. Every consumer (clear/deal/vault are queued for the same consumer migration)
   would have to rediscover and re-implement the same negotiation, including a
   subtle **CSP trap**: consuming apps run `SecurityHeadersMiddleware` with an
   app-wide `default-src 'self'`, which blocks the error page's inline styles —
   invoice's first page shipped visibly unstyled until #615 gave the page its
   own per-page CSP.
3. The guard's **throttle default of 10 starts/hour starved legitimate use** in
   invoice production. The demo is one-shot by design ("reset" = re-click the
   link, each click consuming a slot) and office/carrier NAT puts many
   legitimate visitors behind one IP. Invoice raised it to 30/h.

## Decision

### 1. HTML negotiation is a framework default of `StartDisposableDemoHandler`

When a demo-start response has status >= 400 **and** the request's `Accept`
header contains `text/html`, the handler replaces the Problem Details response
with the page produced by an injected `DemoErrorPageRendererInterface`:

```php
interface DemoErrorPageRendererInterface
{
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface;
}
```

The renderer deliberately receives **only** the status and the parsed
`Retry-After` seconds — not the request and not the Problem Details body. All
page copy must be fixed text plus server-computed numbers, so a renderer cannot
accidentally echo request input and become an XSS vector (the 404 detail string
does contain the raw `{template}` segment; it stays JSON-only).

The handler enforces the transport invariants **after** the renderer runs, so
they hold no matter which renderer a product wires:

- the page's status is reset to the original error status;
- the original `Retry-After` header (429) is copied onto the page;
- `X-Robots-Tag: noindex` is added (header-level noindex works even if a
  custom renderer forgets the meta tag).

API-shaped clients (no `text/html` in `Accept`) and successful responses are
**byte-for-byte unchanged** — fixed by unit tests. The slug-conflict escape
path (exhausted retries rethrow out of the handler) stays app-wide error
handling and is not negotiated; with random slugs it signals a systemic
failure, not a visitor-facing condition.

The default is ON: the bundled `MinimalDemoErrorPageRenderer` arrives as a
defaulted trailing constructor parameter (`new`-in-initializer, the
`LocalBearerTokenVerifier` clock precedent). Existing consumers recompile
unchanged; invoice's product-side wrapper keeps working because it re-wraps
whatever the handler returns.

### 2. The bundled default renderer is minimal, English, and CSP-self-carrying

`MinimalDemoErrorPageRenderer` renders an unbranded English card per status
(429 "in high demand" with an "about N minutes" hint computed from
`Retry-After`, 503 "fully booked", 404 "not available", generic fallback). No
scripts, no external assets, `<meta name="robots" content="noindex">`.

**CSP decision**: the page uses a small inline `<style>` block and therefore
ships its own per-page `Content-Security-Policy` (`default-src 'none';
style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'`). This works
because `SecurityHeadersMiddleware` only adds headers that are **absent**, so
the page's own CSP survives the app-wide default. The alternative — a fully
CSP-safe unstyled page — was rejected: an unstyled wall of text reads nearly as
"broken" as the JSON it replaces, and the per-page CSP is strictly tighter than
the app default everywhere except inline styles, which are safe here precisely
because the page never contains request input. Custom renderers using inline
styles must do the same; the howto documents the trap with a reference header.

The class and constructor join the stable surface; the rendered markup and copy
are presentation, not contract, and may change in minor releases (products that
care about wording ship their own renderer).

### 3. `DemoRouteRegistrar` accepts any PSR-15 `RequestHandlerInterface`

The constructor parameter is widened from the concrete
`StartDisposableDemoHandler` to `RequestHandlerInterface` (which the handler
already implements). Products can now decorate the handler — logging, extra
gates, response post-processing, or their own error pages — without
re-implementing the registrar. Widening an accepted parameter type breaks no
existing call site (ADR 0009: signature changes are breaking only when they
break call sites), and the parameter name `handler` is kept for named-argument
callers.

### 4. The guard's throttle default rises from 10 to 30 starts/hour

`CountingDemoCapacityGuard::$throttleLimit` now defaults to 30, the value
invoice validated in production. Rationale: one-shot re-click demos consume a
slot per click, and NAT aggregation means one IP is many visitors, so 10/h
throttles legitimate prospects. 30/h still caps a hostile crawler at trivial
cost (30 orgs/h/IP against the creation-time ceiling of `DEMO_MAX_ORGS`).
Consumers that pinned `throttleLimit: 10` explicitly are unaffected.

## Consequences

- clear/deal/vault consumer migrations get humane browser errors for free and
  cannot re-hit the CSP trap through the bundled renderer; invoice can retire
  its own registrar and move `DemoBrowserErrorPage` into a
  `DemoErrorPageRendererInterface` implementation (follow-up, product-side).
- A behavior change rides the minor release: API clients that send
  `Accept: text/html` (none known — browsers do, API tooling does not) start
  receiving HTML for demo-start errors. Documented in the CHANGELOG.
- The stable surface grows by one interface and one concrete
  (`DemoErrorPageRendererInterface`, `MinimalDemoErrorPageRenderer`); ADR 0009
  is updated. The registrar's widened constructor is a superset of the old
  contract.
- Products relying on the old 10/h default silently get 30/h after upgrading —
  intended (the old default was wrong in practice), and pinning the old value
  is a one-argument change.

## Related

- ADR 0017 (disposable demo framework module), ADR 0010 (rate limiting),
  ADR 0009 (public API scope)
- Issue: `#1536`
- Proposal: `_work/handoff-nene2-demo-html-negotiation-2026-07-10-proposal.md`
- Reference implementation: nene-invoice `src/Demo/DemoBrowserErrorPage.php`
  (nene-invoice #613/#615)
