# Field Trial 9 — v1.3.0 Pagination Helper + OpenAPI Markdown Generation

## Date

2026-05-18

## Baseline

- NENE2 v1.3.0 (main branch, post-merge)
- New in v1.3.0:
  - `PaginationQuery` / `PaginationQueryParser` — `?limit=&offset=` parsing in `Nene2\Http`
  - `tools/openapi-to-md.php` + `composer openapi:docs` — OpenAPI → Markdown generation
  - `InvalidJson` / `TooManyRequests` OpenAPI response components; POST/PUT now declare 400
- App container: `docker compose up -d app mysql`
- MySQL container: `docker compose run --rm app composer migrations:migrate`

## Goal

Validate that:

1. `PaginationQueryParser` — default, explicit, and error paths work correctly via HTTP
2. `JsonRequestBodyParser` — 400/422 distinction (v1.2.0 carry-over) confirmed working
3. `composer openapi:docs` — script runs without error; no diff in `docs/reference/http-endpoints.md`
4. Full `composer check` passes locally
5. Packagist v1.3.0 auto-reflection confirmed

---

## Steps Taken

### 1. Start app and database

```bash
docker compose up -d app mysql
docker compose run --rm app composer migrations:migrate
```

**Finding**: Clean start. All migrations ran with no issues.

### 2. Health check smoke

```
GET /health → 200 { "status": "ok", "service": "NENE2" }
```

### 3. PaginationQueryParser — default params

```
GET /examples/notes
→ 200 { items: [], limit: 20, offset: 0 }
```

Default `limit=20` and `offset=0` applied correctly when no query params are given.

### 4. PaginationQueryParser — explicit limit and offset

Created 3 notes (`Note 1`, `Note 2`, `Note 3`) then tested pagination:

```
GET /examples/notes?limit=2
→ 200 { items: [Note 1, Note 2], limit: 2, offset: 0 }

GET /examples/notes?limit=2&offset=2
→ 200 { items: [Note 3], limit: 2, offset: 2 }
```

Pagination slices data correctly. The `limit` and `offset` values in the envelope match the request params.

### 5. PaginationQueryParser — validation error paths (422)

```
GET /examples/notes?limit=0       → 422 { errors: [{ field:"limit", code:"out_of_range" }] }
GET /examples/notes?limit=200     → 422 { errors: [{ field:"limit", code:"out_of_range" }] }  (max=100)
GET /examples/notes?offset=-1     → 422 { errors: [{ field:"offset", code:"out_of_range" }] }
GET /examples/notes?limit=abc     → 422 { errors: [{ field:"limit", code:"out_of_range" }] }  ((int)"abc"=0)
GET /examples/notes?limit=0&offset=-1
→ 422 { errors: [
    { field:"limit", code:"out_of_range" },
    { field:"offset", code:"out_of_range" }
  ]}
```

All validation error paths return `422 validation-failed` Problem Details with structured `errors` array. Multiple errors are collected and returned together. Non-numeric values are coerced to `0` (PHP `(int)` cast) and caught by the `limit < 1` check — no uncaught exceptions.

### 6. JsonRequestBodyParser — 400 invalid JSON paths

```
POST /examples/notes  (body: "not-json")
→ 400 { type: ".../invalid-json", detail: "...invalid JSON: Syntax error" }

POST /examples/notes  (body: "")
→ 400 { type: ".../invalid-json", detail: "Request body is empty. A JSON object is required." }

POST /examples/notes  (body: ["a","b"])
→ 400 { type: ".../invalid-json", detail: "Request body must be a JSON object, got array." }
```

400 and 422 are clearly distinct. Each invalid-JSON path returns a specific `detail` message.

### 7. JsonRequestBodyParser — 422 validation failure

```
POST /examples/notes  (body: { "body":"only body" })
→ 422 { errors: [{ field:"title", message:"Title is required.", code:"required" }] }
```

Valid JSON with missing required fields correctly returns 422 (not 400).

### 8. composer openapi:docs — Markdown generation

```bash
docker compose run --rm app composer openapi:docs
# → Written: docs/reference/http-endpoints.md

git diff docs/reference/http-endpoints.md
# → (no output — file unchanged)
```

Script runs without error. The generated file is byte-for-byte identical to the committed version, confirming the CI sync check (`git diff --exit-code`) would pass.

### 9. Full composer check

```bash
docker compose run --rm app composer test    → OK (199 tests, 630 assertions)
docker compose run --rm app composer cs      → Found 0 of 179 files that can be fixed
docker compose run --rm app composer openapi → OpenAPI contract is valid.

# PHPStan requires explicit memory limit in Docker (memory_limit PHP INI is not propagated)
docker compose run --rm app php -d memory_limit=512M vendor/bin/phpstan analyse → No errors
```

All checks pass. **Friction F-1**: `composer check` fails with OOM inside Docker (`phpstan` exits with "increase memory_limit"). The `composer check` script does not pass `--memory-limit` to PHPStan, so it hits the default `128M` cap in the container environment.

### 10. Packagist v1.3.0 reflection

GitHub Releases for v1.3.0 was created during this trial (the previous session had only pushed the git tag, not created a GitHub Release). The Packagist webhook was manually triggered via the API update endpoint. After creating the GitHub Release and triggering the Packagist update API, v1.3.0 and v1.2.0 were confirmed on Packagist within the session. A fresh request (bypassing the CDN cache) confirmed both versions are available.

---

## Results

| Scenario | Expected | Actual | Status |
|---|---|---|---|
| `GET /examples/notes` (no params) | `limit:20, offset:0` | ✓ | Pass |
| `GET /examples/notes?limit=2` | 2 items, `limit:2` | ✓ | Pass |
| `GET /examples/notes?limit=2&offset=2` | offset slicing works | ✓ | Pass |
| `?limit=0` | 422 `out_of_range` | ✓ | Pass |
| `?limit=200` (exceeds max 100) | 422 `out_of_range` | ✓ | Pass |
| `?offset=-1` | 422 `out_of_range` | ✓ | Pass |
| `?limit=abc` | 422 `out_of_range` (coerced to 0) | ✓ | Pass |
| `?limit=0&offset=-1` (both invalid) | 422 with 2 errors | ✓ | Pass |
| invalid JSON body → `POST /examples/notes` | 400 `invalid-json` | ✓ | Pass |
| empty body → `POST /examples/notes` | 400 `invalid-json` | ✓ | Pass |
| array JSON body → `POST /examples/notes` | 400 `invalid-json` | ✓ | Pass |
| valid JSON, missing field → `POST /examples/notes` | 422 `validation-failed` | ✓ | Pass |
| `composer openapi:docs` runs clean | exits 0, no diff | ✓ | Pass |
| PHPUnit 199 tests | all pass | ✓ | Pass |
| PHP-CS-Fixer | 0 fixable | ✓ | Pass |
| OpenAPI contract valid | valid | ✓ | Pass |
| PHPStan (with `--memory-limit 512M`) | no errors | ✓ | Pass |
| Packagist v1.3.0 | reflected | ✓ (v1.2.0 also confirmed) | Pass |

---

## Friction Observed

### F-1: `composer check` hits PHPStan OOM in Docker

The `composer check` script calls `phpstan analyse` without a `--memory-limit` flag. Inside the Docker container, the PHP memory limit defaults to `128M`, which is not sufficient for PHPStan's parallel analysis. The script exits with:

```
Increase your memory limit in php.ini or run PHPStan with --memory-limit CLI option.
```

Running `php -d memory_limit=512M vendor/bin/phpstan analyse` directly passes cleanly.

**Impact**: `composer check` fails locally when the PHP memory limit is low (e.g., in Docker with default limits). CI likely succeeds because `php.ini` there permits more memory, or the phpstan GitHub Action sets limits explicitly.

**Candidate fix**: Add `--memory-limit 512M` to the `phpstan analyse` call in `composer.json` scripts, or add a `phpstan.memory_limit` key to `phpstan.neon`.

### F-2: v1.3.0 GitHub Release was not created alongside the git tag

The git tag `v1.3.0` was pushed in a previous session, but the corresponding GitHub Release was not created. Packagist did not pick up the new version because the webhook had not fired for the new tag. Creating the GitHub Release during this trial triggered the Packagist update job.

**Impact**: `composer require hideyukimori/nene2:^1.3` fails until Packagist reflects the version.

**Policy implication**: The release checklist should include creating a GitHub Release (not just pushing a git tag). A GitHub Release creation triggers the Packagist webhook reliably.

---

## Follow-up Issues

- [ ] fix(phpstan): Add `--memory-limit` to phpstan call in `composer.json` to prevent OOM in Docker (#371)
- [ ] docs(release): Update release checklist to require GitHub Release creation alongside git tag push

## Conclusion

v1.3.0 features are fully functional. `PaginationQueryParser` correctly parses, validates, and reports errors for all edge cases — including multi-error collection and non-numeric input coercion. The 400/422 distinction from `JsonRequestBodyParser` (v1.2.0) continues to work cleanly. `composer openapi:docs` produces stable output with no drift.

Primary discoveries: a PHPStan memory limit gap in the Docker environment (F-1) and a release workflow gap (F-2, GitHub Release was not created alongside the git tag). Neither is a code defect.
