# Roadmap

This roadmap keeps NENE2 focused on a small, modern, AI-readable PHP framework foundation and delivery starter.

## North Star

NENE2 should let a developer clone one repository and quickly build:

- JSON APIs with clear contracts
- thin server HTML when needed
- React frontend starters without backend lock-in, while allowing applications to choose another frontend stack
- testable use cases and adapters
- OpenAPI and MCP integrations through documented boundaries

The first practical delivery target is documented in `docs/integrations/llm-delivery-starter.md`: NENE2 should help its maintainer start LLM-assisted client work with a working API, clear OpenAPI handoff, and safe MCP integration path.

## Phase 0: Project Foundation

Goal: make contribution, AI operation, and technical direction unambiguous before runtime code grows.

- README and contribution guide
- Issue-driven workflow
- local roadmap, milestone, and TODO board
- coding standards
- self-review checklist policy
- language and package selection guardrails
- Cursor rules
- OpenAPI / MCP direction documents
- standard repository layout
- ADR operation policy

Tracked by `docs/milestones/2026-05-nene2-foundation.md`.

## Phase 1: Framework Skeleton

Goal: create the smallest useful runtime structure.

- HTTP request/response abstraction
- PSR-7 / PSR-15 / PSR-17 based HTTP runtime policy
- route dispatch foundation
- middleware pipeline foundation
- middleware security baseline policy
- logging and observability policy
- typed config loading and environment policy
- Composer package metadata
- PSR-11 dependency injection and explicit wiring policy
- RFC 9457 Problem Details error response policy
- layered request validation and readonly DTO policy
- minimal HTML response support through native PHP templates
- database migration layout and tool selection policy
- first PHPUnit test suite
- PHPDoc / TSDoc and quality tools policy
- release, versioning, and CI policy

## Phase 2: API Contract Foundation

Goal: make API behavior explicit and client-friendly.

- OpenAPI document location and generation policy
- request and response schema conventions
- contract-first request validation tests
- shared Problem Details error schemas
- contract tests
- examples for success and failure responses

## Phase 2.5: Database Adapter Foundation

Goal: support database-backed applications without making framework core database-dependent.

- choose migration runner, with Phinx as the first candidate
- define database config and environment variables
- add repository / adapter boundaries
- define test database strategy
- document migration and seed commands

## Phase 3: Frontend Integration

Goal: make the React + TypeScript starter easy to adopt without making NENE2 a frontend framework or blocking other frontend stacks.

- React + TypeScript `frontend/` starter policy
- npm, active Node.js LTS, and lockfile policy
- Vite-oriented integration notes
- API client generation or typed fetch wrapper policy
- SPA shell and static asset serving guidance

## Phase 4: MCP and AI Tooling

Goal: let AI tools inspect and operate the app through safe, documented contracts.

- LLM-assisted delivery starter direction
- MCP server integration guidance
- API-key or token boundary policy
- tool definitions derived from OpenAPI where practical
- safe local development commands
- request id and structured log context for AI-assisted debugging

## Phase 5: Quality and Release

Goal: keep the framework small while making changes safe.

- static analysis
- formatter and linter configuration
- PHP-CS-Fixer, ESLint, TypeScript, and Prettier check commands
- dependency update automation such as Dependabot or Renovate
- logging, metrics, and error tracking adapter policy
- GitHub Actions, SemVer, tags, and release checklist
- mutation or architecture tests where useful
- semantic versioning policy
- release checklist

Tracked by `docs/milestones/2026-05-v0.1.0-release-readiness.md` for the first foundation release.

## Phase 6: LLM Delivery Starter

Goal: make the released foundation useful for real LLM-assisted client delivery.

- local MCP server that clients can connect to
- read-only MCP tools aligned with OpenAPI and Problem Details behavior
- API-key authentication middleware for machine clients
- endpoint scaffold workflow that keeps PHP code, OpenAPI, and tests aligned
- real service database verification through Docker Compose
- small `v0.1.x` patch releases when delivery-starter increments are worth tagging

Tracked by `docs/milestones/2026-05-llm-delivery-starter.md`.

## Phase 7: Client Delivery Hardening

Goal: make the delivery-starter foundation easier to reuse for small client-style API projects.

- README entry points for setup, verification, and current capabilities
- new project start guidance for adapting the foundation
- local MCP client configuration examples
- protected machine-client API smoke workflows
- `v0.1.x` checkpoint release decisions after useful delivery-starter increments

Tracked by `docs/milestones/2026-05-client-delivery-hardening.md`.

## Phase 8: First LLM Field Trial

Goal: prove the delivery-starter workflow in a real or realistic client-style project.

- start from the `v0.1.1` checkpoint release
- add a small endpoint using the documented scaffold workflow
- connect a local LLM/MCP client through the documented stdio configuration
- record at least one MCP tool call through the API boundary
- turn observed friction into focused follow-up Issues

Tracked by `docs/milestones/2026-05-field-trial.md`.

## Phase 9: Domain Layer Starter

Goal: add a minimal, conventional domain layer pattern so that application code has a clear, documented path from HTTP handler to use case to repository adapter.

- UseCase interface and single-responsibility invocation convention
- RepositoryInterface convention for database-backed adapters
- readonly input and output DTOs for cross-layer data passing
- at least one working example endpoint demonstrating the full UseCase → Repository → Handler path
- PHPUnit unit tests for the use case and integration tests for the PDO adapter
- updated endpoint scaffold workflow and client project start guide referencing domain patterns
- self-review checklist entries for domain layer concerns

Tracked by `docs/milestones/2026-05-domain-layer-starter.md`.

## Phase 10: Write Operations Pattern

Goal: complete the CRUD template by adding POST and DELETE endpoints to the domain layer example so that client projects have a concrete, tested write-operation reference to copy and adapt.

- `post()` and `delete()` methods on the Router
- `lastInsertId()` on `DatabaseQueryExecutorInterface`
- write methods on `NoteRepositoryInterface` and `PdoNoteRepository`
- `CreateNoteUseCase` and `DeleteNoteUseCase` with readonly DTOs
- `CreateNoteHandler` (201 Created + Location) and `DeleteNoteHandler` (204 No Content)
- body validation with `ValidationException` → 422 Problem Details
- OpenAPI schemas for write operations
- PHPUnit unit and integration tests for write paths

Tracked by `docs/milestones/2026-05-write-operations-pattern.md`.

## Phase 11: Error Handler Systemization

Goal: centralize domain exception → HTTP response mapping in the middleware layer so handlers stay thin and new domain exceptions do not require handler changes.

- `DomainExceptionHandlerInterface` with `supports()` and `handle()`
- `ErrorHandlerMiddleware` accepts `list<DomainExceptionHandlerInterface>`
- `NoteNotFoundExceptionHandler` as the first registered domain mapper
- handlers (`GetNoteByIdHandler`, `DeleteNoteHandler`) no longer catch domain exceptions
- `RuntimeServiceProvider` wires domain handlers explicitly

Tracked by `docs/milestones/2026-05-error-handler-systemization.md`.

## Phase 12: Test Coverage Hardening

Goal: close the gap between unit-level use case tests and HTTP-level integration by adding end-to-end tests for the Note endpoints through the middleware stack.

- `HttpRuntimeTest` (or dedicated `NoteHttpTest`) covers all Note endpoint behaviors
- GET with valid id → 200
- GET with missing note → 404 via `DomainExceptionHandlerInterface`
- GET with invalid id (≤ 0) → 404 via `DomainExceptionHandlerInterface`
- POST with valid body → 201 + Location header
- POST with invalid body → 422 Problem Details
- DELETE with valid id → 204
- DELETE with missing note → 404 via `DomainExceptionHandlerInterface`

Tracked by `docs/milestones/2026-05-test-coverage-hardening.md`.

## Phase 13: Collection Endpoint Pattern

Goal: complete the CRUD example set by adding a list endpoint that demonstrates collection response shape and introduces a basic pagination design decision.

- `GET /examples/notes` returning an array of notes
- `ListNotesUseCase` with optional limit/offset or cursor parameters
- `findAll()` on `NoteRepositoryInterface` and `PdoNoteRepository`
- OpenAPI schema for collection response
- PHPUnit unit and integration tests for the list path

## Phase 14: Logger Integration Decision

Goal: resolve the `NullLogger` placeholder by either wiring a real structured logger or explicitly documenting that the logger is the application owner's responsibility.

- ADR recording the decision: embed Monolog vs leave PSR-3 open for the caller
- if embedded: `RequestLoggingMiddleware` emits structured fields (`request_id`, `method`, `path`, `status`, `duration_ms`)
- if caller-provided: update setup guide and service provider examples

## Phase 15: Field Trial 2

Goal: validate the full CRUD domain layer pattern in a second client-style project, exercising GET/POST/DELETE and the collection endpoint in a realistic scenario.

- start from the latest `v0.1.x` checkpoint
- scaffold at least one new domain entity using documented patterns
- connect local MCP client and verify tool calls against new endpoints
- record friction and open follow-up Issues

## Phase 16: v0.2.0 Readiness ✓

Goal: stabilize the public surface enough to make an intentional `v0.2.0` decision, covering API stability, Packagist publication readiness, and the boundary between framework core and example code.

- [x] decide whether `src/Example/` stays in core or ships as a separate repository → stays in core through v0.2.x (ADR 0006)
- [x] decide Packagist publication timeline → deferred to v0.3.0+ (ADR 0006)
- [x] create `CHANGELOG.md` covering v0.1.0–v0.1.3
- [x] tag `v0.1.3` as stable checkpoint before v0.2.0 work begins

## Phase 17: Full Note CRUD + v0.2.0 ✓

Goal: complete the Note CRUD example and cut v0.2.0.

- [x] `PUT /examples/notes/{id}` update endpoint
- [x] `Router::put()` method
- [x] v0.2.0 tag

## Phase 18: Structured Logging with Request Correlation ✓

Goal: attach `X-Request-Id` to every Monolog log record for request-level traceability.

- [x] `RequestIdHolder` + `RequestIdProcessor`
- [x] `RequestIdMiddleware` populates the holder
- [x] `MonologLoggerFactory` wires the processor

## Phase 19: v0.3.0 Readiness

Goal: consolidate v0.2.x patterns, resolve Field Trial 2 friction, and prepare for Packagist publication.

- ADR 0007: PUT vs PATCH policy (#218)
- v0.3.0 milestone and Packagist criteria review
- CHANGELOG.md [Unreleased] prepared
- v0.3.0 tag + Packagist registration (if criteria met)

## Phase 20: Packagist Field Trial

Goal: validate the `composer require hideyukimori/nene2` path as a real starting point for new projects, distinct from the git-clone-based workflow used in all prior field trials.

- start a new project directory with `composer require hideyukimori/nene2`
- build the minimum front controller, `.env`, and server setup manually
- add a working endpoint following the documented scaffold workflow
- record friction that only appears when using the framework as a library dependency
- convert friction into follow-up Issues

Tracked by `docs/milestones/2026-05-phase20-packagist-field-trial.md`.

## Phase 21: Field Trial 5 — MCP Write Tools

Goal: validate that the MCP write tools added in v0.4.0 work end-to-end through the local MCP server, covering `POST`, `PUT`, and `DELETE` operations against the Note endpoints.

- start the local MCP server against a running NENE2 app container
- call `create_note`, `update_note`, and `delete_note` tools through an MCP client
- confirm path parameter interpolation (`{id}` → actual id) works correctly
- record friction and open follow-up Issues

Tracked by `docs/milestones/2026-05-phase21-field-trial-5.md`.

## Phase 22: Diátaxis Documentation

Goal: make NENE2 accessible to developers who know JavaScript or Python but have not used PHP before, by producing Markdown documentation structured around the Diátaxis framework.

- Tutorial: "Your first API in 10 minutes" — `composer require` to running JSON endpoint
- HOWTO: "Add a custom route" — registrar pattern with path parameters
- HOWTO: "Add a database-backed endpoint" — UseCase → Repository → Handler walkthrough
- `docs/` index updated to surface the new entry points

HTML generation is deferred to a later phase.

Tracked by `docs/milestones/2026-05-phase22-diataxis-docs.md`.

## Phase 23: CI Hardening + Node.js Upgrade

Goal: close the Node.js 20 deprecation gap in CI before June 2026 EOL, and strengthen automated checks so regressions surface earlier.

- Upgrade `actions/setup-node` to Node.js 22 LTS in `docs.yml`
- Add a dedicated `backend.yml` CI workflow running `composer check` (PHPUnit + PHPStan + CS + OpenAPI + MCP)
- Add a `frontend.yml` CI workflow running `npm run check`
- Confirm all workflows pass on clean `main` push

## Phase 24: Diátaxis Explanation Pages

Goal: add the missing fourth Diátaxis pillar — Explanation — so developers understand *why* NENE2 makes the choices it does, not just *how* to use it.

- `docs/explanation/why-psr.md` — why PSR-7/15/17 instead of custom HTTP abstractions
- `docs/explanation/why-explicit-wiring.md` — why explicit DI over autowiring or Laravel-style magic
- `docs/explanation/why-problem-details.md` — why RFC 9457 for API errors
- `docs/explanation/why-mcp.md` — why MCP as the AI integration boundary
- Update `docs/README.md` index to surface Explanation section
- Add Explanation nav/sidebar entry to VitePress config

## Phase 25: Second Domain Entity Example

Goal: prove that the UseCase → Repository → Handler pattern scales beyond a single Note entity by introducing a second, minimally related domain object.

- Choose a lightweight second entity (e.g. `Tag`) that can relate to `Note`
- Add `src/Example/Tag/` mirroring the Note domain layer structure
- Add `GET/POST /examples/tags` and `GET /examples/tags/{id}` endpoints
- Add OpenAPI schemas for the new entity
- Add PHPUnit unit and integration tests for the Tag use cases
- Update endpoint scaffold docs to reference the two-entity example
- Document the multi-entity pattern in `docs/explanation/` or a new HOWTO

## Phase 26: Production Deployment Guide

Goal: document a minimal, secure path from development Docker Compose to a production-grade deployment so that client projects adopting NENE2 have a reference starting point.

- `docs/howto/deploy-production.md` — Docker production image, env file management, secret injection
- Nginx / Caddy reverse proxy configuration example
- Security checklist: disable debug mode, set `APP_ENV=production`, remove dev endpoints
- Health endpoint verification in production context
- Note on `nene2.dev` Problem Details type URIs — confirm or replace placeholder domain before production use

## Phase 27: Frontend Starter Content

Goal: move the React + TypeScript starter beyond a skeleton so adopters have a working, styled example to reference or delete.

- At least one real API-connected component (`NoteList`, `NoteForm`, or equivalent)
- Typed fetch wrapper in `frontend/src/api/` for Note endpoints
- Basic CSS or Tailwind baseline (adopter can replace)
- `npm run dev` demo that renders live Note data from the backend
- Update frontend HOWTO or Tutorial section to reference the working example
- Score target: frontend evaluation ≥ 70/100

## Phase 28: Authentication Expansion

Goal: document the JWT and OAuth2 direction so client projects adopting NENE2 have a clear path beyond the current API-key-only machine client.

- ADR 0008: JWT authentication direction (library selection, token validation boundary, PSR-15 middleware placement)
- `docs/development/authentication-boundary.md` extended with JWT and OAuth2 sections
- Example `BearerTokenMiddleware` stub with interface for pluggable validation
- Decision on whether `nene2.dev` domain will be registered or replaced in Problem Details type URIs
- Update self-review checklist with JWT/OAuth2 checkpoints

## Phase 29: v0.5.0 Readiness

Goal: consolidate Phase 23–28 additions into a versioned release so adopters get a stable checkpoint covering documentation, second domain entity, frontend starter, production guide, and JWT auth stub.

- CHANGELOG.md v0.5.0 section (Phases 23–28)
- `v0.5.0` tag on main
- Roadmap and TODO updated

## Phase 30: OpenAPI Bearer Security Scheme

Goal: make the `BearerTokenMiddleware` fully integrated into the OpenAPI contract with a concrete local demo.

- Add `bearerAuth` `securitySchemes` entry to OpenAPI document
- Add `LocalBearerTokenVerifier` (HMAC-HS256) for local development wiring
- Wire `BearerTokenMiddleware` + `LocalBearerTokenVerifier` to a new `GET /examples/protected` endpoint
- Add `security: [bearerAuth]` to the protected endpoint's OpenAPI path
- Add HTTP-level tests for 200 (valid token) and 401 (missing/invalid) cases

## Phase 31: Self-Review Checklist — Auth Checkpoints

Goal: add JWT/OAuth2 checkpoints to `docs/review/middleware-security.md` so PRs that touch authentication are reviewed consistently.

- Add Bearer token checklist items: interface injection, WWW-Authenticate header, no secret logging
- Add OpenAPI security scheme checklist: `bearerAuth` present, 401 response documented
- Add ADR 0008 compliance reminder

## Phase 32: Field Trial 6 — JWT End-to-End

Goal: validate `BearerTokenMiddleware` + `LocalBearerTokenVerifier` in a realistic project scenario.

- Start MCP client against NENE2 with a protected endpoint
- Issue a local JWT and verify the tool call succeeds
- Record friction and open follow-up Issues

## Phase 33: Architecture Quality (#298 #300)

Goal: make the framework easier to extend by eliminating the growing-constructor problem and closing the MCP authentication policy gap.

- `NoteRouteRegistrar` / `TagRouteRegistrar` — move entity route registration out of `RuntimeApplicationFactory` into `__invoke`-able classes
- `RuntimeApplicationFactory` constructor reduced from 16 to 8 parameters; adding a new entity requires no change to the factory
- `LocalMcpHttpClientInterface::hasAuthentication()` — write tools are gated behind bearer authentication; calling a write tool without `NENE2_LOCAL_JWT_SECRET` set returns a clear error

## Phase 34: v0.6.0 Release

Goal: consolidate Phase 33 changes and complete the multi-entity documentation started in Phase 25.

- `docs/howto/add-second-entity.md` — HOWTO for adding a second domain entity using the RouteRegistrar pattern, in 6 languages
- VitePress HOWTO sidebar updated with fourth entry
- CHANGELOG.md v0.6.0 section
- `v0.6.0` tag

## Phase 35: Tag CRUD Completion (#304)

Goal: restore Note/Tag symmetry by completing Tag full CRUD and closing the MCP tool coverage gap.

- `PUT /examples/tags/{id}` and `DELETE /examples/tags/{id}` endpoints
- `UpdateTagUseCase`, `UpdateTagHandler`, `DeleteTagUseCase`, `DeleteTagHandler` + supporting DTOs and interfaces
- `TagRepositoryInterface` extended with `update()` and `delete()`
- 5 Tag MCP tools added to `docs/mcp/tools.json` (list / get / create / update / delete)
- TagHttpTest expanded with PUT/DELETE coverage (158 tests total)

## Phase 36: Field Trial 7 (#306)

Goal: validate Tag CRUD and MCP write auth guard end-to-end, record friction.

- All Tag CRUD HTTP endpoints verified against MySQL
- MCP write auth guard confirmed (blocks writes without JWT secret)
- All 5 Tag MCP tools validated
- Missing migration discovered and fixed (`CreateTagsTable`)
- Scaffold checklist updated with migration step

## Phase 37: v0.7.0 Release

Goal: consolidate Phase 35–36 changes into a tagged release.

- CHANGELOG v0.7.0 section (Tag CRUD, migrations, scaffold fix)
- CHANGELOG footer links corrected for v0.5.0 / v0.6.0 (previously missing)
- `add-second-entity.md` Tag endpoint table corrected to full CRUD (en/ja)
- VitePress version badge updated to v0.7.0
- `v0.7.0` tag

## Phase 38–39: Coverage and Frontend Parity

Goal: close test coverage gaps and bring the frontend to feature parity with the backend.

- `UpdateTagUseCase` / `DeleteTagUseCase` unit tests (+4)
- `PdoTagRepository::update()` / `delete()` adapter tests (+2)
- `PdoTagRepositoryMySqlTest` — MySQL integration (7 cases)
- `frontend/src/api/tags.ts` typed fetch wrapper
- `TagList` and `TagForm` React components with live API integration

## Phase 40: v1.0 Public API Scope

Goal: declare the stable public API surface before cutting v1.0, so adopters know which interfaces and classes NENE2 commits not to break across major versions.

- ADR 0009: `src/Example/` stays in core as reference implementation, explicitly outside the stability guarantee
- Stable surface documented: `Routing`, `Http`, `DependencyInjection`, `Config`, `Database` interfaces, `Error`, `Auth`, `Middleware`, `Validation`, `View`, `Mcp`
- `@internal` annotations added to implementation-detail classes (`ConfigLoader`, PDO adapters, `Log/*`, `RuntimeServiceProvider`, `LocalMcpToolCatalog`)
- README updated to note `src/Example/` is a reference implementation, not a dependency target
- Roadmap updated with Phase 40 entry

## Phase 41: v1.0.0 Tagging and Readiness

Goal: complete the v1.0.0 release criteria and tag the first stable release.

- ADR 0009 gap fixes: `HtmlResponseFactory` and `TemplateNotFoundException` moved to correct `Nene2\View` namespace in the stable list; `ResponseEmitter` added
- PHPDoc on all public interfaces and extension points in the stable surface
- `docs/milestones/2026-05-v1.0.md` — v1.0 tagging criteria documented
- CHANGELOG heading note ("Breaking changes may occur until v1.0.0") removed
- `v1.0.0` tag

Tracked by Issue `#340`.

## Phase 44: DB Health Check

Goal: extend the `GET /health` endpoint with an optional, dependency-injected database connectivity check so operators can verify that the application can reach its database, without coupling the framework core to any specific adapter.

- `HealthCheckInterface { name(): string; check(): bool; }` — stable public interface in `Nene2\Http` or a new `Nene2\Health` namespace
- `RuntimeApplicationFactory` accepts `list<HealthCheckInterface> $healthChecks = []` (default empty → current behavior unchanged)
- `GET /health` response extended: `{"status":"ok","checks":{"database":"ok"}}` (503 + `"degraded"` when any check fails)
- `DatabaseHealthCheck` example in `src/Example/` as a reference implementation
- OpenAPI schema for the extended health response
- PHPUnit tests: healthy path, degraded path, empty checks

## Phase 45: Rate Limiting

Goal: add production-grade rate limiting to the middleware pipeline with a storage-backend abstraction so adopters can swap from in-memory to Redis or another store without touching framework code.

- ADR 0010: rate limiting design decisions (algorithm, header conventions, storage abstraction)
- `RateLimitStorageInterface` — stable public interface; get / increment / ttl operations
- `InMemoryRateLimitStorage` — `@internal` default for local development and testing
- `ThrottleMiddleware` — PSR-15 middleware; position 8 (after Auth, before routing)
  - 429 Problem Details response with `type: https://nene2.dev/problems/too-many-requests`
  - `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers
- `RuntimeApplicationFactory` accepts an optional `ThrottleMiddleware` (not wired by default)
- PHPUnit tests: under limit, at limit, over limit, reset after window

## Phase 46: Field Trial 8 — v1.0 Stable Validation

Goal: validate the v1.0 stable API contract and the new production features in a realistic project scenario starting from `composer require hideyukimori/nene2:^1.0`.

- Start a new project directory with `composer require hideyukimori/nene2:^1.0`
- Verify the stable surface (Router, RuntimeApplicationFactory, interfaces) works as documented
- Wire `DatabaseHealthCheck` into `GET /health` and confirm the degraded path
- Wire `ThrottleMiddleware` + `InMemoryRateLimitStorage` and trigger the 429 path
- Record friction and open follow-up Issues

## Phase 47: v1.1.0 Release

Goal: consolidate Phase 44–46 additions into a versioned release.

- CHANGELOG.md v1.1.0 section (DB Health Check, Rate Limiting, Field Trial 8 findings)
- ADR 0010 finalized
- `v1.1.0` tag

Tracked by `docs/milestones/2026-05-v1.1.md`.

## Phase 48: JsonRequestBodyParser — 400/422 分離 (#354)

Goal: make the boundary between malformed JSON (400) and structurally valid but semantically
invalid JSON (422) explicit, so clients can distinguish parse errors from validation errors.

- `JsonBodyParseException` added to stable `Nene2\Http` surface
- `JsonRequestBodyParser::parse()` — throws on empty body, syntactically invalid JSON, or
  non-object JSON (array / string / number / null)
- `ErrorHandlerMiddleware` maps `JsonBodyParseException` → `400 invalid-json` Problem Details
- `CreateNoteHandler`, `UpdateNoteHandler`, `CreateTagHandler`, `UpdateTagHandler` migrated to
  use `JsonRequestBodyParser::parse()` instead of raw `json_decode`
- 7 unit tests in `JsonRequestBodyParserTest`; 3 new 400-path cases in `NoteHttpTest`

## Phase 49: v1.2.0 Release (#356)

Goal: consolidate Phase 48 additions into a versioned release.

- CHANGELOG.md v1.2.0 section (Phase 48 — JsonRequestBodyParser)
- Roadmap and TODO updated
- `v1.2.0` tag

## Phase 51: Pagination Helper (#360)

Goal: extract the duplicated `?limit=&offset=` parsing logic from collection handlers into a
reusable `PaginationQuery` / `PaginationQueryParser` pair in the stable `Nene2\Http` surface.

- `PaginationQuery` — readonly DTO holding `limit: int` and `offset: int`
- `PaginationQueryParser::parse()` — parses and validates query params, throws `ValidationException` on
  out-of-range values; accepts `$defaultLimit` and `$maxLimit` parameters
- `ListNotesHandler` and `ListTagsHandler` migrated to use the parser (duplicate logic removed)
- 9 unit tests in `PaginationQueryParserTest`

## Phase 52: OpenAPI → Markdown Generation (#362)

Goal: add a CLI script (`tools/openapi-to-md.php`) that reads `docs/openapi/openapi.yaml` and
generates `docs/reference/http-endpoints.md`, keeping the English Reference page in sync with the
API contract automatically.

- `tools/openapi-to-md.php` — reads YAML, outputs Markdown endpoint tables grouped by domain
- `composer openapi:docs` script added
- `openapi.yaml` extended with `InvalidJson` (400) and `TooManyRequests` (429) response components
- POST/PUT endpoints reference the new `InvalidJson` response (Phase 48 alignment)

## Phase 53: v1.3.0 Release

Goal: consolidate Phase 51–52 additions into a versioned release.

- CHANGELOG.md v1.3.0 section
- `v1.3.0` tag

## Phase 54: VitePress v1.3.0 Update (#366)

Goal: update the VitePress documentation site for the v1.3.0 release.

- Version badge updated to `v1.3.0`
- `add-pagination` HOWTO page added in 6 languages (covers `PaginationQueryParser` usage)
- `backend.yml` CI step added: `composer openapi:docs && git diff --exit-code` ensures
  `http-endpoints.md` stays in sync with `openapi.yaml`

## Phase 55: Dependabot Dependency Updates

Goal: merge pending Dependabot PRs to keep dependencies current.

- 10 Dependabot PRs merged (#324–#333): phpunit, symfony/yaml, php-cs-fixer, GitHub Actions
  (cache/upload-pages-artifact/deploy-pages), typescript-eslint, eslint, @vitejs/plugin-react, vite
- All PRs verified CI green before merging

## Phase 56: Field Trial 9 — v1.3.0 Validation

Goal: validate v1.3.0 new features in Docker environment.

- `PaginationQueryParser` — default, explicit limit/offset, and all 422 error paths
- `JsonRequestBodyParser` — 400/422 distinction confirmed
- `composer openapi:docs` — runs clean, no diff against committed file
- Full test suite passes (199 tests, 630 assertions)
- Friction found: PHPStan OOM in Docker (#371), GitHub Release missing from release flow (#372)

## Phase 57: VitePress i18n Link Fix (#380)

Goal: fix nav/sidebar links returning to English when navigating from a non-English locale.

- Root cause: VitePress does not auto-prefix locale path on relative `link:` values in nav/sidebar; the
  links were resolving to root (English) instead of the active locale
- `nav()` and `sidebar()` functions extended with a `p: string = ''` locale-prefix parameter
- All `link:` values rewritten as absolute paths using `${p}/...`
- Each locale call passes its prefix (`'/ja'`, `'/fr'`, `'/zh'`, `'/pt-br'`, `'/de'`); English uses `''`
- ADR cross-references in 5 locale `add-rate-limiting.md` files changed from broken relative path
  `../adr/0010-rate-limiting.md` to absolute `/adr/0010-rate-limiting` (ADRs are English-only per language policy)

## Phase 58: Field Trial 10 — New Domain Entity from Scratch (#404)

Goal: validate that the v1.4.0 scaffold workflow is sufficient to build a third domain entity
from scratch without referencing the existing Note/Tag implementation as a codebase crutch.

Project: **hoplog** — クラフトビールテイスティングノート JSON API (`hideyukimori/nene2: ^1.4`,
PHP 8.4, 3 domains / 15 routes, PHPUnit 18/18, PHPStan level 8, CS-Fixer clean).

- Implemented Brewery / Beer / TastingNote domains with full CRUD + list pagination
- All quality checks passed (`composer check`)
- Recorded 9 friction points in `docs/field-trials/2026-05-field-trial-10.md`
- Opened follow-up Issues #423–#429 for each friction point

Tracked by `docs/milestones/2026-05-field-trial-10.md`.

## Phase 59: Problem Details Base URL Configurability (#409)

Goal: allow client projects to use their own domain for custom Problem Details `type` URIs,
while keeping `nene2.dev` as the default for all framework built-in error types.

Background: `nene2.dev` is the registered framework domain (confirmed). Framework-built-in
problem types (`not-found`, `validation-failed`, etc.) resolve correctly under this domain.
The remaining gap is that `ProblemDetailsResponseFactory` hardcodes the base URL, so
client-specific custom problem types also point to `nene2.dev`.

- Add `problemDetailsBaseUrl` to `AppConfig` (default: `https://nene2.dev/problems/`)
- `ProblemDetailsResponseFactory` receives the base URL via constructor injection
- `RuntimeServiceProvider` wires the value from `AppConfig`
- `.env.example` gets a commented-out `PROBLEM_DETAILS_BASE_URL` entry
- `docs/development/client-project-start.md` or `docs/howto/deploy-production.md` documents
  how to override the base URL for a client project's custom problem types

## Phase 60: FT10 Follow-up — High/Medium Priority Docs & Fixes (#423–#425, #427–#428)

Goal: address the highest-impact documentation gaps and one design fix found in Field Trial 10.

- #423: `Router::PARAMETERS_ATTRIBUTE` をハンドラリファレンスに追記（F-2 高）
- #424: SQLite アダプター使用時の環境変数ドキュメント追記（F-4 docs 高）
- #425: SQLite アダプター時に `DB_HOST` / `DB_USER` / `DB_CHARSET` バリデーション免除（F-4 design 高）
- #427: `ContainerBuilder::set()` 後勝ちルール・`ValidationException` 構築例・PHP-CS-Fixer `--allow-risky=yes` 追記（F-1/F-7/F-9）
- #428: php:8.4-cli ベースの推奨 Dockerfile を How-to に掲載（F-3）

## Phase 61: FT10 Follow-up — Feature Improvements (#426, #429)

Goal: address the feature-level improvements found in Field Trial 10.

- #426: `APP_DEBUG=true` 時に例外メッセージを `detail` またはデバッグログに出力（F-5 高）
- #429: ページネーションレスポンスの `total` フィールド対応を設計・実装（F-8）

## Phase 62: Field Trial 11 — JWT 認証フローの AI 実装可能性検証 (#434) ✅

Goal: validate that Claude can implement a full JWT authentication flow using only NENE2
documentation, and record friction points in the authentication ergonomics area.

Theme: **Authentication Ergonomics** — JWT Bearer setup, middleware wiring, user-scoped
resource access, and 401/403 response patterns.

App: **tasklog** — タスク管理 API (`composer require hideyukimori/nene2:^1.4` から 0 構築)

- [x] User domain: register, login, JWT issuance
- [x] Task domain: CRUD + pagination, protected by Bearer middleware
- [x] Record all friction points (F-1〜F-4): hideyukiMORI/tasklog docs/field-trial-report.md
- [x] Open follow-up Issues: #440 (F-1), #441 (F-2), #442 (F-3), #443 (F-4)
- Result: PHPUnit 12/12, PHPStan level 8 clean, PHP-CS-Fixer clean

## Phase 63: FT11 フォローアップ — BearerTokenMiddleware パスマッチング改善 (#440, #441)

Goal: address the highest-impact friction points from Field Trial 11.

- #440: Add prefix-match or excluded-paths option to BearerTokenMiddleware
- #441: Relax RuntimeApplicationFactory auth parameter type to MiddlewareInterface
- Optional: add-jwt-authentication.md how-to guide (covers F-1〜F-3 workarounds)

## Phase 65: Field Trial 12-A — 多対多リレーションの AI 実装可能性検証 (#453) ✓

Goal: validate that Claude can implement many-to-many relationships using only NENE2
documentation, and record friction points in the relational data design area.

Theme: **Relational Data Ergonomics** — JOIN queries, cascade deletes, M:N pagination,
and tag-based filtering.

App: **tagmark** — ブックマーク管理 API (`composer require hideyukimori/nene2:^1.4` から 0 構築)

- [x] User domain (reuse FT11 JWT auth pattern)
- [x] Bookmark / Tag domains with M:N join table
- [x] Tag assignment/removal, tag-based filtering, cascade deletes (19 tests, 45 assertions)
- [x] 7 friction points recorded (F-1〜F-7); F-1/F-2/F-7 resolved in v1.4.1; follow-up Issue #457

## Phase 66: Field Trial 12-B — MCP ファースト（Knowledge Base API）(#454) ✓

Goal: validate MCP tool authoring ergonomics — docs/mcp/tools.json authoring, safety levels,
and the read/write tool workflow.

App: **knowledgelog** — FAQ / document knowledge base API

- [x] Article / Category domains, API key protection, 8 endpoints (10 tests, 30 assertions)
- [x] 7 MCP tools (3 read + 4 write), OpenAPI spec, MCP validation passes
- [x] 5 friction points recorded (F-1〜F-5); F-2/F-3 resolved in PR #464; follow-up Issues #459–#463

## Phase 67: Field Trial 12-C — 公開 + 管理者の二層アクセス（Product Catalog API）(#455) ✓

Goal: validate multi-auth ergonomics — API key + JWT Bearer + public access coexisting
in one application.

App: **shoplog** — product catalog API with 3-tier access model

- [x] Product / Category / Favorite domains, 3-tier access model, 12 endpoints (29 tests)
- [x] MultiAuthMiddleware pattern: Bearer for /me/*, WriteApiKeyMiddleware for writes
- [x] 6 friction points recorded (F-1〜F-6); F-2/F-5 resolved in PR #470; follow-up Issues #466–#469

## Phase 68: Field Trial 13 — MySQL + Phinx マイグレーション（Event Management API）(#472) ✓

Goal: validate database ergonomics — MySQL setup, Phinx migration authoring,
DatabaseConfig wiring, and production-like environment from a consumer project.

App: **eventlog** — イベント管理 API (MySQL + Phinx + M:N + Multi-Auth)

Result: 14/14 PHPUnit, PHPStan level 8, PHP-CS-Fixer 全通過。摩擦 5 件（F-1〜F-5）。
F-2/F-3/F-4 フォローアップ Issues #474–#476 → docs PR で解消。

## Phase 69: Field Trial 14 — CompositeAuthMiddleware + M:N + 3 層アクセスモデル（postboard）(#479) ✓

Goal: validate `CompositeAuthMiddleware` ergonomics in a real consumer project.
Three-tier auth (public / Bearer / API key), M:N (Post ↔ Tag), SQLite.

App: **postboard** — 投稿ボード API (CompositeAuthMiddleware + M:N + JWT + API Key)

Result: 31/31 PHPUnit, PHPStan level 8, PHP-CS-Fixer 全通過。摩擦 4 件（F-1〜F-4）。
F-1 最重要: CompositeAuthMiddleware が v1.4.1 未収録 → v1.4.2 リリースで解消（Issue #481）。

## Phase 70: Howto Library Sprint — Phase I: Documentation Catch-up ✓

Goal: backfill howto guides for FT96–FT120, covering patterns implemented before
the sprint formally began, to ensure every field trial has a self-contained guide.

- `docs/howto/` guides for content negotiation, SQL injection, file upload, CSRF/idempotency,
  pagination, nested JSON validation, transactions, mass assignment, webhook signature,
  optimistic locking, ETag, rate limiting, soft delete, password hashing, JWT auth, RBAC,
  multi-tenant isolation, JWT refresh token rotation, audit trail, API versioning,
  background job queue, API key management, signed URLs, circuit breaker, outbound webhook delivery
- Each guide links to field trial report and reference implementation

Tracked by `docs/milestones/2026-05-howto-library-sprint.md`.

## Phase 71: Howto Library Sprint — Phase II: Implementation Sprint ✓

Goal: run 36 full field trial implementations (FT121–FT156) with code, tests, and howto guides,
maintaining security cadence throughout.

- Feature flags, distributed locking, personal data export, user invitation, tagging (M:N),
  password reset, threaded comments, account lockout, event sourcing, notification inbox,
  comment voting, user profile, bookmarks, user follow, direct messaging, access token management,
  subscription management, group membership, guest order, flash sale, leaderboard,
  content draft lifecycle, emoji reactions, passwordless auth (Magic Link), user preferences,
  content pinning, content reporting/moderation, OTP auth, content collections, coupon management,
  wishlist, points/loyalty, activity feed, product reviews, shopping cart, file metadata sharing
- Reached **100 howto guides** and **v1.5.104**
- Security coverage: 13 vulnerability assessments + 12 cracker attack tests + 8 MySQL test sessions

Tracked by `docs/milestones/2026-05-howto-library-sprint.md`.

## Phase 72: Howto Library Sprint — Phase III: Library Completion ✅

Goal: cover the remaining core API patterns (FT157–FT170) to complete the initial howto library,
reaching ~100 self-contained guides with full test coverage and security assessments.

- Search & autocomplete, CSV bulk import, TOTP two-factor auth, OAuth2/social login pattern,
  application caching, content versioning, payment webhook, geolocation queries, A/B testing,
  multi-step workflow (state machine), inbound webhook receiver, admin report aggregation,
  data masking (PII), request deduplication
- Security cadence: vuln assessment at FT159/FT169, cracker attack at FT160/FT164/FT168/FT170
- MySQL integration tests at FT158/FT167
- **Completed** — v1.5.104, 100 howto guides

Tracked by `docs/milestones/2026-05-howto-library-sprint.md`.

## Phase 73: Howto Library Consolidation ✅

Goal: make the 100+ guide library navigable and publish it as a reference corpus.

- `docs/howto/README.md` full index with categories, search tags, and cross-links
- VitePress documentation site updated with all new howto pages
- Sidebar / nav restructured around pattern categories
  (Auth, Security, Database, API Design, Social, E-commerce, System Patterns)
- VitePress 全ロケールで howto 全 256 件を動的サイドバー生成（#1302）
- Tag the library milestone as **Howto Library v1**
- **Result**: 256 howto guides, 6 locales (en/ja/fr/zh/de/pt-br) で完全同期

## Phase 74: DX Trial Improvements v1.5.x ✅

Goal: convert the friction collected from DX Trial 01〜26 (78 試験) into shipped
public APIs, ADRs, and howto callouts so the next FT loop does not re-hit the
same gotchas.

- IMP-04 `DatabaseConfig::sqlite($path)` ファクトリ追加（#1304）
- IMP-07 `DatabaseConstraintException` の Packagist 公開状態確認 + TODO 整理（#1306）
- IMP-14 `Nene2\Testing\DatabaseTestKit` 新規 stable public API + ADR 0012（#1308）
- IMP-01 `Router::param()` を ADR 0009 stable API に正式記載（#1318）
- IMP-09/19/15/12/20/02/11/16: `add-database-endpoint.md` 一括追記（#1310）
- IMP-18/10/05: `use-transactions.md` 追記（#1312）
- IMP-13/17: PSR-15 namespace + `withParsedBody` バイパス trap（#1314）
- IMP-03/21: SQLite `FOR UPDATE` 不可 + PHP regex `$` デリミタ干渉（#1316）
- **Result**: 全 14 IMP 完了、ADR 0009 stable API 表に 3 surface 追加、新規 ADR 0012

## Phase 75: Framework Hardening — Installer, Fail-Closed Auth, Audit (v1.6.0–v1.7.0) ✅

Goal: harden the delivery path with opt-in, generic building blocks that stay dormant
until wired, without baking product-specific assumptions into the core.

- **v1.6.0** — opt-in installer toolkit `Nene2\Install` for shared-hosting setup: payload
  security core, preflight & config, `TenantConfigurationValidator`, `DatabaseSchemaApplier`
  (programmatic Phinx), release manifest / `ReleaseSource`, presentation contracts, and an
  unbranded reference renderer (`src/Install/`). First consumer: NeNe Invoice.
- **v1.7.0** — fail-closed JWT secret resolution `Nene2\Auth\GuardedJwtSecretResolver`
  (ADR 0013): production never adopts a development signing key, an empty secret is treated
  as unset, and installer fields gained input types (`InstallerInputType` / `InstallerField`)
  so password inputs are never echoed back to the page.
- **Unreleased** — audit logging base `Nene2\Audit` (ADR 0014): `AuditEvent` / `AuditQuery`
  value objects, append-only `AuditEventRepositoryInterface`, and a default recorder that binds
  audit rows into the same transaction as the business mutation they describe. Product-side
  migration off hand-rolled audit logs is deferred to separate PRs.
- **Result**: shipped v1.6.0 and v1.7.0; audit base landed on `main` (Unreleased).

## Phase 76: v2.0 Design and Framework Evolution

Goal: review the friction and gaps accumulated across FT96–FT352 and decide
what moves into the framework core vs. remains as howto-only patterns.

- Collect top-friction patterns from field trial reports
- ADR for each framework-level change candidate
- Identify stable surface additions that the FT loop has validated
- Draft v2.0 breaking change scope (if any)
- Consumer project validation using `composer require hideyukimori/nene2:^2.0`

## Non-Goals

- Recreating Laravel or Symfony.
- Hiding application behavior behind magic conventions.
- Coupling the framework to one frontend library.
- Making a full template engine a default dependency.
- Treating AI integration as direct database or filesystem access.
