---
title: "Add a Disposable Demo"
category: product
tags: [demo, multi-tenant, provisioning, seeding, rate-limiting, cron]
difficulty: advanced
related: [add-rate-limiting, add-custom-route, multi-tenant-isolation]
---

# Add a Disposable Demo

This guide shows how to give your product an **invoice-style disposable demo**: a visitor
opens `GET /demo/{template}`, the app provisions a brand-new throwaway organization, seeds
it with realistic industry data, seats an authenticated session, and 302-redirects into the
fresh tenant. A cron sweeper destroys demo orgs after a TTL. "Reset the demo" is simply
hitting the URL again — a new org every time.

The framework module is `Nene2\Demo`. It owns the product-independent orchestration
(gate → throttle/capacity → slug allocation with conflict retry → provision → seed → seat)
and the sweep decision (TTL + overflow). You implement four small interfaces that carry
everything product-specific.

**Prerequisite**: a working NENE2 application with a multi-tenant organization model
(orgs identified by slug) and some way to create and delete an organization.

---

## What the framework provides vs. what you implement

| Framework (`Nene2\Demo`) | You (the product) |
|---|---|
| `StartDisposableDemoHandler` — the HTTP orchestration | `DisposableOrgProvisionerInterface` — create one demo org + admin |
| `DisposableDemoSweeper` — TTL / overflow decision, `SweepReport` | `DisposableOrgReaperInterface` — destroy one org **and its children** |
| `CountingDemoCapacityGuard` — creation-time ceiling + per-IP throttle | `DemoSessionSeaterInterface` — auth handoff + redirect |
| `DemoConfig` — typed `DEMO_*` settings on `AppConfig::$demo` | `DemoDataSeederInterface` — industry seed data |
| `DemoRouteRegistrar` — registers `GET /demo/{template}` | `DemoTemplateKeyInterface` — your template enum |
| `MinimalDemoErrorPageRenderer` — unbranded browser error page | `DemoErrorPageRendererInterface` — branded error page (optional) |

---

## 1. Configure

The `DEMO_*` variables are loaded by `ConfigLoader` into `AppConfig::$demo`
(a typed `Nene2\Demo\DemoConfig`) — never read them with `getenv()`:

```bash
DEMO_MODE=1            # strictly parsed: only 1/true/yes enable it; off by default
# DEMO_SLUG_PREFIX=demo-
# DEMO_TTL_HOURS=3
# DEMO_MAX_ORGS=200
# DEMO_SLUG_ATTEMPTS=5
```

With `DEMO_MODE` unset the endpoint answers a plain 404 — you can ship the wiring dormant
and enable it per deployment.

## 2. Define the template key

A string-backed enum of your seedable industry presets:

```php
enum DemoTemplate: string implements DemoTemplateKeyInterface
{
    case Kensetsu = 'kensetsu';   // the {template} URL segment
    case Seisaku = 'seisaku';

    public function value(): string
    {
        return $this->value;
    }

    public static function tryFromValue(string $value): ?static
    {
        return self::tryFrom($value);
    }
}
```

## 3. Implement the provisioner

A thin wrapper over your existing "create organization" use case. Throw
`SlugConflictException` on a taken slug (the handler retries with a fresh random slug),
generate throwaway admin credentials internally, and return the admin's id — the
framework never looks the admin up by role literal:

```php
final readonly class DemoOrgProvisioner implements DisposableOrgProvisionerInterface
{
    public function __construct(private CreateOrganizationUseCaseInterface $createOrg)
    {
    }

    public function provision(string $slug, string $template): ProvisionedDemoOrg
    {
        try {
            $org = $this->createOrg->execute(null, new CreateOrganizationInput(
                name: $this->companyName($template),
                slug: $slug,
                adminEmail: 'admin@' . $slug . '.demo.local',
                adminPassword: SecureTokenHelper::generate(16),
            ));
        } catch (OrganizationSlugConflictException $e) {
            throw new SlugConflictException($slug, previous: $e);
        }

        return new ProvisionedDemoOrg($org->id, $org->slug, $org->adminUserId);
    }
}
```

## 4. Implement the seeder

Seed content is yours entirely. Two hard rules:

- **Write through ONE injected connection** — the same executor the request already uses.
  A second PDO to the same database deadlocks under SQLite (`database is locked`).
- **Every row carries the explicit `$orgId`** — the demo route is org-less at entry, so
  seeding is a deliberate cross-tenant write into the org you just created. Never rely on
  a request-scoped tenant holder here.

```php
final class DemoDataSeeder implements DemoDataSeederInterface
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $query,
        private readonly ClockInterface $clock,
    ) {
    }

    public function seed(int $orgId, DemoTemplateKeyInterface $template): void
    {
        // insert clients, items, documents ... anchored to $this->clock->now()
    }
}
```

Anchor seeded dates to the injected clock (relative "this month" dates keep the demo
looking current) and clamp historical events to today.

## 5. Implement the seater

This is where your product's authentication lives, fully isolated. A cookie-session
product issues its login cookies scoped to the new tenant and 302s into the SPA; a
JWT-bearer product lands the SPA its own way:

```php
final readonly class DemoSessionSeater implements DemoSessionSeaterInterface
{
    public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
    {
        $token = $this->refreshTokens->issue($org->adminUserId, $org->orgId);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', '/' . $org->slug . '/dashboard')
            ->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', /* session cookie scoped to the tenant */);
    }
}
```

Keep product-specific session semantics (e.g. a one-shot cookie where reloading drops to
the login screen) inside this class — they must not leak into the orchestration.

## 6. Implement the reaper

> **Warning**: the typical "delete organization" use case does **not** cascade to child
> tables — deleting only the org row strands orphan children forever. The reaper owns the
> full teardown, and the framework deliberately does not try to guess your schema.

Delete child rows first (including grandchildren reachable only through a parent), then
the org, then any residue outside the database (file stamps, caches). `reap()` must be
**idempotent**: an org already swept by a concurrent run is success, not an error —
`DisposableDemoSweeper` relies on this and does not catch your exceptions.

```php
final readonly class DemoOrgReaper implements DisposableOrgReaperInterface
{
    public function reap(int $orgId): void
    {
        foreach (self::CHILD_TABLES as $table) {
            $this->query->execute("DELETE FROM {$table} WHERE organization_id = ?", [$orgId]);
        }

        try {
            $this->deleteOrg->execute(null, $orgId);
        } catch (OrganizationNotFoundException) {
            // already gone (concurrent sweep) — idempotent success
        }
    }
}
```

## 7. Wire the handler and route

```php
$config = $container->get(AppConfig::class);

$guard = new CountingDemoCapacityGuard(
    // Inject the count — the framework carries no knowledge of your tenant schema.
    demoOrgCount: fn (): int => (int) $query->fetchValue(
        'SELECT COUNT(*) FROM organizations WHERE slug LIKE ?',
        [$config->demo->slugPrefix . '%'],
    ),
    config: $config->demo,
    throttleStorage: $rateLimitStorage,   // shared storage in production!
);

$handler = new StartDisposableDemoHandler(
    $config->demo,
    $guard,
    new DemoOrgProvisioner($createOrg),
    new DemoDataSeeder($query, $clock),
    new DemoSessionSeater(...),
    $problemDetails,
    DemoTemplate::class,
);

(new DemoRouteRegistrar($handler))($router);   // GET /demo/{template}
```

The endpoint is public and org-less by design (it *creates* orgs). If your product has
tenant-resolution middleware, exempt `/demo/...` from org resolution.

> **Warning**: the same caveats as [Add Rate Limiting](add-rate-limiting.md) apply to the
> guard's throttle: `InMemoryRateLimitStorage` does not share state across PHP-FPM workers
> (use Redis/Memcached/DB in production), and behind a reverse proxy inject a
> `keyExtractor` that reads your trusted forwarded-IP header — otherwise every client
> shares one bucket.

The guard's throttle defaults to **30 demo starts per hour per client IP**. If you tune
`throttleLimit`, keep in mind that this style of demo is one-shot by design — "reset the
demo" means re-clicking the link, and every click consumes a start — and that office and
mobile-carrier NAT puts many legitimate visitors behind a single IP. A limit of 10/h
starved normal use in production; do not go lower.

## 8. Optional: brand the browser error page

The demo start route is the one route real people open in a **browser** (a sales
prospect clicking a referral link), so the handler content-negotiates its errors: when
the request's `Accept` header contains `text/html`, the 4xx/5xx Problem Details JSON is
replaced by an HTML page from the `DemoErrorPageRendererInterface` you inject. The
default is the bundled `MinimalDemoErrorPageRenderer` — a minimal, unbranded English
card — so this works out of the box; replace it to supply your product's copy, language,
and brand:

```php
final readonly class BrandedDemoErrorPageRenderer implements DemoErrorPageRendererInterface
{
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
    {
        // Fixed copy per status; turn $retryAfterSeconds into "try again in ~N minutes".
    }
}

$handler = new StartDisposableDemoHandler(
    // ... as in step 7 ...
    errorPageRenderer: new BrandedDemoErrorPageRenderer($responseFactory),
);
```

The framework enforces the transport invariants no matter which renderer is wired: the
page keeps the original error status, the original `Retry-After` header (429), and gets
`X-Robots-Tag: noindex`. API clients (no `text/html` in `Accept`) and the success
redirect stay byte-for-byte unchanged.

Two hard rules for custom renderers:

- **Never put request input in the page.** The interface deliberately receives only the
  status code and the retry seconds — all copy must be fixed text plus server-computed
  numbers, or the error page becomes an XSS vector. Also include
  `<meta name="robots" content="noindex">` and reference no external assets.
- **Mind the Content-Security-Policy.** Your app almost certainly runs
  `SecurityHeadersMiddleware` with an app-wide `default-src 'self'`, which **blocks the
  inline `<style>`/`<script>` a self-contained error page needs** — the page renders as
  bare unstyled text. That middleware only adds headers that are absent, so ship a
  per-page CSP on the renderer's response:

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'
  ```

  Add `script-src 'unsafe-inline'` only if the page really carries a script (e.g. a
  retry countdown). Inline allowances are safe here precisely because the page contains
  no request input. The bundled renderer already ships this CSP.

If you need more than a different error page — extra gates, logging, response
post-processing — `DemoRouteRegistrar` accepts any PSR-15 `RequestHandlerInterface`, so
you can wrap `StartDisposableDemoHandler` in a decorator instead of re-implementing the
route registration.

## 9. Sweep on cron

```php
// tools/sweep-demo.php — run hourly
$sweeper = new DisposableDemoSweeper($config->demo, new DemoOrgReaper(...), new UtcClock());

$rows = $query->fetchAll(
    'SELECT id, created_at FROM organizations WHERE slug LIKE ?',
    [$config->demo->slugPrefix . '%'],
);
$report = $sweeper->sweep(array_map(
    static fn (array $row): DemoOrgRecord => new DemoOrgRecord(
        (int) $row['id'],
        new DateTimeImmutable((string) $row['created_at']),
    ),
    $rows,
));

echo count($report->reapedOrgIds) . " demo orgs swept\n";
```

Two criteria combine: orgs older than `DEMO_TTL_HOURS` expire, and only the newest
`DEMO_MAX_ORGS` survive regardless of age (runaway insurance). The sweeper only ever sees
the records you pass it — your query's `LIKE 'demo-%'` filter is what protects real
organizations, so never widen it.

---

## HTTP surface

| Situation | Response |
|---|---|
| `DEMO_MODE` off | 404 `not-found` (indistinguishable from no route) |
| Unknown `{template}` | 404 `not-found` |
| Per-IP throttle exceeded | 429 `too-many-requests` + `Retry-After` |
| Demo org ceiling reached | 503 `demo-capacity-exceeded` |
| All slug attempts collided | `SlugConflictException` escapes → 500 via error middleware |
| Success | whatever your seater returns (typically 302 + `Cache-Control: no-store`) |

API clients receive RFC 9457 Problem Details; browser clients (`Accept` containing
`text/html`) receive the error page from step 8 with the same status and `Retry-After`.

## Why guard at creation time when the sweeper already caps the count?

Sweeping alone caps the **steady state**. Between sweeps, a crawler or attacker can grow
the tenant table without bound — each demo start writes an org plus its full seed data.
`CountingDemoCapacityGuard` closes that gap by checking the ceiling and the per-client
rate **before anything is created**.
