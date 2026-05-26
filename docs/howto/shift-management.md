# How-to: Shift Management API

> **FT reference**: FT43 (`NENE2-FT/shiftlog`) — Employee Shift Scheduling API
> **VULN**: FT225 — security / vulnerability assessment (V-01 through V-12)

Demonstrates an employee shift scheduling API with overlap detection, transaction-scoped
checks, ISO 8601 date comparisons, and custom exception handlers for domain errors.
The VULN section systematically assesses every attack surface and records each finding.

---

## Routes

| Method   | Path                          | Description                              |
|----------|-------------------------------|------------------------------------------|
| `GET`    | `/employees`                  | List employees (paginated)               |
| `POST`   | `/employees`                  | Create an employee                       |
| `GET`    | `/employees/{id}`             | Get a single employee                    |
| `GET`    | `/employees/{id}/shifts`      | List shifts for an employee (paginated)  |
| `POST`   | `/shifts`                     | Schedule a shift (overlap-checked)       |
| `GET`    | `/shifts/{id}`                | Get a single shift                       |
| `DELETE` | `/shifts/{id}`                | Delete a shift                           |
| `GET`    | `/schedule`                   | Shifts within a date window (`?from=&to=`) |
| `GET`    | `/summary/weekly`             | Hours per employee per week              |
| `GET`    | `/summary/overtime`           | Employees exceeding an hour threshold    |

---

## Creating employees

```php
// POST /employees
$body = [
    'name'        => 'Alice',    // required, non-empty string
    'role'        => 'Barista',  // required, non-empty string
    'hourly_rate' => 18.50,      // required, numeric > 0
];
```

`is_int()` / `is_string()` strict JSON type checks are applied. Empty strings are
rejected after `trim()`.

```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'required');
}
```

> **Note**: The schema also has `CHECK(hourly_rate > 0)` at the DB level as a
> defense-in-depth backstop. Validate at the app layer first to return a proper 422.

---

## Scheduling shifts with overlap detection

Overlap detection runs inside a database transaction to prevent race conditions:

```php
return $this->txManager->transactional(
    function (DatabaseQueryExecutorInterface $tx) use ($employeeId, $startsAt, $endsAt, $location, $now): Shift {
        $txRepo   = new self($tx, $this->txManager);
        $employee = $txRepo->findEmployeeById($employeeId);

        // Overlap: any existing shift that intersects [$startsAt, $endsAt)
        $overlap = $tx->fetchOne(
            "SELECT id FROM shifts
             WHERE employee_id = ?
               AND starts_at < ?
               AND ends_at   > ?",
            [$employeeId, $endsAt, $startsAt],
        );

        if ($overlap !== null) {
            throw new ShiftOverlapException($employee->name, $startsAt, $endsAt);
        }

        $id = $tx->insert(
            'INSERT INTO shifts (employee_id, starts_at, ends_at, location, created_at) VALUES (?, ?, ?, ?, ?)',
            [$employeeId, $startsAt, $endsAt, $location, $now],
        );
        // ...
    },
);
```

The overlap condition `starts_at < $endsAt AND ends_at > $startsAt` correctly handles all
four overlap configurations (partial from left, partial from right, contained, and containing).

**Why transactional?** Without a transaction, two concurrent requests can both pass the
overlap check simultaneously and create conflicting shifts. The transaction serialises
the read-check-write sequence.

---

## ends_at > starts_at validation

The application validates time ordering before the DB:

```php
if ($endsAt <= $startsAt) {
    throw new ValidationException([
        new ValidationError('ends_at', 'ends_at must be after starts_at.', 'invalid_range'),
    ]);
}
```

The schema adds `CHECK(ends_at > starts_at)` as a backstop. Two layers together
ensure invalid ranges never reach the data store.

---

## ISO 8601 date string comparison

Shift times are stored as ISO 8601 strings (`2026-05-27T09:00:00+09:00`) and compared
lexicographically in SQL. This works correctly **only when all times use the same
timezone offset or UTC**. Mixed-offset comparisons can produce wrong results:

```
"2026-05-27T09:00:00+09:00" < "2026-05-27T01:00:00Z"  → wrong (same instant)
```

**Recommendation**: Normalise all datetimes to UTC before storage:

```php
$utc      = new \DateTimeZone('UTC');
$startsAt = (new \DateTimeImmutable($raw))->setTimezone($utc)->format(\DateTimeInterface::ATOM);
```

---

## Custom exception → HTTP response mapping

Domain exceptions map to structured Problem Details responses via handlers:

```php
final readonly class ShiftOverlapExceptionHandler implements DomainExceptionHandlerInterface
{
    public function supports(\Throwable $exception): bool
    {
        return $exception instanceof ShiftOverlapException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->create(
            $request,
            'shift-overlap',
            'Shift overlaps with an existing shift.',
            409,
            $exception->getMessage(),
        );
    }
}
```

Separate handlers exist for `ShiftNotFoundException` → 404, `EmployeeNotFoundException` → 404,
and `ShiftOverlapException` → 409. Registering them in `RuntimeApplicationFactory` keeps
controllers free of `try/catch` boilerplate.

---

## Aggregate queries: weekly summary and overtime

```php
// GET /summary/weekly?from=2026-05-19&to=2026-05-25
// GET /summary/overtime?from=2026-05-19&to=2026-05-25&threshold=40
```

The overtime threshold defaults to 40 hours:

```php
$threshold = (float) (QueryStringParser::int($request, 'threshold') ?? 40);
if ($threshold <= 0) {
    throw new ValidationException([...]);
}
```

Note: `QueryStringParser::int()` is used first (rejects non-numeric strings), then cast
to `float`. This prevents `NaN` / `Infinity` from reaching the business layer.

---

## Schema: cascade delete and DB-level constraints

```sql
CREATE TABLE employees (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    role        TEXT    NOT NULL,
    hourly_rate REAL    NOT NULL CHECK(hourly_rate > 0),
    created_at  TEXT    NOT NULL
);

CREATE TABLE shifts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    location    TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    CHECK(ends_at > starts_at)
);
```

`ON DELETE CASCADE` removes an employee's shifts when the employee is deleted.
DB-level `CHECK` constraints are defense-in-depth backstops, not the primary
validation layer — app-level validation must return 422 before any DB INSERT.

---

## VULN — Security assessment (FT225)

Each finding records the attack vector, observed result, and verdict:
**BLOCKED** (secure), **EXPOSED** (real vulnerability), **PARTIALLY EXPOSED**,
or **ACCEPTED BY DESIGN**.

### V-01 — No authentication on any endpoint

**Attack**: Create employees, schedule shifts, or delete shifts without credentials.

```http
POST /employees
{"name": "Attacker", "role": "Ghost", "hourly_rate": 0.01}

DELETE /shifts/1
```

**Observed**: Both succeed. No token, session, or API key is required.

**Verdict**: **EXPOSED** (by design for FT43 demo).
Production scheduling systems MUST gate mutations behind authentication.
Use `MachineApiKeyMiddleware` (env: `NENE2_MACHINE_API_KEY`) or JWT Bearer.

---

### V-02 — No authorization: anyone can delete any shift

**Attack**: Delete a shift that belongs to another employee without any ownership check.

```http
DELETE /shifts/1   # succeeds for any authenticated or unauthenticated caller
```

**Observed**: `204 No Content` regardless of caller identity.

**Verdict**: **EXPOSED** (by design for FT43 demo).
Add a manager/admin role check before deletion, or tie shifts to a requesting user.

---

### V-03 — SQL injection via parameterized queries

**Attack**: Inject SQL through `name`, `role`, `starts_at`, or `location`.

```json
{"name": "x'; DROP TABLE employees; --", "role": "Admin", "hourly_rate": 1}
{"starts_at": "2026-01-01' OR '1'='1", "ends_at": "2026-01-02", "employee_id": 1}
```

**Observed**: Employee created with the injection string as name. Shift `starts_at` is
used in a parameterized query, so no SQL injection occurs.

**Verdict**: **BLOCKED** — all queries use PDO parameterized statements. The stored
string is harmless in the DB; the only risk would be if it were later rendered as HTML.

---

### V-04 — Shift overlap detection race condition

**Attack**: Send two concurrent `POST /shifts` requests with overlapping windows for the
same employee.

**Observed**: The overlap check runs inside `transactional()`. SQLite serialises writes
with WAL-mode locking; MySQL/PostgreSQL use `REPEATABLE READ` or `SERIALIZABLE` isolation
when the transaction manager is configured correctly. Both concurrent inserts cannot both
pass the overlap check.

**Verdict**: **BLOCKED** — transactional overlap check prevents double-booking under
concurrency. Verify isolation level matches the DB engine; SQLite's WAL default is
sufficient for single-node deployments.

---

### V-05 — ends_at ≤ starts_at accepted

**Attack**: Submit a shift where the end time is before or equal to the start time.

```json
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T09:00:00Z"}
{"employee_id": 1, "starts_at": "2026-05-27T10:00:00Z", "ends_at": "2026-05-27T10:00:00Z"}
```

**Observed**: `422 Unprocessable Entity` — the app compares strings (`$endsAt <= $startsAt`)
before inserting. The DB `CHECK(ends_at > starts_at)` is a backstop.

**Verdict**: **BLOCKED** — two-layer validation (app + DB constraint).

---

### V-06 — hourly_rate validation gap

**Attack**: Submit a negative, zero, or string value for `hourly_rate`.

```json
{"name": "X", "role": "Y", "hourly_rate": -10}
{"name": "X", "role": "Y", "hourly_rate": 0}
{"name": "X", "role": "Y", "hourly_rate": "free"}
```

**Observed**:
- Negative/zero: The application does NOT validate `hourly_rate > 0` at the controller
  layer. A negative value bypasses the app check and hits the DB `CHECK(hourly_rate > 0)`,
  which raises a DB exception. Without an explicit handler, this becomes a 500.
- String `"free"`: `is_numeric()` returns false, so this is rejected with 422.

**Verdict**: **PARTIALLY EXPOSED** — add app-layer validation before the DB insert:
```php
if (!isset($body['hourly_rate'])
    || !is_numeric($body['hourly_rate'])
    || (float) $body['hourly_rate'] <= 0) {
    $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive number.', 'out_of_range');
}
```

---

### V-07 — Semantically invalid ISO 8601 datetime

**Attack**: Submit a shift with a structurally plausible but calendar-invalid datetime.

```json
{"starts_at": "2026-02-30T00:00:00Z", "ends_at": "2026-02-30T08:00:00Z", "employee_id": 1}
```

**Observed**: Accepted and stored. The application checks `trim() === ''` but does not
parse the date. `DateTimeImmutable` silently normalises `2026-02-30` to `2026-03-02`,
corrupting the stored value.

**Verdict**: **EXPOSED** — add a round-trip check on both `starts_at` and `ends_at`:
```php
$dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);
if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $raw) {
    $errors[] = new ValidationError('starts_at', 'starts_at must be a valid ISO 8601 datetime.', 'invalid_format');
}
```

---

### V-08 — Unbounded date range in aggregate queries

**Attack**: Request a summary across an arbitrarily large date range to exhaust memory
or cause a slow query.

```http
GET /summary/weekly?from=1900-01-01&to=2099-12-31
```

**Observed**: Query runs on all rows in the table. With a large dataset this can cause
excessive memory use or a multi-second response.

**Verdict**: **EXPOSED** — cap the maximum allowed range (e.g. 90 days) at the
controller layer:
```php
$maxDays = 90;
$diff    = (new DateTimeImmutable($to))->diff(new DateTimeImmutable($from));
if ($diff->days > $maxDays) {
    return $this->json->create(['error' => "Date range must not exceed {$maxDays} days."], 422);
}
```

---

### V-09 — Unbounded employee name / role length

**Attack**: Create an employee with a name or role of tens of thousands of characters.

```json
{"name": "AAAA... (50000 chars)", "role": "Y", "hourly_rate": 10}
```

**Observed**: `201 Created` — SQLite TEXT is unbounded; the row is inserted.

**Verdict**: **EXPOSED** — add `mb_strlen()` checks and return 422:
```php
if (mb_strlen($name) > 100) {
    $errors[] = new ValidationError('name', 'name must not exceed 100 characters.', 'max_length');
}
```

---

### V-10 — Unbounded location string

**Attack**: Schedule a shift with a location string of arbitrary length.

```json
{"employee_id": 1, "starts_at": "...", "ends_at": "...", "location": "BBBB... (50000 chars)"}
```

**Observed**: `201 Created` — no length limit is enforced.

**Verdict**: **EXPOSED** — add `mb_strlen($location) <= 200` check.

---

### V-11 — XSS payload in name / role / location

**Attack**: Store a `<script>` tag in any free-text field.

```json
{"name": "<script>alert(1)</script>", "role": "Admin", "hourly_rate": 1}
```

**Observed**: `201 Created`. Value returned verbatim in JSON responses.

**Verdict**: **ACCEPTED BY DESIGN** — this is a JSON API; escaping is the HTML
rendering client's responsibility. The server does not emit HTML from these fields.
Document the contract in the OpenAPI spec.

---

### V-12 — Non-numeric path IDs

**Attack**: Pass non-digit or negative values as `{id}`.

```http
GET /shifts/abc
GET /shifts/-1
DELETE /employees/0
```

**Observed**: `404 Not Found` in each case. `(int) "abc"` = `0`; no shift/employee
with ID 0 or negative exists, so `findShiftById(0)` throws `ShiftNotFoundException`,
which the handler maps to 404.

**Verdict**: **BLOCKED** in practice. Note: `(int) "9abc"` = `9` — if a record with
ID 9 exists it would be returned. Use `ctype_digit()` for strict path-ID validation
when the difference matters.

---

## VULN summary

| # | Attack vector | Verdict |
|---|---------------|---------|
| V-01 | No authentication | EXPOSED (by design) |
| V-02 | No authorization / any shift deletable | EXPOSED (by design) |
| V-03 | SQL injection | BLOCKED |
| V-04 | Overlap race condition | BLOCKED |
| V-05 | ends_at ≤ starts_at | BLOCKED |
| V-06 | Negative hourly_rate bypasses app check | PARTIALLY EXPOSED |
| V-07 | Semantically invalid ISO 8601 datetime | EXPOSED |
| V-08 | Unbounded date range in aggregate queries | EXPOSED |
| V-09 | Unbounded employee name/role | EXPOSED |
| V-10 | Unbounded location string | EXPOSED |
| V-11 | XSS payload storage | ACCEPTED BY DESIGN |
| V-12 | Non-numeric path IDs | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **V-01/02** — Add authentication and role-based authorization
2. **V-06** — Add `hourly_rate > 0` validation at the app layer
3. **V-07** — Add ISO 8601 round-trip validation for datetime fields
4. **V-08** — Cap the maximum date range in aggregate endpoints (e.g. 90 days)
5. **V-09/10** — Add `mb_strlen()` max-length checks on all free-text fields

---

## Related howtos

- [`notification-inbox.md`](notification-inbox.md) — IDOR protection pattern (404 on unauthorized read/write)
- [`prevent-double-booking.md`](prevent-double-booking.md) — transactional double-booking prevention
- [`expense-tracker.md`](expense-tracker.md) — ISO 8601 round-trip date validation
- [`resource-booking.md`](resource-booking.md) — date range capping and time-window queries
