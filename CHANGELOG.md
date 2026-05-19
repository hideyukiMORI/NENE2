# Changelog

All notable changes to NENE2 are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.5.0] — 2026-05-20

### Added
- `ApiKeyAuthenticationMiddleware::$protectedPathPrefixes` — prefix allowlist parameter; protects paths starting with any listed prefix (e.g. `/admin/` matches `/admin/users/42`). Evaluated only when `$protectedPaths` is empty, mirroring `BearerTokenMiddleware` behaviour. (#461, #482)
- `ApiKeyAuthenticationMiddleware::$protectedMethods` — method filter; when non-empty, only requests whose HTTP method is in the list are protected. Enables "GET is public, POST/DELETE require API key" patterns without a custom middleware. (#461, #482)
- `ExampleServiceProvider` (`Nene2\Example`) — bundles Note and Tag example services and exposes `ROUTE_REGISTRARS` / `EXCEPTION_HANDLERS` string keys so framework infrastructure code can wire example routes without importing individual example classes (#463)
- `LocalMcpToolCatalog` — `is_file()` guard before `file_get_contents()` to avoid PHP warnings on missing catalog files
- `docs/adr/0009`: `nene2.auth.credential_type` and `nene2.auth.claims` request attributes documented as part of the stable public API (#462)
- `docs/howto/add-html-view.md` — new howto: server-rendered HTML with `NativePhpViewRenderer` and `HtmlResponseFactory` (#487)
- `docs/howto/add-mcp-tools.md` — new howto: MCP tool catalog setup, read/write tools, JWT protection, MCP server startup, Claude Code/Desktop config (#489)
- `docs/howto/add-database-endpoint.md`: M:N many-to-many relationship section — join table schema, idempotent `attachTag`/`detachTag` repository pattern, MySQL `INSERT IGNORE` note (#457)
- `docs/howto/add-custom-route.md`: "Reserved framework paths" section — lists built-in paths (`/`, `/health`, etc.) that cannot be overridden by user route registrars (#493)
- `docs/development/quality-tools.md`: PHPStan `memory_limit` guidance updated — `memory_limit` in `phpstan.neon` is not supported in PHPStan 2.x; use `--memory-limit` CLI flag instead (#469, #493)

### Improved
- `tests/View/NativePhpViewRendererTest`: 5 → 13 tests — added boundary cases for `HtmlEscaper` (null, numeric types, empty string, multibyte UTF-8), `HtmlResponseFactory` (default status, multiple headers), and `NativePhpViewRenderer` (exception buffer cleanup, subdirectory resolution) (#486)
- `tests/Mcp/LocalMcpToolCatalogTest`: 3 → 15 tests — added error cases (file not found, invalid JSON, missing keys), safety level variants, method uppercasing, and `responseSchemaRef` handling (#489)

### Field Trials (FT15 · FT16)
- **FT15** — noteboard (HTML View): `NativePhpViewRenderer` + `HtmlResponseFactory` — 7 endpoints, 10/10 tests, PHPStan level 8, PHP-CS-Fixer clean. Frictions: F-1 `GET /` reserved (→ add-custom-route.md), F-2 `Router::PARAMETERS_ATTRIBUTE` usage, F-3 PHPStan neon `memory_limit` (→ quality-tools.md)
- **FT16** — noteboard MCP: 3 MCP tools (2 read + 1 write) added to FT15 app. `composer mcp --root=.` validates cleanly with v1.4.2+. No friction — `add-mcp-tools.md` howto works as written.

---

## [1.4.2] — 2026-05-19

### Added
- `validate-mcp-tools.php` — `--root=<path>` CLI option; falls back to `getcwd()` so consumer projects can run the validator without a wrapper script (#459)
- `composer.json` `suggest` entry for `symfony/yaml` — consumer projects are guided to add it when using the MCP validator (#460)
- CLAUDE.md section on how to wire the MCP validator in a consumer project
- `BearerTokenMiddleware::$protectedPathPrefixes` — prefix allowlist parameter; protects all paths starting with the listed prefixes (e.g. `/me/` matches `/me/favorites/42`); evaluated after `$protectedPaths` and before `$excludedPaths` (#467)
- `CompositeAuthMiddleware` — chains multiple `MiddlewareInterface` instances as a single auth middleware; enables three-tier access models (public / Bearer / API key) by composing path-scoped middlewares in order (#466, #481)

### Changed
- `LocalBearerTokenVerifier` — removed `@internal` tag; now part of the public API stability guarantee (see ADR 0009) (#468)

---

## [1.4.1] — 2026-05-19

### Added
- `FrameworkInfo::VERSION` — public string constant exposing the current framework version (`Nene2\FrameworkInfo`) (#417)
- `BearerTokenMiddleware::$excludedPaths` — blocklist parameter to skip authentication on specific paths (e.g. `/auth/register`, `/auth/login`); complements the existing `$protectedPaths` allowlist (#440)
- `TokenIssuerInterface` — public stable interface for JWT issuance (`Nene2\Auth`); `LocalBearerTokenVerifier` now implements it (#442)
- `add-jwt-authentication` how-to guide in 6 languages (#449)

### Changed
- `RuntimeApplicationFactory` — `$bearerTokenMiddleware: ?BearerTokenMiddleware` parameter renamed to `$authMiddleware: ?MiddlewareInterface`; accepts any PSR-15 middleware, not only `BearerTokenMiddleware` (#441)

### Fixed
- `add-jwt-authentication.md` — corrected `DomainExceptionHandlerInterface` method name (`handles` → `supports`), `handle()` signature (2 params, not 3), request body parsing (`JsonRequestBodyParser::parse()` instead of `getParsedBody()`), and handler return type note (FT12-A findings F-3/F-4/F-5)

---

## [1.4.0] — 2026-05-18

### Added
- `AppConfig::$problemDetailsBaseUrl` — configurable base URL for Problem Details `type` URIs (`Nene2\Config`); defaults to `https://nene2.dev/problems/` (#409)
- `PROBLEM_DETAILS_BASE_URL` environment variable — override the base URL per project without touching framework code (#409)
- `.env.example` entry for `PROBLEM_DETAILS_BASE_URL` (commented out) (#409)

### Changed
- `ProblemDetailsResponseFactory` — accepts `string $problemDetailsBaseUrl` as a third constructor parameter; existing call sites that omit it continue to use the `nene2.dev` default (#409)
- `RuntimeServiceProvider` — wires `AppConfig::$problemDetailsBaseUrl` into `ProblemDetailsResponseFactory` (#409)

---

## [1.3.0] — 2026-05-18

### Added
- `PaginationQuery` readonly DTO — holds validated `limit` and `offset` values (`Nene2\Http`) (#360)
- `PaginationQueryParser::parse()` — parses and validates `?limit=&offset=` query parameters; throws `ValidationException` on out-of-range values; accepts `$defaultLimit` and `$maxLimit` parameters (`Nene2\Http`) (#360)
- `tools/openapi-to-md.php` — generates `docs/reference/http-endpoints.md` from `docs/openapi/openapi.yaml`; runnable via `composer openapi:docs` (#362)
- `InvalidJson` and `TooManyRequests` response components added to `openapi.yaml`; POST/PUT endpoints now reference the 400 `InvalidJson` response (#362)
- `public_html/problems/invalid-json/` and `public_html/problems/too-many-requests/` human-readable Problem Details type pages (#362)

### Changed
- `ListNotesHandler` and `ListTagsHandler` — replaced duplicated limit/offset parsing logic with `PaginationQueryParser::parse()` (#360)

---

## [1.2.0] — 2026-05-18

### Added
- `JsonRequestBodyParser` and `JsonBodyParseException` — parse request body as JSON object; throws on empty body, invalid JSON, or non-object JSON (`Nene2\Http`) (#354)
- `ErrorHandlerMiddleware` maps `JsonBodyParseException` → `400 invalid-json` Problem Details, distinct from `422 validation-failed` (#354)

### Changed
- `CreateNoteHandler`, `UpdateNoteHandler`, `CreateTagHandler`, `UpdateTagHandler` — replaced `(array) json_decode(...)` with `JsonRequestBodyParser::parse()` (#354)

---

## [1.1.0] — 2026-05-18

### Added
- `HealthCheckInterface` and `HealthStatus` — stable public interface for dependency health checks (`Nene2\Http`) (#346)
- `RuntimeApplicationFactory` extended with `list<HealthCheckInterface> $healthChecks = []`; `GET /health` returns `checks` map and 503 when any check reports `error` (#346)
- `DatabaseHealthCheck` reference implementation in `src/Example/Health/` (#346)
- OpenAPI `HealthResponse` schema extended with optional `checks` field; `503` response added to `/health` path (#346)
- ADR 0010: rate limiting design — fixed window, IP-keyed by default, `RateLimitStorageInterface` as storage abstraction (#348)
- `RateLimitStorageInterface` — stable public interface for rate limit counter storage (`Nene2\Middleware`) (#348)
- `InMemoryRateLimitStorage` (`@internal`) — fixed-window in-memory storage for local development and testing (#348)
- `ThrottleMiddleware` — PSR-15 middleware; 429 Problem Details + `Retry-After` + `X-RateLimit-*` headers; position 8 after Auth (#348)
- `RuntimeApplicationFactory` extended with optional `ThrottleMiddleware $throttleMiddleware = null` (#348)

---

## [1.0.0] — 2026-05-18

### Added
- ADR 0009 v1.0 readiness: `ResponseEmitter` added to stable surface; `HtmlResponseFactory` and `TemplateNotFoundException` moved to correct `Nene2\View` namespace in stable list (#340)
- PHPDoc on stable public interfaces: `ServiceProviderInterface`, `DomainExceptionHandlerInterface`, `DatabaseConnectionFactoryInterface`, `ResponseEmitter` (#340)
- `docs/milestones/2026-05-v1.0.md` — v1.0 tagging criteria documented (#340)

### Changed
- Public API stability contract in effect: surfaces listed in ADR 0009 will not have breaking changes without a major version bump
- `src/Example/` explicitly outside the stability guarantee (reference implementation)

---

## [0.8.0] — 2026-05-18

### Added
- ADR 0009: v1.0 public API scope and stability guarantee — `src/Example/` declared as reference implementation outside the stability promise (#334)
- `@internal` annotations on implementation-detail classes: `ConfigLoader`, `PdoConnectionFactory`, `PdoDatabaseQueryExecutor`, `PdoDatabaseTransactionManager`, `RuntimeServiceProvider`, `RuntimeContainerFactory`, `MonologLoggerFactory`, `RequestIdHolder`, `RequestIdProcessor`, `LocalMcpToolCatalog`, `LocalBearerTokenVerifier`, `NativeLocalMcpHttpClient`, `LocalMcpHttpResponse` (#334 #337)
- `PdoTagRepositoryMySqlTest` — MySQL integration tests for Tag repository (save / findAll / update / delete, 7 cases) (#312)
- Frontend Tag support: `frontend/src/api/tags.ts` typed fetch wrapper, `TagList` and `TagForm` React components with live API integration (#314)
- Frontend component tests for `TagList` and `TagForm` (#321)
- Frontend API type-guard tests for `tags.ts` (#321)

### Changed
- README: `src/Example/` documented as reference implementation not covered by the stability guarantee (#334)
- CLAUDE.md § 5 middleware order corrected to match `RuntimeApplicationFactory` implementation: `RequestId → Logging → Security → CORS → Error → RequestSize → Auth` (#337)
- ADR 0008 middleware placement table corrected to match implementation (#337)
- Roadmap: Phase 38–39–40–41 entries added (#334 #337)

---

## [0.7.0] — 2026-05-18

### Added
- `PUT /examples/tags/{id}` — full tag update endpoint; replaces `name` field (#304)
- `DELETE /examples/tags/{id}` — tag delete endpoint; returns 204 (#304)
- `UpdateTagUseCase`, `UpdateTagHandler`, `UpdateTagInput/Output`, `UpdateTagUseCaseInterface` (#304)
- `DeleteTagUseCase`, `DeleteTagHandler`, `DeleteTagByIdInput`, `DeleteTagUseCaseInterface` (#304)
- `TagRepositoryInterface::update(Tag): void` and `delete(int): void` implemented in `PdoTagRepository` and `InMemoryTagRepository` (#304)
- 5 Tag MCP tools in `docs/mcp/tools.json`: `listExampleTags`, `getExampleTagById`, `createExampleTag`, `updateExampleTagById`, `deleteExampleTagById` — Tag/Note MCP parity restored (#304)
- `database/migrations/20260516000001_create_tags_table.php` — Phinx migration for the `tags` table (#306)
- `database/schema/tags.sql` — schema snapshot for the `tags` table (#306)
- Endpoint scaffold checklist: added "create migration" step for new entity tables (#307)

### Changed
- `TagRouteRegistrar` extended from 3 to 5 routes and constructor params (#304)
- `TagServiceProvider` registers `UpdateTagHandler`, `DeleteTagHandler`, and their use cases; registrar closure updated (#304)
- `docs/howto/add-second-entity.md` — Tag endpoint table corrected to reflect full CRUD

---

## [0.6.0] — 2026-05-17

### Added
- `src/Example/Note/NoteRouteRegistrar` — registers Note routes via `__invoke(Router): void`; used by `NoteServiceProvider` and directly in tests (#299)
- `src/Example/Tag/TagRouteRegistrar` — registers Tag routes via `__invoke(Router): void`; used by `TagServiceProvider` and directly in tests (#299)
- `LocalMcpHttpClientInterface::hasAuthentication(): bool` — write tools are now gated: calling a `safety: write` tool without `NENE2_LOCAL_JWT_SECRET` set returns a `LocalMcpException` with a clear setup message (#301)
- `docs/howto/add-second-entity.md` — HOWTO for adding a second domain entity using the RouteRegistrar pattern, in 6 languages (en/ja/fr/zh/pt-br/de) (#303)
- VitePress HOWTO sidebar updated with "Add a second entity" entry in all 6 locales (#303)

### Changed
- `RuntimeApplicationFactory` constructor reduced from 16 parameters to 8 — entity-specific handler parameters removed; all entity routes are now contributed via the `$routeRegistrars` parameter (#299)
- `NoteServiceProvider` / `TagServiceProvider` each register a `nene2.route_registrar.*` service; `RuntimeServiceProvider` references registrars by key instead of passing individual handlers (#299)
- `NativeLocalMcpHttpClient` implements `hasAuthentication()` based on bearer token presence (#301)
- `tools/local-mcp-server.php` token scope updated from `read:system` to `read:system write:example` (#301)
- VitePress version badge updated to `v0.6.0` (#303)

---

## [0.5.0] — 2026-05-17

### Added
- Diátaxis Explanation pages in 6 languages (English, Japanese, French, Chinese, Portuguese, German): `why-psr`, `why-explicit-wiring`, `why-problem-details`, `why-mcp` (#275)
- Second domain entity example: `src/Example/Tag/` with full CRUD (#277)
  - `GET /examples/tags`, `POST /examples/tags`, `GET /examples/tags/{id}`
  - `ListTagsUseCase`, `GetTagByIdUseCase`, `CreateTagUseCase` with readonly DTOs
  - `PdoTagRepository`, `InMemoryTagRepository`, `TagServiceProvider`
  - OpenAPI schemas and 8 HTTP-level tests
- Production deployment guide (`docs/howto/deploy-production.md`) with multi-stage `Dockerfile.prod`, env management, Nginx/Caddy reverse proxy examples, security checklist, and post-deploy verification in 6 languages (#279)
- Frontend starter content: `NoteList` and `NoteForm` React components with live API integration (#281)
  - Typed fetch wrapper `frontend/src/api/notes.ts` for all Note endpoints
  - `LoadState` union type pattern for loading / error / ok states
  - `App.tsx` wired to refresh the list on note creation
- `src/Auth/BearerTokenMiddleware` — PSR-15 middleware for `Authorization: Bearer` tokens; returns RFC 6750-compliant 401 Problem Details on failure (#283)
- `src/Auth/TokenVerifierInterface` — pluggable token verifier; framework ships no concrete JWT library dependency (#283)
- `src/Auth/TokenVerificationException` — thrown by verifier implementations on any failure (#283)
- ADR 0008: JWT authentication direction — library-agnostic design, `firebase/php-jwt` as recommended starting point (#283)
- VitePress 1.6.4 multilingual documentation site with 6 locales, Explanation nav/sidebar, and production deployment sidebar entry (#275)

### Changed
- CI `backend.yml`: explicit PHP extensions (`pdo`, `pdo_sqlite`, `pdo_mysql`) and Composer dependency cache (#273)
- CI `docs.yml`: `cache-dependency-path: package-lock.json` added to setup-node step (#273)
- `docs/development/authentication-boundary.md`: JWT section with `firebase/php-jwt` wiring example, request attribute table, and failure response specification (#283)
- `docs/adr/index.md`: ADR 0008 entry added (#283)

---

## [0.4.0] — 2026-05-17

### Added
- `RuntimeApplicationFactory` accepts `$routeRegistrars` — an optional `list<callable(Router): void>` for injecting custom routes without subclassing (#236)
- Local MCP server now executes write operations (`POST`, `PUT`, `DELETE`) through the documented API boundary (#228)
  - `LocalMcpHttpClientInterface` extended with `post()`, `put()`, `delete()` methods
  - `NativeLocalMcpHttpClient` implements the new methods with JSON body support
  - `LocalMcpServer` routes tools to the correct HTTP method; path parameters are interpolated from arguments; GET query arguments are appended as a query string
  - `LocalMcpToolCatalog` now exposes all tools (not only `read`); `responseSchemaRef` is nullable

---

## [0.3.0] — 2026-05-17

### Added
- Monolog `RequestIdProcessor`: attaches `X-Request-Id` as `extra.request_id` to every log record (#216)
  - `RequestIdHolder` (mutable singleton) — middleware writes the ID, processor reads it
  - `RequestIdMiddleware` accepts optional `RequestIdHolder` injection
- ADR 0007: PUT vs PATCH policy for resource update operations (#218)
- v0.3.0 readiness milestone with Packagist publication criteria review (#222)

### Changed
- README: added Domain Layer Example section linking `src/Example/Note/` as canonical reference (#217)
- README: Repository Layout updated with `src/Log/` and `src/Example/Note/`

---

## [0.2.0] — 2026-05-17

### Added
- `PUT /examples/notes/{id}` update endpoint — completes full Note CRUD (#212)
  - `UpdateNoteUseCase`, `UpdateNoteHandler`, `UpdateNoteInput/Output`, `UpdateNoteUseCaseInterface`
  - `NoteRepositoryInterface::update(Note): void` + PDO and in-memory implementations
  - `Router::put()` method
  - OpenAPI path with 200/404/422/500 responses
  - HTTP-level and use-case unit tests

---

## [0.1.3] — 2026-05-17

### Added
- Monolog 3.x structured JSON logging to stderr (`src/Log/MonologLoggerFactory`) (#206)
  - Log level driven by `APP_DEBUG`: `debug` when true, `warning` otherwise
  - ADR 0005 documents the decision
- `GET /examples/notes` collection endpoint with `limit`/`offset` pagination (#204)
  - `ListNotesUseCase`, `ListNotesHandler`, `ListNotesInput/Output/Item`
  - OpenAPI schema `ExampleNoteListResponse` with `items`, `limit`, `offset`
- HTTP-level integration tests for all Note endpoints (#203)
  - Uses `InMemoryNoteRepository` + full middleware stack
  - Covers GET/POST/DELETE success + error paths, collection pagination
- Error handler systemization: `DomainExceptionHandlerInterface` pluggable mapping (#197)
  - `NoteNotFoundExceptionHandler` maps `NoteNotFoundException` → 404 Problem Details
  - Domain handlers + middleware decouple exception handling from individual route handlers
- MySQL Docker Compose verification tests (#194)
- Setup guide (`docs/development/setup.md`) and MySQL Docker section (#196)
- `.env` copy step added to README Quick Start (#192)

### Changed
- `NoteRepositoryInterface` extended with `findAll(int $limit, int $offset): list<Note>` (#204)
- `GetNoteByIdHandler` and `DeleteNoteHandler` simplified (no longer inject `ProblemDetailsResponseFactory`) (#197)
- DB env vars (`DB_HOST`, `DB_PORT`, etc.) added to `compose.yaml` `app` service defaults (#194)

---

## [0.1.2] — 2026-05-14

### Added
- Note CRUD end-to-end: `POST /examples/notes`, `DELETE /examples/notes/{id}` (#190)
  - `CreateNoteUseCase`, `DeleteNoteUseCase`, readonly DTOs
  - OpenAPI paths for POST + DELETE with Problem Details schemas
- Domain layer policy documents and Phase 9 milestone (#182, #180)
- Local MCP smoke helper script (`tools/mcp-smoke.php`) (#178)
- Cursor/GitHub MCP authentication notes (#168)
- MCP integer path-parameter requirement documented (#167)

### Changed
- `NoteRepositoryInterface` extended with `save(Note): int` and `delete(int): void`
- `PdoNoteRepository` implements save (INSERT + lastInsertId) and delete (DELETE WHERE id)

---

## [0.1.1] — 2026-05-10

### Added
- API-key authentication middleware (`X-NENE2-API-Key` header) (#140)
- Local MCP server (`tools/local-mcp-server.php`) with read-only Note tools (#138)
- LLM delivery starter documentation and client project start guide (#134, #150)
- Machine-client smoke workflow and local MCP client config example (#154, #152)
- MySQL Docker Compose service and migration runner (#144)
- Endpoint scaffold workflow documentation (#142)
- v0.1.x patch release policy (#146)

---

## [0.1.0] — 2026-05-07

### Added
- PHP 8.4 HTTP runtime: PSR-7/15/17 via Nyholm + FastRoute (#43)
- PSR-11 DI container with explicit wiring and service providers (#43)
- RFC 9457 Problem Details error responses (`application/problem+json`) (#43)
- `GET /examples/notes/{id}` — first domain endpoint with use case pattern (#43)
- PHPUnit integration tests, PHPStan level 8, PHP-CS-Fixer (#43)
- OpenAPI contract (`docs/openapi/openapi.yaml`) + Swagger UI (#9)
- Docker Compose environment with MySQL service (#7)
- Phinx migration runner with `database/` layout (#13)
- PSR-3 logging boundary (NullLogger placeholder at this release)
- Governance docs: workflow, coding standards, ADR policy, review checklists (#1)
- ADR 0001–0004: HTTP runtime, DI container, phpdotenv, Phinx

[Unreleased]: https://github.com/hideyukiMORI/NENE2/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.4.2...v1.5.0
[1.4.2]: https://github.com/hideyukiMORI/NENE2/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/hideyukiMORI/NENE2/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/hideyukiMORI/NENE2/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.8.0...v1.0.0
[0.8.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/hideyukiMORI/NENE2/releases/tag/v0.1.0
