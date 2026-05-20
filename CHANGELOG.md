# Changelog

All notable changes to NENE2 are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.5.42] — 2026-05-21

### Added
- `docs/howto/soft-delete.md` — soft delete guide: `deleted_at` schema pattern, critical `WHERE deleted_at IS NULL` filter (missing it leaks deleted data), `$includeTrashed = false` default, `purge()` guard (trash-first requirement), `insert()` vs `execute()` + `lastInsertId()`, REST semantics note, foreign key considerations, code review checklist. (#718)
- `docs/field-trials/2026-05-field-trial-108.md` — FT108 report: softdeletelog (soft delete, 18 tests, 30 assertions, 6-persona DX review). (#718)

---

## [1.5.41] — 2026-05-21

### Added
- `docs/howto/rate-limiting.md` — rate limiting guide: `ThrottleMiddleware` setup (`throttleMiddleware` named parameter), `X-RateLimit-*` headers, 429 response format, IP-based vs custom `keyExtractor` (user, API key), reverse proxy `REMOTE_ADDR` warning (`X-Forwarded-For` trust), `InMemoryRateLimitStorage` production restriction, Redis `RateLimitStorageInterface` implementation pattern, fixed-window burst problem, client retry pattern, code review checklist. (#716)
- `docs/field-trials/2026-05-field-trial-107.md` — FT107 report: throttlelog (rate limiting, 9 tests, 33 assertions, 6-persona DX review). (#716)

---

## [1.5.40] — 2026-05-21

### Added
- `docs/howto/etag-conditional-requests.md` — ETag & conditional requests guide: ETag generation (double-quote requirement, entity method pattern), `ConditionalGetHelper` for 304 Not Modified, `ConditionalWriteHelper` for 412/428 precondition checks, `If-Match: *` wildcard semantics, `Last-Modified` ISO 8601 format requirement, ETag vs version field comparison, code review checklist. (#714)
- `docs/field-trials/2026-05-field-trial-106.md` — FT106 report: etaglog (ETag/conditional requests, 15 tests, 37 assertions, 6-persona DX review). (#714)

---

## [1.5.39] — 2026-05-21

### Added
- `docs/howto/optimistic-locking.md` — optimistic locking guide: lost-update problem scenario, version field schema, `WHERE version = ?` pattern with `execute()` affected-rows check, 0-rows disambiguation (not-found vs conflict), atomic `version = version + 1` in SQL, 409 response with `current_version`, client retry flow, optimistic vs pessimistic comparison table, code review checklist. (#712)
- `docs/field-trials/2026-05-field-trial-105.md` — FT105 report: optlocklog (optimistic locking, 12 tests, 24 assertions, 6-persona DX review). (#712)

---

## [1.5.38] — 2026-05-21

### Added
- `docs/howto/webhook-signature.md` — Webhook HMAC-SHA256 signature verification guide: `hash_equals()` vs `===` timing attack explanation, Stripe-compatible header format, timestamp-in-payload design, replay attack prevention (5-minute window), secret rotation pattern, testing with `sign()` helper, code review checklist. (#710)
- `docs/field-trials/2026-05-field-trial-104.md` — FT104 report: hmaclog (Webhook signature verification, 13 tests, 23 assertions, 6-persona DX review). (#710)

---

## [1.5.37] — 2026-05-21

### Added
- `docs/howto/mass-assignment.md` — mass assignment defence guide: explicit DTO whitelist pattern, attack scenarios (role escalation, is_active tampering, id/timestamp forgery), response field control, trusted-internal-service DTO pattern, `create()` vs `createList()` distinction, code review checklist. (#708)
- `docs/field-trials/2026-05-field-trial-103.md` — FT103 report: masslog (mass assignment defence, 14 tests, 39 assertions, 6-persona DX review). (#708)

---

## [1.5.36] — 2026-05-21

### Added
- `docs/howto/transactions.md` — `DatabaseTransactionManagerInterface::transactional()` guide: correct callback-scoped executor pattern, injected-repository trap (silent rollback failure), pre-validation + atomic operation pattern, rollback testing, Laravel vs NENE2 connection model comparison. (#706)
- `docs/field-trials/2026-05-field-trial-102.md` — FT102 report: txlog (database transaction boundaries, rollback correctness, 6-persona DX review). (#706)

---

## [1.5.35] — 2026-05-21

### Added
- `docs/howto/nested-json-validation.md` — nested JSON validation guide: dot-notation error paths, collect-all-errors pattern, PHPStan discriminated-union workaround (`@var` annotation), `array_is_list()` usage, JSON numeric type handling. (#704)
- `docs/field-trials/2026-05-field-trial-101.md` — FT101 report: nestedlog (nested JSON validation with structured error paths). (#704)

---

## [1.5.34] — 2026-05-21

### Added
- `docs/howto/pagination.md` — OFFSET vs cursor pagination guide: fetch+1 pattern, limit clamping, performance comparison table, SQLite EXPLAIN QUERY PLAN note, migration verification technique. (#702)
- `docs/field-trials/2026-05-field-trial-100.md` — FT100 report: pagelog (OFFSET vs cursor pagination, 500-row deep-page correctness test). (#702)

---

## [1.5.33] — 2026-05-21

### Added
- `docs/howto/idempotency.md` — Idempotency-Key pattern guide: header validation, replay with 200, race condition handling via `DatabaseConstraintException` + UNIQUE constraint, key generation guidance. (#699)
- `docs/howto/csrf-and-json-api.md` — CORS vs CSRF guide: why CORS ≠ CSRF protection, JSON API preflight resistance, Bearer JWT immunity, SameSite cookies and Origin enforcement for cookie-based sessions. (#700)
- `docs/field-trials/2026-05-field-trial-99.md` — FT99 report: csrflog (CSRF-like patterns / idempotency key). (#699 #700)

---

## [1.5.32] — 2026-05-20

### Added
- `docs/howto/file-upload.md` — base64 JSON file upload guide: `requestMaxBodyBytes` sizing for base64 overhead, `finfo_buffer()` MIME detection, filename sanitization checklist (path traversal, null bytes, dangerous extensions), PHP exception property collision note. (#697)
- `docs/field-trials/2026-05-field-trial-98.md` — FT98 report: uploadlog (file upload security). (#697)

---

## [1.5.31] — 2026-05-20

### Added
- `Router::param(ServerRequestInterface $request, string $key): ?string` — static helper to read a single route path parameter without the two-step `getAttribute(Router::PARAMETERS_ATTRIBUTE)` boilerplate. (#693)
- `QueryStringParser::string()` — optional `$default` third argument; returns `$default` (instead of `null`) when the key is absent or empty. Backwards-compatible. (#692)
- `QueryStringParser::int()` — optional `$default` third argument; same pattern. (#692)
- `docs/howto/sql-injection.md` — SQL injection defense guide: LIKE parameterization, ORDER BY whitelist requirement, IN clause with variable-length placeholders. (#691)
- `docs/field-trials/2026-05-field-trial-97.md` — FT97 report: injectionlog (SQL injection defense). (#691)

---

## [1.5.30] — 2026-05-20

### Added
- `docs/howto/content-negotiation.md` — documents NENE2's JSON-only response design (no 406), `JsonRequestBodyParser` Content-Type behavior, and a custom middleware pattern for 406/415 enforcement. (#689)
- `docs/field-trials/2026-05-field-trial-96.md` — FT96 report: contentlog (content negotiation / Accept header). (#689)

---

## [1.5.29] — 2026-05-20

### Added
- `QueryStringParser::parse()` — returns `array<string, mixed>` (alias for `$request->getQueryParams()`). Mirrors the `JsonRequestBodyParser::parse()` discovery pattern so developers can find the method without knowing the typed-accessor API upfront. (#686)
- `docs/howto/handle-timezones.md` — timezone handling guide: always explicit UTC on `now`, IANA validation with `listIdentifiers()`, `createFromFormat` for strict parsing, DST ambiguous-time behavior (PHP takes first occurrence), local→UTC conversion pattern. (#687)
- `docs/field-trials/2026-05-field-trial-95.md` — FT95 report: schedulelog (timezone-sensitive scheduling). (#686 #687)
- `QueryStringParser` added to the public API stability guarantee (ADR 0009). (#686)

---

## [1.5.28] — 2026-05-20

### Added
- `docs/howto/use-bearer-auth.md` — Bearer token authentication guide: `BearerTokenMiddleware` setup (`authMiddleware` named parameter), `exp` claim recommendation, path protection modes, claims extraction, testing patterns, security property table. (#684)
- `docs/field-trials/2026-05-field-trial-94.md` — FT94 report: authlog (JWT Bearer authentication edge cases). (#684)

---

## [1.5.27] — 2026-05-20

### Changed
- `JsonResponseFactory::create()` and `createList()` now include `JSON_UNESCAPED_UNICODE` in the `json_encode` flags. Non-ASCII characters (Japanese, emoji, Arabic, etc.) are now emitted as literal UTF-8 in responses instead of `\uXXXX` escape sequences. Payload size for CJK-heavy responses is reduced by ~3×. (#681)

### Added
- `docs/howto/validate-unicode-input.md` — how-to guide for Unicode-aware input validation: `mb_strlen` vs `strlen`, null-byte rejection, grapheme clusters vs codepoints, and `JSON_UNESCAPED_UNICODE` behaviour. (#682)
- `docs/field-trials/2026-05-field-trial-93.md` — FT93 report: unicodelog (Unicode/emoji/encoding boundary). (#681 #682)

---

## [1.5.26] — 2026-05-20

### Added
- `docs/howto/prevent-double-booking.md` — reservation concurrency guide: distinguishing duplicate-user from over-capacity, TOCTOU window explanation, SQLite vs PostgreSQL behaviour, domain exception pattern. (#679)
- `docs/field-trials/2026-05-field-trial-92.md` — FT92 report: bookinglog (double-booking prevention). (#679)

---

## [1.5.25] — 2026-05-20

### Added
- `docs/howto/enforce-resource-ownership.md` — how-to guide for IDOR prevention: SQL-level ownership enforcement, 404-not-403 guidance, cross-tenant test patterns. (#677)
- `docs/field-trials/2026-05-field-trial-91.md` — FT91 report: noteslog (personal notes with IDOR prevention). (#677)

---

## [1.5.24] — 2026-05-20

### Added
- `ConditionalWriteHelper` — static helper for HTTP conditional writes. Evaluates the `If-Match` header and returns a `412 Precondition Failed` or `428 Precondition Required` problem-details response when the precondition fails; returns `null` when the write may proceed. Complements the existing `ConditionalGetHelper` (which handles `If-None-Match` / 304). (#674)
- `docs/howto/add-optimistic-locking.md` — how-to guide for implementing optimistic concurrency control with ETag / If-Match, including 428 usage, wildcard semantics, and conditional UPDATE pattern. (#674 #675)

---

## [1.5.23] — 2026-05-20

### Added
- `DatabaseConstraintException` — new exception class (extends `DatabaseConnectionException`) thrown by `PdoDatabaseQueryExecutor` when a DB constraint is violated (UNIQUE, FK, NOT NULL, CHECK). Catch this type to handle duplicate-key or FK violations without catching all connection errors. (#669)

### Changed
- `DatabaseConnectionException` — removed `final` modifier to allow `DatabaseConstraintException` to extend it. Existing `catch (DatabaseConnectionException)` blocks now also catch `DatabaseConstraintException` (Liskov-compatible). (#669)

---

## [1.5.22] — 2026-05-20

### Changed
- `Router`: static routes (zero path parameters) now always take priority over parameterized routes, regardless of registration order. Among routes with the same parameter count, registration order is preserved. This eliminates the common pitfall of `GET /resources/action` being captured by `GET /resources/{id}` when the parameterized route is registered first. (#649)

---

## [1.5.21] — 2026-05-20

### Added
- `JsonResponseFactory::createList()` — creates a bare JSON array response (`[{...}, ...]`). Accepts `list<mixed>` and encodes it as a top-level JSON array with `Content-Type: application/json`. Complements `create()` which is restricted to JSON objects (`array<string, mixed>`). (#645)

---

## [1.5.20] — 2026-05-20

### Added
- `ConditionalGetHelper` — static utility for ETag / conditional-GET support. `check($request, $responseFactory, $etag, $lastModified)` returns a 304 Not Modified response (with `ETag` and `Last-Modified` headers) when `If-None-Match` or `If-Modified-Since` indicates the client already has a fresh copy, or `null` when the handler should return the full 200 response. (#637)

---

## [1.5.19] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Path parameters" callout explaining `Router::PARAMETERS_ATTRIBUTE` extraction pattern; notes that `getAttribute('id')` returns `null` and causes silent 404s. (#624)
- `docs/field-trials/2026-05-field-trial-48.md` — FT48 (webhooklog) field trial report.

---

## [1.5.18] — 2026-05-20

### Added
- `QueryStringParser::array()` — typed helper for PHP-style repeated query parameters (`?key[]=v1&key[]=v2`). Returns `list<string>|null`, trimming each value and filtering empty strings. (#621)
- `docs/howto/add-pagination.md` — documented `QueryStringParser::array()` in Step 6 alongside `commaSeparated()`. (#621)
- `docs/howto/add-database-endpoint.md` — noted that `CAST(? AS INTEGER)` is required in `HAVING` clauses with `COUNT(DISTINCT ...)` comparisons (PDO binds all array values as strings). (#622)
- `docs/field-trials/2026-05-field-trial-47.md` — FT47 (tagfilterlog) field trial report.

---

## [1.5.17] — 2026-05-20

### Fixed
- `JsonResponseFactory::create()` now includes `JSON_PRESERVE_ZERO_FRACTION` so whole-number PHP floats (e.g. `12.0`) are encoded as JSON numbers with a decimal point (`12.0`) rather than integers (`12`). (#602)

### Added
- `docs/howto/quality-tools.md` — new guide covering PHPStan and PHP-CS-Fixer configuration, including the PHPStan 2.x `--memory-limit` CLI-flag requirement. (#603)
- `docs/field-trials/2026-05-field-trial-33.md` — FT33 (shiftlog) field trial report.

---

## [1.5.16] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Aggregate queries with HAVING and PDO parameters (SQLite)" section: explains why `HAVING col <= ?` performs text comparison and documents the `CAST(? AS INTEGER)` fix. (#600)
- `docs/field-trials/2026-05-field-trial-32.md` — FT32 (inventorylog) field trial report.

---

## [1.5.15] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Full-text search with SQLite FTS5" section: content table setup, sync triggers (AFTER INSERT/DELETE/UPDATE), JOIN + MATCH query pattern, prefix matching. (#598)
- `docs/field-trials/2026-05-field-trial-31.md` — FT31 (feedlog) field trial report.

---

## [1.5.14] — 2026-05-20

### Fixed
- `PdoConnectionFactory` now runs `PRAGMA foreign_keys = ON` immediately after creating a SQLite connection. Previously, `ON DELETE CASCADE` and other foreign key constraints were silently ignored on SQLite. (#596)

### Added
- `docs/field-trials/2026-05-field-trial-30.md` — FT30 (projtrack) field trial report.

---

## [1.5.13] — 2026-05-20

### Added
- `DatabaseQueryExecutorInterface::insert()` — convenience method that executes an INSERT and returns the auto-generated ID in one call (wraps `execute()` + `lastInsertId()`). (#593)
- `JsonResponseFactory::createEmpty()` — creates a bodyless response for 204 No Content and similar status codes. (#594)
- `docs/howto/add-database-endpoint.md` — added "Inserting a row and retrieving the generated ID" section documenting `insert()` and the two-step `execute()` + `lastInsertId()` pattern. (#593)
- `docs/howto/add-custom-route.md` — added "Returning 204 No Content" section documenting `createEmpty()` for DELETE endpoints. (#594)

---

## [1.5.12] — 2026-05-20

### Added
- `docs/howto/implement-bulk-endpoint.md` — new howto covering the complete bulk create pattern: envelope validation, per-item validation with `"scores[{i}].field"` indexed error names, size limiting, route registration order, and transaction note. (#591)

---

## [1.5.11] — 2026-05-20

### Changed
- `docs/howto/add-custom-route.md` — added "Action endpoints" section documenting the `POST /resource/{id}/action` pattern (archive, restore, publish, approve) with registration-order note; updated HTTP methods table to include PATCH. (#589)

---

## [1.5.10] — 2026-05-20

### Changed
- `docs/howto/add-custom-route.md` — added warning: static route segments must be registered before parameterised routes sharing the same prefix. Includes example of the silent misbehaviour and the correct ordering. (#586)
- `docs/howto/implement-patch-endpoint.md` — added Step 4 "Validating PATCH fields": explains `array_key_exists` vs `isset` for PATCH field detection, validation pattern for optional fields, and nullable repository parameter pattern. (#587)

---

## [1.5.9] — 2026-05-20

### Added
- `QueryStringParser::commaSeparated()` — splits a comma-separated query parameter into a `list<string>`, trimming whitespace and removing empty values. Returns `null` when the key is absent or produces an empty list. (#584)
- `docs/howto/add-pagination.md` — added Step 6 documenting `QueryStringParser` helpers (`string`, `int`, `bool`, `commaSeparated`) with usage examples. (#584)

---

## [1.5.8] — 2026-05-20

### Changed
- `CorsMiddleware` — added PHPDoc note that OPTIONS preflight requests receive a `204 No Content` response and do not reach the route handler. (#582)

---

## [1.5.7] — 2026-05-20

### Changed
- `DatabaseTransactionManagerInterface::transactional()` — added PHPDoc warning: repositories injected at construction time must not be reused inside the callback; they execute on a different connection outside the transaction. (#579)
- `DatabaseConfig` — added PHPDoc noting that for SQLite only `adapter` and `name` are required; `host`, `user`, `password`, `charset` accept empty strings. (#580)
- `docs/howto/use-transactions.md` — added prominent `> **Warning**` callout about the injected-repository anti-pattern; expanded "Test with a file-based SQLite database" section with `DatabaseConfig` + `PdoConnectionFactory` setup, `:memory:` limitation explanation, and notes on SQLite optional fields. (#578 #579 #580)

---

## [1.5.6] — 2026-05-20

### Added
- `docs/howto/add-database-endpoint.md` — added "Handling UNIQUE constraint violations" section: `DatabaseConnectionException` wraps `PDOException`; catch `DatabaseConnectionException` and inspect `getPrevious()` to detect constraint violations. Includes per-adapter message fragments (SQLite / MySQL / PostgreSQL). (#576)

### Changed
- `InMemoryRateLimitStorage` — removed `@internal` annotation; promoted to public API. The class is for local development and single-process testing; PHPDoc now explicitly documents that it is NOT shared across PHP-FPM workers. Consumers can now use it in tests without PHPStan warnings. (#574)
- `docs/howto/add-health-check.md` — fixed `check()` return type from `bool` to `HealthStatus` in the Quick Start example; added inline note that `name()` return value becomes the key in the `checks` response map. (#575)

---

## [1.5.5] — 2026-05-20

### Added
- `QueryStringParser` (`Nene2\Http\QueryStringParser`) — typed helpers for extracting individual query-string parameters from a PSR-7 request: `string()`, `int()`, and `bool()`. Complements `PaginationQueryParser` (which only handles `limit`/`offset`) with ergonomic accessors for custom filters such as `?category=tech` or `?is_read=false`. `bool()` treats `"0"`, `"false"`, and `"no"` as false; absent or empty-string values return `null`. (#570)
- `docs/howto/implement-patch-endpoint.md` — step-by-step guide for PATCH endpoints: `array_key_exists()` vs `isset()` for partial update field extraction, the empty-body `new stdClass()` pattern, repository contract with empty-fields no-op, and a PHPUnit test helper example. (#571)

### Changed
- `JsonRequestBodyParser::parse()` — when the body is a JSON array instead of a JSON object, the error message now includes an actionable hint: `Hint: in PHP, json_encode([]) produces "[]" (a JSON array). Use json_encode((object)[]) or new stdClass() to produce "{}" (a JSON object).` (#572)

---

## [1.5.4] — 2026-05-20

### Added
- `docs/howto/use-postgresql.md` — complete PostgreSQL setup guide: Dockerfile (`pdo_pgsql`), Docker compose (`postgres:17`), Phinx config, `RETURNING id` pattern for INSERT ID retrieval, per-adapter test data reset (`TRUNCATE RESTART IDENTITY CASCADE` vs MySQL/SQLite), and `DB_CHARSET` behavior on `pgsql`. (#562 #563 #564 #565)
- `docs/howto/add-domain-exception-handler.md` — step-by-step guide for implementing `DomainExceptionHandlerInterface`: correct `ProblemDetailsResponseFactory::create()` call order (`$request` first, slug-only `type`), common mistakes (missing request, full URL in type), and wiring via `RuntimeApplicationFactory::$domainExceptionHandlers`. (#563)

### Changed
- `DatabaseQueryExecutorInterface::lastInsertId()` — added PHPDoc explaining that the method returns `0` on PostgreSQL and documenting the `fetchOne()` + `RETURNING id` alternative. (#562)
- `PdoConnectionFactory` — added inline comment noting that `DB_CHARSET` is intentionally excluded from the `pgsql` DSN; documents the `DATABASE_URL` workaround for explicit client encoding. (#565)

---

## [1.5.3] — 2026-05-20

### Added
- `RequestScopedHolder<T>` (`Nene2\Http\RequestScopedHolder`) — a generic mutable holder for request-scoped values. Inject one shared instance into a middleware (writer) and a route handler or repository (reader) to pass extracted values — tenant ID, decoded claims, trace context — without threading the PSR-7 request object through every layer. Includes `set()`, `get()`, `isSet()`, and `reset()` (for async runtimes). (#555)
- `RuntimeApplicationFactory::$authMiddleware` now accepts `list<MiddlewareInterface>` in addition to a single `MiddlewareInterface|null`. Pass a list to stack multiple middlewares in sequence (first item runs first) without wrapping them in a composite — e.g. a tenant extractor followed by a JWT verifier. Existing single-middleware callers are unaffected. (#556)
- `docs/howto/request-scoped-state.md` — explains the holder pattern, when to prefer it over PSR-7 request attributes, how to stack multiple auth middlewares, and the async-runtime caveat (PHP shared-nothing model). (#557)

---

## [1.5.2] — 2026-05-20

### Added
- `RuntimeApplicationFactory::$requestMaxBodyBytes` — configures the request body size limit passed to `RequestSizeLimitMiddleware`. Defaults to 1 MiB (1 048 576 bytes). Increase for bulk-import or large-payload endpoints. (#547)
- `docs/howto/use-transactions.md` — explains the transactional repository pattern: why `transactional()` provides a fresh executor, how to instantiate concrete repositories inside the callback, how to test with a file-based SQLite, and rollback verification. (#549)

### Fixed
- `ApiKeyAuthenticationMiddleware`: `$protectedPaths` and `$protectedPathPrefixes` are now evaluated in **union mode** — a path is protected when it matches any exact entry in `$protectedPaths` OR starts with any prefix in `$protectedPathPrefixes`. Previously, a non-empty `$protectedPaths` suppressed all prefix evaluation, causing `$protectedPathPrefixes` to be silently ignored when both were set. (#548)

---

## [1.5.1] — 2026-05-20

### Added
- `RuntimeApplicationFactory::$machineApiKeyProtectedPathPrefixes` — exposes `ApiKeyAuthenticationMiddleware::$protectedPathPrefixes` through the factory; protects paths starting with any listed prefix without enumerating each route individually (#540)
- `RuntimeApplicationFactory::$machineApiKeyProtectedMethods` — exposes `ApiKeyAuthenticationMiddleware::$protectedMethods` through the factory; enables "public read / key-gated write" patterns (e.g. GET is open, POST/PUT/DELETE require the API key) without manual middleware construction (#540)
- `docs/development/quality-tools.md`: "PHP-CS-Fixer Risky Fixers in Consumer Projects" section — explains `setRiskyAllowed(true)` and `--allow-risky=yes` required when using `declare_strict_types` and other risky rules in consumer projects (#542)

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
