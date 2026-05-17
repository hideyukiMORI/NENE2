# Changelog

All notable changes to NENE2 are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** NENE2 is currently in `0.x.y`. Public contracts are still forming.
> Breaking changes may occur between minor versions until `v1.0.0`.

---

## [Unreleased]

### Added
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

[Unreleased]: https://github.com/hideyukiMORI/NENE2/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/hideyukiMORI/NENE2/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/hideyukiMORI/NENE2/releases/tag/v0.1.0
