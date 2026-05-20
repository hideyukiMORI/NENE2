# Field Trial 29 — Time Tracking API (timelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/timelog/`
**NENE2 version**: 1.5.12
**Theme**: Timer start/stop lifecycle, NULL end_time for running timers, daily summary with duration calculation, 409-conflict domain exceptions for timer state violations

## What was built

A time-tracking API with:

- `POST /timers/start` — start a new timer (label required); 409 if one is already running
- `POST /timers/stop` — stop the running timer; 409 if none is running
- `GET /timers/running` — check whether a timer is currently active
- `GET /timers` — list all entries with `?label=` search and `?date=YYYY-MM-DD` filter, pagination
- `GET /timers/{id}` — fetch by ID
- `DELETE /timers/{id}` — delete entry
- `GET /timers/summary` — daily summary grouped by date with total duration (seconds) and entry count; `?from=` and `?to=` date filters

Duration calculated via SQLite's `julianday()` function:
```sql
SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds
```

28/28 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. `executor->insert()` does not exist — must use `execute()` + `lastInsertId()` (Medium)

**Description**: When implementing a repository that needs the auto-increment ID after an INSERT, the natural pattern `$id = $executor->insert(...)` does not exist. The correct pattern is `execute()` followed by `lastInsertId()` — but this is not obvious from reading other FT code or howtos.

**Severity**: Medium

**Reproduction**:
```php
// Wrong — method does not exist:
$id = $this->executor->insert('INSERT INTO ...', [...]);

// Correct:
$this->executor->execute('INSERT INTO ...', [...]);
$id = $this->executor->lastInsertId();
```

**Potential fix**: Add `insert()` as a convenience method that wraps `execute()` + `lastInsertId()`, OR document this two-step pattern explicitly in `implement-list-endpoint.md` or a new `use-database.md` howto.

---

### 2. `JsonResponseFactory::create()` does not accept `null` for 204 No Content (Low)

**Description**: For `DELETE` endpoints returning 204, the intuitive call `$this->json->create(null, 204)` fails because `create(array $data, ...)` requires an `array`. The workaround is to pass an empty array `[]`.

**Severity**: Low

**Reproduction**:
```php
// Wrong — type error:
return $this->json->create(null, 204);

// Correct:
return $this->json->create([], 204);
```

**Potential fix**: Add a `createEmpty(int $status = 204): ResponseInterface` method, or make `$data` nullable in `create()`. Document this in the howto.

---

### 3. CS-Fixer rejects short `{}` constructor bodies (Low)

**Description**: PHP-CS-Fixer with `@PSR12` reformats `__construct(...) {}` to a multi-line block. This is the same friction as FT26 and FT27 — consistent but surprising to developers who write compact constructors.

**Severity**: Low (expected tooling behaviour).

**Pattern**:
```php
// Written:
public function __construct(private Foo $foo) {}

// CS-Fixer normalises to:
public function __construct(private Foo $foo)
{
}
```

---

### 4. `POST /timers/start` with empty array body (`[]`) returns 400, not 422 (Low)

**Description**: When a test sends `json_encode([])` = `"[]"` as the body (PHP empty array → JSON array, not object), `JsonRequestBodyParser::parse()` returns an array but `$body['label']` would be inaccessible for a JSON array. In practice the parser accepted `[]` but the validation fired as expected. However, sending truly empty `{}` in a test requires passing an associative array; passing `[]` serialises to a JSON array, not an empty object.

**Severity**: Low (test authoring footgun, not a framework bug).

**Workaround**: In tests, send `['label' => null]` to test missing fields rather than `[]`.

## Tests

28 tests, 56 assertions — all pass.

Coverage: start (201, missing label 422, empty label 422, already-running 409), stop (200 with duration, no-running-timer 409, stop-twice 409), running (no timer, timer active, clears after stop), list (empty, two entries, label filter, pagination, includes running entries), show (200, 404), delete (204, 404), summary (empty, excludes running, groups by date, from filter, to filter, invalid from 422, invalid to 422), infrastructure (security headers + X-Request-Id, unknown route 404).
