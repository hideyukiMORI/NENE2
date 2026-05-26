# How-to: Habit Tracker API

> **FT reference**: FT24 (`NENE2-FT/habitlog`) — Habit Tracking API with streak calculation
> **ATK**: FT224 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates a habit-tracking REST API with streak calculation, duplicate-completion
protection (409 Conflict), and frequency allowlisting. The ATK section documents
every attack surface the cracker mindset finds and records whether each is
defended or exposed.

---

## Routes

| Method   | Path                          | Description                        |
|----------|-------------------------------|------------------------------------|
| `GET`    | `/habits`                     | List all habits (`?frequency=`)    |
| `POST`   | `/habits`                     | Create a habit                     |
| `GET`    | `/habits/{id}`                | Get a single habit                 |
| `DELETE` | `/habits/{id}`                | Delete a habit (cascades)          |
| `POST`   | `/habits/{id}/completions`    | Record a completion (idempotent at date level) |
| `GET`    | `/habits/{id}/completions`    | List completions for a habit       |
| `GET`    | `/habits/{id}/streak`         | Current streak (`?today=YYYY-MM-DD`) |

---

## Creating habits

```php
// POST /habits
$body = [
    'name'        => 'Morning Run',        // required, non-empty string
    'description' => 'Run 5 km',           // optional
    'frequency'   => 'daily',              // 'daily' | 'weekly' | 'monthly'
];
```

`frequency` is validated against an explicit allowlist. Any other value returns 422.

```php
private function createHabit(ServerRequestInterface $req): mixed
{
    $body      = JsonRequestBodyParser::parse($req);
    $name      = isset($body['name']) ? trim((string) $body['name']) : '';
    $frequency = isset($body['frequency']) ? (string) $body['frequency'] : 'daily';

    $errors = [];
    if ($name === '') {
        $errors[] = new ValidationError('name', 'Name must not be empty.', 'required');
    }

    $validFrequencies = ['daily', 'weekly', 'monthly'];
    if (!in_array($frequency, $validFrequencies, true)) {
        $errors[] = new ValidationError('frequency', 'Frequency must be daily, weekly, or monthly.', 'invalid_value');
    }

    if ($errors !== []) {
        throw new ValidationException($errors);
    }
    // ...
}
```

---

## Recording completions with duplicate protection

Completions are keyed by `(habit_id, completed_on)` via a `UNIQUE` constraint. A second
POST for the same date returns **409 Conflict** without touching the database row.

```sql
-- schema.sql
UNIQUE(habit_id, completed_on)
```

```php
public function complete(int $habitId, string $completedOn, string $note): Completion
{
    try {
        $this->executor->execute(
            'INSERT INTO completions (habit_id, completed_on, note) VALUES (?, ?, ?)',
            [$habitId, $completedOn, $note],
        );
    } catch (DatabaseConnectionException $e) {
        $previous = $e->getPrevious();
        if ($previous !== null && str_contains($previous->getMessage(), 'UNIQUE constraint failed')) {
            throw new AlreadyCompletedException($habitId, $completedOn);
        }
        throw $e;
    }

    return new Completion($this->executor->lastInsertId(), $habitId, $completedOn, $note);
}
```

The controller maps `AlreadyCompletedException` → 409 before NENE2's global error handler
sees it, so the response uses Problem Details correctly.

---

## Streak calculation

Streak counts backward from `$today` through consecutive daily completions.

```php
public function currentStreak(int $habitId, string $today): int
{
    $rows = $this->executor->fetchAll(
        'SELECT completed_on FROM completions WHERE habit_id = ? ORDER BY completed_on DESC',
        [$habitId],
    );

    $streak   = 0;
    $expected = new \DateTimeImmutable($today);

    foreach ($rows as $row) {
        $date = new \DateTimeImmutable((string) $row['completed_on']);
        if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) {
            break;
        }
        $streak++;
        $expected = $expected->modify('-1 day');
    }

    return $streak;
}
```

`?today=YYYY-MM-DD` overrides the reference date so tests are deterministic
without mocking `date()`.

---

## Date format validation

The `completed_on` field is validated by regex, not by semantic parsing:

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn)) {
    throw new ValidationException([
        new ValidationError('completed_on', 'Date must be in YYYY-MM-DD format.', 'invalid_format'),
    ]);
}
```

This correctly rejects `"not-a-date"` but accepts `"2026-02-30"`. For strict
semantic validation, add a `DateTimeImmutable` round-trip check:

```php
// Stricter validation (recommended for production):
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

## Path parameter safety

Path `{id}` is cast to `int` with a zero fallback:

```php
$id = (int) ($req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id'] ?? 0);
```

Non-numeric strings become `0`. No habit with `id = 0` exists, so the handler
falls through to a `null` check and returns 404. This avoids the need for
`ctype_digit()` here, but be aware that `(int) "9abc"` yields `9` — a route that
must reject non-digit paths should use `ctype_digit()` instead.

---

## Schema: cascade delete

```sql
CREATE TABLE completions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    habit_id     INTEGER NOT NULL REFERENCES habits(id) ON DELETE CASCADE,
    completed_on TEXT    NOT NULL,
    note         TEXT    NOT NULL DEFAULT '',
    UNIQUE(habit_id, completed_on)
);
```

`ON DELETE CASCADE` ensures completions are removed when the parent habit is deleted.
Enable foreign-key enforcement with `PRAGMA foreign_keys = ON` when using SQLite.

---

## ATK — Cracker attack test (FT224)

Each finding below documents an attack vector, the observed result, and the
verdict: **BLOCKED** (secure), **EXPOSED** (real vulnerability), or
**ACCEPTED BY DESIGN** (intentional trade-off documented).

### ATK-01 — No authentication on any endpoint

**Attack**: Create, read, or delete habits without any credentials.

```http
POST /habits
Content-Type: application/json

{"name": "Attacker habit", "frequency": "daily"}
```

**Observed**: `201 Created` — success with no token, session, or key.

**Verdict**: **EXPOSED** (by design for FT24 demo).
Production habit trackers MUST gate mutations behind authentication.
NENE2's `MachineApiKeyMiddleware` or JWT Bearer middleware covers this.

---

### ATK-02 — No ownership: read / delete any habit

**Attack**: Without knowing whose habit it is, enumerate and delete all habits.

```http
GET /habits         → lists every habit in the system
DELETE /habits/1    → deletes habit #1 regardless of who created it
```

**Observed**: `200 OK` on list, `200 OK` on delete.

**Verdict**: **EXPOSED** (by design for FT24 demo).
Add a `user_id` column, ownership check on write paths, and 404 (not 403) on
unauthorized access (IDOR protection — see FT222 `notificationlog`).

---

### ATK-03 — SQL injection via parameterized queries

**Attack**: Inject SQL through `name`, `frequency`, or `completed_on`.

```json
{"name": "x' OR '1'='1", "frequency": "daily"}
{"completed_on": "2026-01-01' OR '1'='1"}
```

**Observed**: Name stored verbatim. Completion rejected by date-format regex before
reaching the DB layer.

**Verdict**: **BLOCKED** — all queries use PDO parameterized statements. The
frequency allowlist blocks injection via that field at the application layer.

---

### ATK-04 — Semantically invalid date accepted

**Attack**: Submit a structurally correct but calendar-invalid date.

```json
{"completed_on": "2026-02-30"}
{"completed_on": "2026-13-01"}
{"completed_on": "0000-00-00"}
```

**Observed**: `201 Created` — regex `^\d{4}-\d{2}-\d{2}$` passes; PDO stores
the string verbatim; `DateTimeImmutable` silently normalises it (e.g.
`2026-02-30` becomes `2026-03-02`), corrupting streak counts.

**Verdict**: **EXPOSED** — add a round-trip check:
```php
$dt = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
if ($dt === false || $dt->format('Y-m-d') !== $completedOn) {
    throw new ValidationException([...]);
}
```

---

### ATK-05 — Non-numeric path IDs

**Attack**: Send non-digit or negative values as `{id}`.

```http
GET  /habits/abc
GET  /habits/-1
GET  /habits/0
GET  /habits/1.5
```

**Observed**: All return `404 Not Found`. `(int) "abc"` = `0`, `(int) "-1"` = `-1`,
`(int) "1.5"` = `1`. No habit exists at those IDs, so `findById()` returns `null`.

**Verdict**: **BLOCKED** in practice (no habit with ID ≤ 0 exists). However
`(int) "9abc"` = `9` — if a habit with ID 9 exists it would be returned. Use
`ctype_digit()` for strict path-ID validation when the difference matters.

---

### ATK-06 — Duplicate completion on same date

**Attack**: POST the same `(habit_id, completed_on)` twice to pad streaks.

```http
POST /habits/1/completions {"completed_on": "2026-05-20"}
POST /habits/1/completions {"completed_on": "2026-05-20"}
```

**Observed**: Second request returns `409 Conflict` — the UNIQUE constraint
at the DB layer fires, `AlreadyCompletedException` is caught, and a Problem
Details response is returned.

**Verdict**: **BLOCKED** — the DB constraint is the authoritative guard; the
application layer maps it to a well-formed 409.

---

### ATK-07 — XSS payload in name/note

**Attack**: Store a script tag in `name` or `note`.

```json
{"name": "<script>alert(document.cookie)</script>", "frequency": "daily"}
```

**Observed**: `201 Created`. The payload is stored verbatim and returned as-is
in JSON responses.

**Verdict**: **ACCEPTED BY DESIGN** — this is a JSON API; escaping is the
rendering client's responsibility. The server does not produce HTML from these
fields. Document this contract clearly in the API spec.

---

### ATK-08 — Extremely long habit name

**Attack**: Send a name with tens of thousands of characters to exhaust storage
or cause slow serialisation.

```php
'name' => str_repeat('A', 50_000)
```

**Observed**: `201 Created` — no length limit is enforced at the application layer.
SQLite TEXT is unbounded; the row is inserted.

**Verdict**: **EXPOSED** — add a max-length check (e.g. 200 chars) in the
controller's validation block and return 422:
```php
if (mb_strlen($name) > 200) {
    $errors[] = new ValidationError('name', 'Name must not exceed 200 characters.', 'max_length');
}
```

---

### ATK-09 — Whitespace-only habit name

**Attack**: Send a name that is all whitespace.

```json
{"name": "   "}
```

**Observed**: `422 Unprocessable Entity` — `trim()` collapses the value to `''`,
which triggers the `required` validation error.

**Verdict**: **BLOCKED** — `trim()` before the empty-string check covers this.

---

### ATK-10 — Streak manipulation via `?today=` query param

**Attack**: Override the reference date to claim a historical streak.

```http
GET /habits/1/streak?today=2099-12-31
GET /habits/1/streak?today=not-a-date
```

**Observed**: `today=2099-12-31` → streak = 0 (no completions in the future).
`today=not-a-date` → PHP `DateTimeImmutable` throws an internal exception on
the malformed value (becomes a 500 in the default error handler).

**Verdict**: **PARTIALLY EXPOSED** — validate `today` with a regex or round-trip
check before passing to `currentStreak()`:
```php
$today = QueryStringParser::string($req, 'today') ?? date('Y-m-d');
$dt    = DateTimeImmutable::createFromFormat('Y-m-d', $today);
if ($dt === false || $dt->format('Y-m-d') !== $today) {
    $today = date('Y-m-d'); // fall back to server date
}
```

---

### ATK-11 — Complete a non-existent habit

**Attack**: POST a completion for a habit ID that does not exist.

```http
POST /habits/99999/completions
{"completed_on": "2026-05-20"}
```

**Observed**: `404 Not Found` — `findById(99999)` returns `null` and the controller
returns the not-found response before attempting the INSERT.

**Verdict**: **BLOCKED** — the existence check happens before the DB write.

---

### ATK-12 — Path traversal / injection in query parameters

**Attack**: Inject path-traversal or shell-injection strings via `frequency` filter.

```http
GET /habits?frequency=../../../etc/passwd
GET /habits?frequency='; DROP TABLE habits; --
```

**Observed**: Both return `200 OK` with an empty `habits` array. The `frequency`
value is used only in `array_filter` with a strict `===` comparison against stored
values. No DB query is constructed from it.

**Verdict**: **BLOCKED** — filter-by-query-param is applied in PHP memory, not
as a raw SQL `WHERE` clause. No file I/O or shell execution is triggered.

---

## ATK summary

| # | Vector | Verdict |
|---|--------|---------|
| ATK-01 | No authentication | EXPOSED (by design) |
| ATK-02 | No ownership / IDOR | EXPOSED (by design) |
| ATK-03 | SQL injection | BLOCKED |
| ATK-04 | Semantically invalid date | EXPOSED |
| ATK-05 | Non-numeric path ID | BLOCKED |
| ATK-06 | Duplicate completion | BLOCKED |
| ATK-07 | XSS payload storage | ACCEPTED BY DESIGN |
| ATK-08 | Unbounded name length | EXPOSED |
| ATK-09 | Whitespace-only name | BLOCKED |
| ATK-10 | `?today=` manipulation | PARTIALLY EXPOSED |
| ATK-11 | Non-existent habit completion | BLOCKED |
| ATK-12 | Path traversal / injection in QS | BLOCKED |

**Real vulnerabilities to fix before production**:
1. **ATK-01/02** — Add authentication and ownership
2. **ATK-04** — Add semantic date validation (round-trip via `DateTimeImmutable`)
3. **ATK-08** — Add `mb_strlen()` max-length check on `name`/`note`
4. **ATK-10** — Validate `?today=` before passing to business logic

---

## Related howtos

- [`notification-inbox.md`](notification-inbox.md) — IDOR protection pattern (404 on unauthorized read)
- [`expense-tracker.md`](expense-tracker.md) — strict `is_int()` type checks and ISO date round-trip validation
- [`session-management.md`](session-management.md) — authentication layer to add on top of this pattern
