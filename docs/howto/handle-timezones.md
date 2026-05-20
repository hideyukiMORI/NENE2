# How to handle timezones

PHP's timezone handling has several silent failure modes. This guide covers the patterns and pitfalls encountered in real NENE2 field trials.

## Always specify the timezone when creating `DateTimeImmutable`

`new DateTimeImmutable('now')` uses the server's `date.timezone` ini setting, which differs between environments. Always pass `UTC` explicitly for server-side timestamps:

```php
// Fragile — depends on server's date.timezone
$now = new \DateTimeImmutable('now');

// Correct — always UTC
$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
```

For stored timestamps, format as ISO8601 UTC:

```php
$now->format('Y-m-d\TH:i:s\Z') // → "2026-05-20T15:00:00Z"
```

## Validate IANA timezone identifiers explicitly

PHP's `DateTimeZone` constructor accepts timezone abbreviations like `"EST"` without throwing an exception, but they are not canonical IANA identifiers. `"America/New_York"` is the correct IANA form.

```php
// This succeeds — but "EST" is not an IANA identifier
$tz = new \DateTimeZone('EST'); // no exception!

// Correct validation:
try {
    $tz = new \DateTimeZone($input);
} catch (\Exception) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}

// Additional membership check for non-IANA abbreviations:
if (!in_array($input, \DateTimeZone::listIdentifiers(), true)) {
    throw new InvalidTimezoneException("Unknown timezone: $input");
}
```

Without the `listIdentifiers()` check, `"EST"`, `"PST"`, and similar abbreviations pass silently.

## Parse local datetime strings with `createFromFormat`

When accepting a local datetime from user input (without timezone offset), use `createFromFormat` with the explicit format and timezone:

```php
$tz    = new \DateTimeZone('Asia/Tokyo');
$local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', '2026-06-01T10:00:00', $tz);

if ($local === false) {
    // Invalid format — '2026/06/01 10:00', '2026-06-01', etc. all return false
    throw new \InvalidArgumentException('Invalid datetime format. Expected YYYY-MM-DDTHH:mm:ss.');
}
```

Prefer `createFromFormat` over `new DateTimeImmutable($str, $tz)` — the constructor is lenient and accepts many formats silently.

## Convert local time to UTC for storage

```php
$utc = $local->setTimezone(new \DateTimeZone('UTC'));
// Store as: $utc->format('Y-m-d\TH:i:s\Z')
```

Always store UTC in the database. Store the original timezone name alongside it so you can reconstruct the local time on retrieval.

## DST transition: ambiguous wall-clock times

During "fall back" transitions (e.g., `America/New_York` on the first Sunday of November), some wall-clock times occur twice:

- `2026-11-01 01:30 AM` exists in both EDT (UTC-4) and EST (UTC-5)

PHP resolves the ambiguity by choosing the **first occurrence** (summer/DST time):

```php
$dt = \DateTimeImmutable::createFromFormat(
    'Y-m-d\TH:i:s',
    '2026-11-01T01:30:00',
    new \DateTimeZone('America/New_York'),
);
// → 05:30 UTC (EDT = UTC-4), not 06:30 UTC (EST = UTC-5)
```

This matches the IANA standard. If your application needs to distinguish the two occurrences (e.g., for calendar systems), you must handle this at the application level — PHP does not expose an API for selecting the second occurrence.

## Complete local→UTC conversion pattern

```php
use Schedule\Event\InvalidTimezoneException;

function localToUtc(string $localDatetime, string $ianaTimezone): \DateTimeImmutable
{
    try {
        $tz = new \DateTimeZone($ianaTimezone);
    } catch (\Exception) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    // Reject non-IANA abbreviations (e.g. "EST")
    if (!in_array($ianaTimezone, \DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidTimezoneException("Unknown timezone: $ianaTimezone");
    }

    $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $localDatetime, $tz);

    if ($local === false) {
        throw new \InvalidArgumentException("Cannot parse datetime: $localDatetime");
    }

    return $local->setTimezone(new \DateTimeZone('UTC'));
}
```

## Multi-timezone list queries

When listing events stored in UTC, convert to a viewer's timezone on the way out:

```php
$viewTz = QueryStringParser::string($request, 'timezone');

if ($viewTz !== null) {
    try {
        $tz    = new \DateTimeZone($viewTz);
        $local = (new \DateTimeImmutable($event->startUtc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $data['start_local'] = $local->format('Y-m-d\TH:i:s');
    } catch (\Exception) {
        // Invalid requested timezone — silently omit the conversion
    }
}
```
