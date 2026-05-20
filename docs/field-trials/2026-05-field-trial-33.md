# Field Trial 33 — Employee Shift Scheduling (shiftlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/shiftlog/`
**NENE2 version**: 1.5.16
**Theme**: Datetime arithmetic, overlap detection, float aggregates, HAVING with REAL threshold

## Overview

Built an employee shift scheduling API with overlap detection, weekly pay summaries, and overtime alerts. The repository uses `transactional()` for atomic overlap-check + insert, SQLite's `julianday()` function for hour calculations, and `HAVING total_hours > CAST(? AS REAL)` for overtime threshold filtering.

## Endpoints Implemented

- `POST /employees` — create employee (name, role, hourly_rate), 201
- `GET /employees` — list employees with pagination
- `GET /employees/{id}` — show employee, 404 on missing
- `GET /employees/{id}/shifts` — paginated shifts for employee
- `POST /shifts` — schedule shift (employee_id, starts_at, ends_at, location); 409 on overlap, 422 on invalid range
- `GET /shifts/{id}` — show shift, 404 on missing
- `DELETE /shifts/{id}` — cancel shift, 204 No Content
- `GET /schedule?from=…&to=…` — all shifts in a date range
- `GET /summary/weekly?from=…&to=…` — hours, pay, shift count per employee
- `GET /summary/overtime?from=…&to=…&threshold=40` — employees exceeding hourly threshold

## Test Results

31 tests, 53 assertions — all pass after 2 fixes (see frictions below).

---

## Frictions Found

### Friction 1 — `JsonResponseFactory` encodes whole-number floats as integers [HIGH]

**Symptom**: The weekly summary endpoint returns `"total_hours": 12` instead of `"total_hours": 12.0`. When asserting `assertSame(12.0, $body['total_hours'])`, the test fails because PHP's `json_decode` returns integer `12`, not float `12.0`.

**Root cause**: `JsonResponseFactory` calls `json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)` without `JSON_PRESERVE_ZERO_FRACTION`. PHP's default behavior encodes floats with no fractional part (e.g. `12.0`) as integers (`12`).

**Impact**: Any endpoint returning float fields (prices, rates, hours, percentages) will silently produce integer JSON when the value is a whole number. Clients expecting `float` / `number` type fields will parse them as integers. This breaks strict type checking in typed clients and violates OpenAPI schemas that declare the field as `number`.

**Reproduction**:

```php
$json = new JsonResponseFactory($psr17, $psr17);
$res  = $json->create(['total_hours' => 12.0]);  // body: {"total_hours": 12}  ← integer!
```

**Expected**:

```json
{"total_hours": 12.0}
```

**Fix**: Add `JSON_PRESERVE_ZERO_FRACTION` to `json_encode` flags:

```php
json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)
```

**Workaround used in this FT**: Changed test assertions from `assertSame(12.0, ...)` to `assertEqualsWithDelta(12.0, ..., 0.01)`.

**NENE2 impact**: Fix in `JsonResponseFactory`. High priority — this silently changes the JSON type of float fields in every response.

---

### Friction 2 — PHPStan `memory_limit` is not a valid `.neon` parameter in v2.x [MEDIUM]

**Symptom**: Adding `memory_limit: 512M` under `parameters:` in `phpstan.neon` causes:

```
Invalid configuration: Unexpected item 'parameters › memory_limit'.
```

**Root cause**: PHPStan 2.x removed `memory_limit` as a configuration parameter. It must now be passed as a CLI flag: `--memory-limit=512M`.

**Reproduction**: PHPStan OOM with default 128M limit when analysing a project with many vendor classes (nene2 + nyholm/psr7).

**Fix**: Pass via CLI:

```bash
phpstan analyse --memory-limit=512M src tests
```

Or in `composer.json` scripts:

```json
"analyse": "phpstan analyse --level=8 --memory-limit=512M src tests"
```

**NENE2 impact**: Update `quality-tools.md` howto to note that `memory_limit` is a CLI-only option in PHPStan 2.x. (Previously documented in FT12-C, but the neon approach was shown — now it's confirmed broken in v2.)

---

## NENE2 Changes Required

| # | Change | Priority |
|---|--------|----------|
| 1 | Add `JSON_PRESERVE_ZERO_FRACTION` to `JsonResponseFactory::create()` | High |
| 2 | Update `quality-tools.md` howto: PHPStan `--memory-limit` is CLI-only in v2.x | Medium |

## Version

NENE2 changes → v1.5.17
