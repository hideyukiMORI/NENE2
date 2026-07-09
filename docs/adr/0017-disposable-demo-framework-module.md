# ADR 0017: Disposable Demo Framework Module (`Nene2\Demo`)

## Status

accepted

## Context

On 2026-07-09 the product owner decided that every NeNe product adopts the
demo style proven on `nene-invoice`: a **disposable organization** per demo
visit. `GET /demo/{template}` provisions a brand-new throwaway tenant, seeds it
with industry data, seats an authenticated session, and 302-redirects into the
fresh org; a cron sweeper destroys demo orgs after a TTL. "Reset the demo" is
simply opening the URL again.

The invoice implementation (read-only anatomy in
`_work/handoff-nene2-demo-module-2026-07-09.md`) works but is not liftable
as-is, and it has known gaps:

- **No creation-time capacity guard** (invoice #608): sweeping caps only the
  steady state. Between sweeps, a crawler or attacker grows the tenant table
  without bound — each start writes an org plus full seed data. There is also
  no per-IP rate limit on demo starts.
- **Untyped, undocumented configuration**: the handler reads `DEMO_MODE` with
  `getenv()`, the sweep script reads `DEMO_TTL_HOURS` / `DEMO_MAX_ORGS` the
  same way, and none of them appear in `.env.example` — an operational trap.
- **Product-specific concerns are interleaved** with the reusable
  orchestration: cookie mechanics, `role='admin'` lookup by literal, child
  table lists, SPA paths.

Rolling this out per product by copy-paste would duplicate the orchestration
(and the #608 gap) into every codebase. NENE2 is the right home for one tested
skeleton, with the product differences behind interfaces.

### Non-goals

- Migrating invoice/clear/vault to consume the module (follow-up work, one
  product at a time, starting with invoice).
- A demo story for single-tenant products without an org concept (deal) — that
  needs its own design decision first.

## Decision

Add a `Nene2\Demo` module: the product-independent orchestration as framework
concretes, the product-specific parts as five small interfaces.

**Interfaces the product implements:**

- `DisposableOrgProvisionerInterface::provision(slug, template): ProvisionedDemoOrg`
  — a thin wrapper over the product's existing org-creation use case. Throws
  `SlugConflictException` (retryable). `ProvisionedDemoOrg` carries
  `adminUserId` resolved at creation time, eliminating the product-specific
  "find the admin again by role literal" step.
- `DisposableOrgReaperInterface::reap(orgId): void` — full teardown, **child
  tables included**, contractually idempotent. This stays a product concern by
  design: the consuming products' `DeleteOrganizationUseCase` does **not**
  cascade (verified on invoice), so a generic framework reaper would silently
  strand orphan child rows.
- `DemoSessionSeaterInterface::seatAndRedirect(request, org): ResponseInterface`
  — the auth handoff. Cookie vs JWT-bearer differences are fully isolated here;
  product-specific session semantics (invoice's one-shot cookie) must not leak
  into the orchestration.
- `DemoDataSeederInterface::seed(orgId, template): void` — seed content. The
  contract mandates writing through **one injected connection** (a second PDO
  deadlocks under SQLite, observed on invoice) with explicit `orgId` on every
  row (the request is org-less at entry).
- `DemoTemplateKeyInterface` — the template key; a string-backed enum satisfies
  it with two one-line methods.

**Framework concretes:**

- `StartDisposableDemoHandler` — gate → throttle/capacity → slug allocation
  with conflict retry → provision → seed → seat. Errors are RFC 9457 Problem
  Details: 404 (mode off / unknown template), 429 + `Retry-After` (throttled),
  503 (capacity). Exhausted slug attempts rethrow — with random slugs that
  indicates a systemic failure, not bad luck.
- `DemoCapacityGuardInterface` + `CountingDemoCapacityGuard` — the #608 root
  fix: an instance-wide ceiling checked **before creation** and a per-IP fixed
  window throttle. The org count is an injected closure and the throttle reuses
  `Nene2\Middleware\RateLimitStorageInterface` (ADR 0010), so the framework
  learns nothing about the product's tenant schema and inherits the existing
  storage ecosystem (in-memory for dev, shared store for production).
- `DisposableDemoSweeper::sweep(list<DemoOrgRecord>): SweepReport` — the TTL +
  overflow decision, delegating destruction to the reaper. Stateless: the
  caller queries its own schema and passes `DemoOrgRecord` (id + createdAt)
  values in, `Nene2\Install`-style.
- `DemoConfig` — typed `DEMO_*` settings (`demoMode`, `slugPrefix='demo-'`,
  `ttlHours=3`, `maxOrgs=200`, `slugAttempts=5`; defaults are the values
  invoice ran in production), assembled by `ConfigLoader` and carried on
  `AppConfig::$demo`. `DEMO_MODE` uses the same strict opt-in parse as
  `NENE2_ALLOW_DEV_SECRET` (only `1`/`true`/`yes`): the endpoint creates
  organizations without authentication, so a typo fails closed.
- `DemoRouteRegistrar` — registers `GET /demo/{template}`.

All of it is opt-in and dormant: nothing changes for products that do not wire
the module, and `AppConfig` only gained a defaulted trailing constructor
parameter (backward compatible per ADR 0009).

### Configuration lives in `ConfigLoader`, not a module-local env reader

The alternative — `DemoConfig::fromEnvironment()` reading `$_ENV` itself — was
rejected: "raw environment access stays inside `ConfigLoader`" is a framework
invariant (`docs/development/configuration.md`), and the whole point of typing
this configuration is to end the scattered `getenv()` reads that made
`DEMO_TTL_HOURS` undocumented in the first place. This follows the
`DatabaseConfig` nesting precedent.

## Consequences

- Products add a disposable demo by implementing four small classes plus an
  enum and wiring them (`docs/howto/add-disposable-demo.md`); the orchestration,
  guards, and sweep policy arrive tested and stay fixable in one place.
- The #608 class of abuse is closed at creation time for every adopter, not
  patched per product.
- The reaper contract makes the child-teardown responsibility explicit and
  keeps schema knowledge out of the framework — at the cost that each product
  must maintain its own child-table list. That cost is inherent: only the
  product knows its schema.
- `AppConfig` now references `Nene2\Demo\DemoConfig`, a deliberate exception to
  "config objects live in `Nene2\Config`" so the module's public types stay in
  one namespace.
- Consumer migration order: invoice (replaces its `src/Demo/` and gains the
  capacity guard) → clear → vault; deal is deferred pending an org-model
  decision.
