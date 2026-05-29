---
title: "How-to: Timezone-aware Event Scheduling"
category: api-design
tags: [timezone, utc, scheduling, datetime, iana]
difficulty: intermediate
related: [handle-timezones, iso-datetime-validation]
---

# How-to: Timezone-aware Event Scheduling

> **FT reference**: FT286 (`NENE2-FT/schedulelog`) — Timezone-aware scheduling: UTC storage + local time conversion, IANA timezone validation via DateTimeZone::listIdentifiers(), InvalidTimezoneException, dynamic ?timezone query parameter, 19 tests / 39 assertions PASS.

This guide shows how to build an event scheduling API that stores times in UTC and presents them in any timezone the client requests.

## Why Store in UTC?

UTC is the universal reference point. Local times are ambiguous (DST changes, timezone rule changes) and vary by client location. By storing in UTC:
- Sorting and comparison are always correct
- Clients can display in their local timezone
- DST transitions don't create ambiguity in historical data

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    timezone    TEXT    NOT NULL,      -- IANA timezone of event creator
    start_utc   TEXT    NOT NULL,      -- UTC ISO 8601: 2026-05-20T15:00:00Z
    start_local TEXT    NOT NULL,      -- Local ISO 8601: 2026-05-20T10:00:00
    created_at  TEXT    NOT NULL
);
```

Both `start_utc` and `start_local` are stored. `start_utc` is authoritative; `start_local` is a convenience cache for the creator's timezone.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/events` | Create event (timezone + local start → UTC) |
| `GET` | `/events` | List events (optional `?timezone=America/New_York`) |
| `GET` | `/events/{id}` | Get event (optional `?timezone=`) |

## IANA Timezone Validation

PHP's `DateTimeZone` constructor accepts some invalid identifiers silently. Validate explicitly:

```php
final class TimezoneConverter
{
    public static function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($ianaTimezone);
        } catch (\Exception) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        // PHP accepts invalid abbreviations like "EST" in some versions —
        // validate against the canonical IANA list explicitly.
        $valid = \DateTimeZone::listIdentifiers();
        if (!in_array($ianaTimezone, $valid, true)) {
            throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

        if ($local === false) {
            throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

`DateTimeZone::listIdentifiers()` returns the PHP-compiled list of IANA identifiers. Non-IANA strings (like `EST`, `GMT+5`) are rejected.

## Create Event: Local → UTC

```php
try {
    $utc = TimezoneConverter::localToUtc($start, $timezone);
} catch (InvalidTimezoneException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'timezone', 'code' => 'invalid', 'message' => "Unknown timezone: $timezone"]],
    ]);
} catch (\InvalidArgumentException) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'start', 'code' => 'invalid', 'message' => "Cannot parse datetime: $start"]],
    ]);
}

$startUtc   = TimezoneConverter::formatUtc($utc);                              // "2026-05-20T15:00:00Z"
$startLocal = TimezoneConverter::formatLocal($utc->setTimezone(new \DateTimeZone($timezone)));  // "2026-05-20T10:00:00"
```

## List Events: Dynamic Timezone Conversion

The `?timezone=` query parameter converts all events to the client's timezone on the fly:

```php
$viewTz = isset($params['timezone']) && $params['timezone'] !== '' ? $params['timezone'] : null;

$items = array_map(static function (Event $e) use ($viewTz): array {
    $data = $e->toArray();
    if ($viewTz !== null) {
        try {
            $local = TimezoneConverter::utcToLocal($e->startUtc, $viewTz);
            $data['start_local'] = TimezoneConverter::formatLocal($local);
            $data['view_timezone'] = $viewTz;
        } catch (InvalidTimezoneException) {
            // Invalid view timezone: silently return UTC
            $data['view_timezone'] = 'UTC';
        }
    }
    return $data;
}, $events);
```

Invalid `?timezone=` values silently fall back to the stored `start_local` rather than returning an error — a design choice appropriate for read-only views.

## UTC Format: ISO 8601 with Z Suffix

```php
public static function formatUtc(\DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    //                                                                           ^ literal Z
}
```

The `Z` suffix explicitly indicates UTC (per ISO 8601 / RFC 3339). Using `+00:00` or omitting the offset are acceptable alternatives, but `Z` is more compact and universally recognized.

## DST-Safe Conversion

```
Example: Asia/Tokyo is UTC+9 (no DST)
Local: 2026-05-20T10:00:00  Asia/Tokyo
UTC:   2026-05-20T01:00:00Z

Example: America/New_York (DST)
Local: 2026-05-20T10:00:00  America/New_York (EDT = UTC-4 in summer)
UTC:   2026-05-20T14:00:00Z

Local: 2026-01-20T10:00:00  America/New_York (EST = UTC-5 in winter)
UTC:   2026-01-20T15:00:00Z
```

`DateTimeImmutable` with a named IANA timezone automatically handles DST. It uses the offset active on that specific date, not a fixed offset.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store local time without timezone column | Cannot convert to UTC later; historical data becomes ambiguous after DST changes |
| Accept `EST`, `PST`, `GMT+5` as timezone | Ambiguous abbreviations; some map to multiple IANA zones; `DateTimeZone::listIdentifiers()` rejects these |
| Use `new DateTimeZone($tz)` without checking `listIdentifiers()` | PHP silently accepts some invalid or deprecated identifiers; canonical validation catches them |
| Store UTC offset (`+09:00`) instead of IANA name | Offset alone cannot handle DST; `Asia/Tokyo` always +9 but `America/New_York` varies |
| Sort events by `start_local` | Lexicographic sort on local times ignores timezone differences; always sort by `start_utc` |
| Convert timezone on every query | Expensive for large datasets; consider caching or pre-computing common view timezones |
| Return 422 for invalid `?timezone=` in GET | Read-only queries should degrade gracefully; fall back to UTC rather than error |
| Use `date()` instead of `DateTimeImmutable` | `date()` uses server's default timezone; `DateTimeImmutable` with explicit zones is predictable |
