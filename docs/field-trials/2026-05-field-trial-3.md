# Field Trial 3 Report

**Date:** 2026-05-17  
**NENE2 version:** v0.3.0  
**Issue:** [#225](https://github.com/hideyukiMORI/NENE2/issues/225)

---

## Trial context

| Item | Notes |
|---|---|
| Sandbox | NENE2 core repository — focused on Phase 18 (RequestIdProcessor) and ADR 0007 (PUT/PATCH) validation |
| NENE2 version | `v0.3.0` |
| Endpoints exercised | Full Note CRUD: `GET list`, `GET single`, `POST`, `PUT`, `DELETE` |
| OpenAPI operationIds | 9 total: system + Note CRUD |

---

## Session summary

This trial targeted two specific v0.3.0 improvements:

### Phase 18: RequestIdProcessor

**Verification:** `RequestIdHolder` is DI-wired as a singleton. `RequestIdMiddleware` calls `$holder->set($requestId)` on every request. `MonologLoggerFactory` attaches `RequestIdProcessor`, which reads from the holder and adds `extra.request_id` to each log record.

**Observed:** Logging stack was exercised via `composer check` (111 tests). Unit tests confirm:
- `RequestIdProcessorTest`: extra.request_id added, empty ID skipped, existing fields preserved
- `RequestIdMiddlewareHolderTest`: holder populated from header and from generated ID

**Verdict: ✓ Works as designed.**

### ADR 0007: PUT vs PATCH policy

**Verification:** `PUT /examples/notes/{id}` requires both `title` and `body`. Missing fields return 422 with `errors` array. The behavior matches "full replacement" semantics.

**Observed via HTTP tests:**
- `testPutNoteUpdatesNoteAndReturns200` — both fields supplied → 200 with updated values
- `testPutNoteReturns404WhenNoteIsAbsent` — missing resource → 404
- `testPutNoteReturns422WhenTitleIsMissing` — partial body → 422 validation error

**Verdict: ✓ PUT full-replacement semantics are consistent with ADR 0007.**

---

## MCP and API boundary

| Item | Notes |
|---|---|
| MCP tool category | Read-only tools (`listExampleNotes`, `getExampleNoteById`) |
| `X-Request-Id` in logs | Now present in log records as `extra.request_id` via `RequestIdProcessor` |
| Verification command | `docker compose run --rm app composer check` → 111 tests / 410 assertions |

---

## Commands run

```text
docker compose run --rm app composer check
# OK (111 tests, 410 assertions)
# PHPStan: 0 errors
# PHP-CS-Fixer: 0 fixable files
# OpenAPI contract: valid
# MCP catalog: valid

docker compose run --rm app vendor/bin/phpunit --filter RequestId
# OK (5 tests, 11 assertions)

docker compose run --rm app vendor/bin/phpunit --filter "testPut"
# OK (3 tests, 14 assertions)
```

---

## Outcomes

What worked well:

- `RequestIdProcessor` integrates cleanly — no changes needed to existing middleware or domain code
- `RequestIdHolder` singleton pattern avoids static state while keeping the wiring simple
- PUT validation is consistent with POST: same field requirements, same 422 structure
- All 9 OpenAPI operationIds have corresponding HTTP-level tests
- `docker compose logs app` now shows JSON with `request_id` on every log record

What the LLM inferred easily:

- The holder pattern is self-describing once `RequestIdHolder` and `RequestIdProcessor` are visible
- PUT body validation reuses the same `ValidationError` / `ValidationException` flow as POST
- `InMemoryNoteRepository::update()` correctly replaces the stored Note by ID

What docs or boundaries were unclear:

- **No smoke-test guide for RequestIdProcessor in isolation**: verifying that `request_id` appears in live Docker logs requires manually triggering a request and reading stderr — this is not in `docs/development/setup.md`
- **MCP tools don't cover write operations**: `POST`, `PUT`, `DELETE` have no MCP tool entries. A future client using only MCP tools cannot create or update notes without direct HTTP access.
- **`composer.json` "type": "project" blocks `composer require` installs**: developers who try `composer require hideyukimori/nene2` will find it not on Packagist. This is expected (pre-publication), but the README does not explain the installation path clearly for external consumers.

---

## Packagist Go/No-Go Assessment

| Criterion (ADR 0006) | Status |
|---|---|
| ≥ 2 field trial reports confirm stable workflow | ✓ (trials 1, 2, 3) |
| No pending breaking API changes | ✓ |
| CHANGELOG covers all releases | ✓ (v0.1.0–v0.3.0) |
| README Quick Start works from fresh clone | ✓ |
| src/Example/ position confirmed | ✓ (ADR 0006: stays in core) |

**Recommendation: Proceed to Packagist registration.**

One preparatory step before registering: update `composer.json` `"type"` from `"project"` to `"library"` (required for Packagist consumers to `composer require` the package). Add an installation note to README. Then register.

---

## Follow-up Issues

- **#226** — `composer.json` type `"project"` → `"library"` + README install instructions (Packagist prerequisite)
- **#227** — `docs/development/setup.md`: add smoke-test step for RequestIdProcessor in live Docker logs
- **#228** — MCP tool catalog: add write-operation tools (POST/PUT/DELETE Notes) with `write` safety level
