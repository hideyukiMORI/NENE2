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

## Non-Goals

- Recreating Laravel or Symfony.
- Hiding application behavior behind magic conventions.
- Coupling the framework to one frontend library.
- Making a full template engine a default dependency.
- Treating AI integration as direct database or filesystem access.
