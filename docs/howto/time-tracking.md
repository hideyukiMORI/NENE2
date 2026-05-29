---
title: "How-to: Time Tracking API"
category: product
tags: [time-tracking, stopwatch, timer, duration]
difficulty: intermediate
related: [habit-tracker, shift-management]
---

# How-to: Time Tracking API

> **FT reference**: FT246 (`NENE2-FT/timelog`) — Time Tracking API

Demonstrates a stopwatch-style time tracking API where a timer entry has a `start_time`
and a nullable `end_time` (`NULL` = running, non-`NULL` = stopped), only one timer can
run at a time, duration is computed via SQLite's `julianday()`, and daily summaries
aggregate total tracked seconds per calendar day.

---

## Routes

| Method   | Path              | Description                                             |
|----------|-------------------|---------------------------------------------------------|
| `POST`   | `/timers/start`   | Start a new timer (fails if one is already running)     |
| `POST`   | `/timers/stop`    | Stop the currently running timer                        |
| `GET`    | `/timers/running` | Get the currently running timer (or `running: false`)   |
| `GET`    | `/timers/summary` | Daily summary: total seconds and entry count per day    |
| `GET`    | `/timers`         | List entries (paginated, filterable by label and date)  |
| `GET`    | `/timers/{id}`    | Get a single timer entry                                |
| `DELETE` | `/timers/{id}`    | Delete a timer entry (`204 No Content`)                 |

> **Static routes first**: `/timers/start`, `/timers/stop`, `/timers/running`,
> `/timers/summary` are all registered before `/timers/{id}` so literal paths are
> not captured as parameterized segments.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS time_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time   TEXT,              -- NULL = running
    created_at TEXT NOT NULL
);
```

`end_time` is nullable — `NULL` means the timer is still running. `NOT NULL` means it
has been stopped. There is no separate `status` column; the presence or absence of
`end_time` encodes the running state.

---

## Running state: `end_time IS NULL`

The timer's running state is detected purely from the `end_time` column:

```php
final readonly class TimeEntry
{
    public function isRunning(): bool
    {
        return $this->endTime === null;
    }

    public function durationSeconds(): ?int
    {
        if ($this->endTime === null) {
            return null;  // still running — no duration yet
        }
        $start = new \DateTimeImmutable($this->startTime);
        $end   = new \DateTimeImmutable($this->endTime);
        return (int) $end->getTimestamp() - (int) $start->getTimestamp();
    }
}
```

`isRunning()` returns `true` when `endTime` is `null`. `durationSeconds()` returns
`null` for running timers — the duration cannot be computed until the timer is stopped.
The response includes `"running": true` and `"duration_seconds": null` for active entries.

---

## Singleton timer: only one can run at a time

`start()` checks for a running timer before creating a new one:

```php
public function start(string $label, string $startTime, string $createdAt): TimeEntry
{
    $running = $this->findRunning();
    if ($running !== null) {
        throw new TimerAlreadyRunningException($running->id);
    }

    $this->executor->execute(
        'INSERT INTO time_entries (label, start_time, end_time, created_at) VALUES (?, ?, NULL, ?)',
        [$label, $startTime, $createdAt],
    );

    return $this->findById($this->executor->lastInsertId());
}
```

If a timer is already running, `TimerAlreadyRunningException` is thrown → `409 Conflict`.
`end_time` is inserted as the literal `NULL` SQL value.

The running timer lookup:

```php
public function findRunning(): ?TimeEntry
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM time_entries WHERE end_time IS NULL ORDER BY start_time DESC LIMIT 1',
        [],
    );
    return $row !== null ? $this->hydrate($row) : null;
}
```

`WHERE end_time IS NULL` — standard SQL `NULL` comparison (not `= NULL`). `LIMIT 1`
guards against returning multiple rows if the invariant is ever violated.

---

## Stopping a timer: `stop()`

```php
public function stop(string $endTime): TimeEntry
{
    $running = $this->findRunning();
    if ($running === null) {
        throw new NoRunningTimerException();
    }

    $this->executor->execute(
        'UPDATE time_entries SET end_time = ? WHERE id = ?',
        [$endTime, $running->id],
    );

    return $this->findById($running->id);
}
```

`stop()` finds the running timer, sets `end_time`, and returns the updated entry with
the computed duration. `NoRunningTimerException` is thrown if no timer is running →
`409 Conflict`.

---

## Duration calculation: `julianday()` in SQL

For aggregate summaries, duration is computed in SQL using SQLite's `julianday()`
function:

```sql
SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds
```

`julianday()` converts an ISO datetime string to a Julian Day Number (a real number
representing days since noon on January 1, 4713 BC). Subtracting two Julian Day Numbers
gives the difference in days. Multiplying by `86400` converts days to seconds.
`CAST(... AS INTEGER)` truncates to whole seconds.

`SUM(...)` totals all completed entries for the day. `WHERE end_time IS NOT NULL`
filters out any still-running timers from the summary.

The PHP-side calculation for individual entries:

```php
$start = new \DateTimeImmutable($this->startTime);
$end   = new \DateTimeImmutable($this->endTime);
return (int) $end->getTimestamp() - (int) $start->getTimestamp();
```

Both approaches produce the same result for UTC timestamps. The SQL approach is used
for aggregation (it avoids fetching all rows to sum); the PHP approach is used for
individual entry serialization.

---

## Daily summary aggregation

```php
$sql = 'SELECT date(start_time) AS day,
               SUM(CAST((julianday(end_time) - julianday(start_time)) * 86400 AS INTEGER)) AS total_seconds,
               COUNT(*) AS entry_count
          FROM time_entries
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY day
         ORDER BY day DESC';
```

`date(start_time)` extracts the calendar date from the `start_time` ISO string.
`GROUP BY day` groups all completed entries for the same day.
`ORDER BY day DESC` returns most-recent days first.

The `$where` clause always starts with `['end_time IS NOT NULL']` to exclude running
timers, then optionally adds `date(start_time) >= ?` and `date(start_time) <= ?` for
the date range filter.

---

## `date()` function for date-only filtering

Filtering entries by a calendar date uses SQLite's `date()` function:

```php
if ($date !== null) {
    $where[]  = "date(start_time) = ?";
    $params[] = $date;
}
```

`date(start_time)` extracts just `YYYY-MM-DD` from the ISO datetime string.
`= ?` compares the extracted date to the filter value. This correctly matches all
entries that started on the given day regardless of the time component.

---

## Label filtering with `LIKE`

```php
if ($label !== null) {
    $where[]  = 'label LIKE ?';
    $params[] = '%' . $label . '%';
}
```

`LIKE '%label%'` performs a case-insensitive substring match in SQLite's default
collation. Special characters `%` and `_` in `$label` are interpreted as LIKE wildcards
— escape them if strict literal matching is required.

---

## `GET /timers/running` response contract

The running endpoint returns a consistent shape whether or not a timer is active:

```php
if ($entry === null) {
    return $this->json->create(['running' => false, 'entry' => null]);
}
return $this->json->create(['running' => true, 'entry' => $this->serialize($entry)]);
```

`running: false, entry: null` — no timer active.
`running: true, entry: {...}` — active timer with `end_time: null` and `duration_seconds: null`.

This avoids a `404` for "no running timer" — `404` implies the resource doesn't exist,
but the concept of "running timer" always exists (it's just empty). Using `running: false`
is semantically cleaner.

---

## Related howtos

- [`shift-management.md`](shift-management.md) — shift clock-in/clock-out with nullable end time
- [`scheduled-reminders.md`](scheduled-reminders.md) — timezone-aware datetime validation
- [`aggregate-reporting.md`](aggregate-reporting.md) — `GROUP BY date` aggregation patterns
- [`handle-timezones.md`](handle-timezones.md) — UTC storage and timezone conversion
