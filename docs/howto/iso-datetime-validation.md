# How to Validate ISO 8601 Datetimes with Timezone

Accepting user-controlled datetime strings requires careful validation. This guide covers
the two most important traps: **PHP silently accepting invalid timezone offsets**, and
**string comparison failing across different timezone offsets**.

---

## V::isoDatetime — Format Validation

```php
V::isoDatetime(mixed $raw): ?string
```

Validates a datetime string in `±HH:MM` offset format:

```
✅ 2024-01-15T12:30:00+09:00   (JST)
✅ 2024-06-01T00:00:00+00:00   (UTC)
✅ 2024-12-31T23:59:59-05:00   (EST)
✅ 2026-06-15T09:00:00-14:00   (UTC−14, Howland Island)
✅ 2026-06-15T09:00:00+14:00   (UTC+14, Kiribati)

❌ 2024-01-15                   (date only, no time)
❌ 2024-01-15T12:00:00Z         ('Z' suffix, not ±HH:MM)
❌ 2024-01-15T12:00:00          (no offset at all)
❌ 2024-02-30T00:00:00+00:00   (Feb 30 doesn't exist)
❌ 2024-13-01T00:00:00+00:00   (month 13 doesn't exist)
❌ 2026-06-15T09:00:00+25:00   (invalid offset — exceeds +14:00)
```

### Implementation

```php
public static function isoDatetime(mixed $raw): ?string
{
    if (!is_string($raw)) return null;

    // Strict regex: ±HH:MM required, no Z, no bare time
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Validate offset range: valid UTC offsets are −14:00 … +14:00.
    // PHP's DateTimeImmutable silently accepts +25:00 and similar invalid offsets.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];
    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }

    // DateTimeImmutable preserves the input timezone — avoids strtotime + date()
    // which silently re-formats in the server's local timezone.
    $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);
    if ($dt === false) return null;

    // Round-trip comparison catches overflow dates (Feb 30 rolls to Mar 1, etc.)
    return $dt->format(DATE_ATOM) === $raw ? $raw : null;
}
```

### Why not `strtotime` + `date()`?

```php
// ❌ WRONG — date() uses the server's local timezone
$ts = strtotime('2024-01-15T12:30:00+09:00');
$canonical = date('c', $ts);
// If server is UTC: '2024-01-15T03:30:00+00:00' — timezone lost!
```

```php
// ✅ CORRECT — DateTimeImmutable preserves the original offset
$dt = DateTimeImmutable::createFromFormat(DATE_ATOM, '2024-01-15T12:30:00+09:00');
$dt->format(DATE_ATOM); // → '2024-01-15T12:30:00+09:00' ✓
```

---

## V::futureDatetime — Future Check Across Timezones

```php
V::futureDatetime(mixed $raw, string $now): ?string
```

Returns the validated string only if the datetime is **strictly after** `$now`.

### The Critical Bug: String Comparison Fails Across Timezones

```php
$now  = '2026-06-01T10:00:00+00:00';  // UTC 10:00

// JST 18:00 = UTC 09:00 → 1 hour in the PAST
$pastJst = '2026-06-01T18:00:00+09:00';

// ❌ WRONG: string comparison says future ("T18" > "T10")
$pastJst > $now  // → TRUE   ← WRONG! It's in the past!

// ✅ CORRECT: DateTimeImmutable comparison normalises to UTC first
$dtObj = new DateTimeImmutable('2026-06-01T18:00:00+09:00');  // UTC 09:00
$nowObj = new DateTimeImmutable('2026-06-01T10:00:00+00:00');  // UTC 10:00
$dtObj > $nowObj  // → FALSE ✓ (correctly in the past)
```

The opposite error also occurs with negative offsets:

```php
// EST 08:00 = UTC 13:00 → 3 hours in the FUTURE
$futureEst = '2026-06-01T08:00:00-05:00';

// ❌ WRONG: string comparison says past ("T08" < "T10")
$futureEst > $now  // → FALSE  ← WRONG! It's in the future!

// ✅ CORRECT: object comparison
$dtObj = new DateTimeImmutable('2026-06-01T08:00:00-05:00');  // UTC 13:00
$dtObj > $nowObj  // → TRUE ✓ (correctly in the future)
```

### Implementation

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);
    if ($dt === null) return null;

    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) return null;

    // Object comparison normalises both to UTC before comparing.
    return $dtObj > $nowObj ? $dt : null;
}
```

### Usage in a Route Handler

```php
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // ...
    $rawRemindAt = $body['remind_at'] ?? null;

    if (!is_string($rawRemindAt)) {
        return $this->responseFactory->create(
            ['error' => 'remind_at is required (ISO 8601 with timezone, e.g. 2026-06-01T09:00:00+09:00).'],
            422,
        );
    }

    // Use DateTimeImmutable for a timezone-preserving "now"
    $now      = (new DateTimeImmutable())->format(DATE_ATOM);
    $remindAt = V::futureDatetime($rawRemindAt, $now);

    if ($remindAt === null) {
        return $this->responseFactory->create(
            ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone and must be in the future.'],
            422,
        );
    }

    // $remindAt is now safe to store — the exact submitted string, timezone preserved.
    $reminder = $this->repository->create($userId, $message, $remindAt, $now);
    // ...
}
```

---

## Timezone Preservation

Store `remind_at` (or any user-submitted datetime) exactly as validated — do not convert to UTC.

```php
// ✅ Store the validated string as-is
'INSERT INTO reminders (remind_at, ...) VALUES (:remind_at, ...)'
// with :remind_at = '2026-06-15T09:00:00+09:00'

// Return it unchanged in the API response
$reminder->remindAt  // → '2026-06-15T09:00:00+09:00'
```

This respects the user's intent and avoids implicit timezone conversion. If your application
needs UTC normalisation for ordering/comparison in SQL, add a separate `remind_at_utc` column
computed at write time.

---

## Validated Inputs → Safe SQL

After `V::isoDatetime()` / `V::futureDatetime()`, the string is safe to insert via a
parameterized query. Never interpolate raw datetime strings into SQL.

```php
// ✅ Safe — pre-validated, parameterized
$stmt->execute(['remind_at' => $remindAt]);

// ❌ Dangerous — raw user input interpolated
$sql = "INSERT INTO reminders (remind_at) VALUES ('{$_POST['remind_at']}')";
```

---

## Related

- FT181 — reminderlog: ISO 8601 Datetime Validation & Timezone-Aware API  
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) — Date and Time on the Internet  
- [IANA Time Zone Database](https://www.iana.org/time-zones) — UTC offset reference  
- `docs/howto/json-merge-patch.md` — also uses isoDatetime for created_at
