# Changelog

All notable changes to NENE2 are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** NENE2 is currently in `0.x.y`. Public contracts are still forming.
> Breaking changes may occur between minor versions until `v1.0.0`.

---

## [Unreleased]

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

[Unreleased]: https://github.com/hideyukiMORI/NENE2/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/hideyukiMORI/NENE2/releases/tag/v0.1.0
