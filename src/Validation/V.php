<?php

declare(strict_types=1);

namespace Nene2\Validation;

use DateTimeImmutable;

/**
 * Stateless HTTP request parameter validation helpers.
 *
 * Design principles:
 *  - All methods return null on invalid input; callers return 422.
 *  - No framework dependencies — suitable for extraction as a standalone package.
 *  - ReDoS-immune: ctype_digit() (O(n)) instead of regex for integer validation.
 *  - Integer overflow guard: strlen > 18 before (int) cast.
 *  - Absent vs invalid distinction: queryInt() returns $default when the key
 *    is missing, null only when the key is present but invalid.
 */
final class V
{
    /**
     * Validates an integer from a URL query string (PSR-7 getQueryParams()).
     *
     * - Key absent              → returns $default (not an error)
     * - Key present and valid   → returns int in [$min, $max]
     * - Key present and invalid → returns null (caller should return 422)
     *
     * Uses ctype_digit() (O(n), ReDoS-immune) and a strlen > 18 overflow guard
     * to prevent silent PHP integer wrap on 20+ digit inputs.
     *
     * @param array<string, mixed> $params  Result of $request->getQueryParams()
     * @param string               $key     Parameter name
     * @param int                  $min     Inclusive lower bound
     * @param int                  $max     Inclusive upper bound
     * @param int|null             $default Returned when the key is absent
     */
    public static function queryInt(
        array $params,
        string $key,
        int $min,
        int $max,
        ?int $default = null,
    ): ?int {
        if (!array_key_exists($key, $params)) {
            return $default;
        }

        $raw = $params[$key];

        // ctype_digit('') === false — empty string already rejected here.
        // Also rejects: '-1', '+10', '10.5', '1e2', ' 10', '0x10', 'abc'.
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }

        // Prevent silent PHP overflow: (int)'99999999999999999999' wraps to -1 on 64-bit.
        // 18 chars safely covers PHP_INT_MAX (19 digits) with one digit of margin.
        if (strlen($raw) > 18) {
            return null;
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            return null;
        }

        return $value;
    }

    /**
     * Validates a strict integer from a JSON body (json_decode() output).
     *
     * Accepts only PHP int — rejects string "2", float 2.5, null, and bool.
     * Note: json_encode(['x' => 2.0]) produces {"x":2}, so 2.0 and 2 are
     * indistinguishable at the JSON level; use bodyInt() to catch string/bool/null.
     *
     * Returns null on type mismatch or out-of-range.
     *
     * @param int $min Inclusive lower bound
     * @param int $max Inclusive upper bound
     */
    public static function bodyInt(mixed $raw, int $min, int $max): ?int
    {
        if (!is_int($raw)) {
            return null;
        }

        if ($raw < $min || $raw > $max) {
            return null;
        }

        return $raw;
    }

    /**
     * Validates and trims a string field from any source.
     *
     * - Non-string input      → null
     * - Exceeds $maxLen bytes → null (checked after trim)
     * - Valid                 → trimmed string (may be empty; callers check for '')
     *
     * @param int $maxLen Maximum allowed byte length after trimming (default: 500)
     */
    public static function str(mixed $raw, int $maxLen = 500): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return strlen($trimmed) <= $maxLen ? $trimmed : null;
    }

    /**
     * Validates an ISO 8601 datetime string with explicit timezone offset.
     *
     * Accepted format: 2024-01-15T12:30:00+09:00
     *
     * Rejected: date-only, UTC 'Z' suffix, missing offset, overflow dates
     * (e.g., Feb 30 — caught by round-trip re-format comparison).
     *
     * Uses DateTimeImmutable::createFromFormat() which preserves the input
     * timezone for re-formatting — avoids the strtotime + date() pitfall where
     * date() silently re-formats in the server's local timezone.
     *
     * Returns the canonical string unchanged, or null on any failure.
     */
    public static function isoDatetime(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        // Strict regex: full datetime with explicit ±HH:MM offset required.
        // Rejects: date-only, 'Z' suffix, missing offset, float/sci notation.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $raw)) {
            return null;
        }

        // Reject out-of-range UTC offsets. The regex alone accepts e.g. "+25:00"
        // or "+99:00" (hours 00-99); every real timezone offset is within ±14:00.
        // (Offset minutes ≥ 60 are already rejected by the round-trip below.)
        $offsetTotalMinutes = ((int) substr($raw, -5, 2)) * 60 + (int) substr($raw, -2);
        if ($offsetTotalMinutes > 14 * 60) {
            return null;
        }

        // createFromFormat preserves the input timezone offset; format() re-emits it.
        $dt = DateTimeImmutable::createFromFormat(DATE_ATOM, $raw);

        if ($dt === false) {
            return null;
        }

        // Round-trip comparison catches overflow dates that roll over silently:
        // "2024-02-30T00:00:00+00:00" → parsed as Mar 1 → canonical ≠ input → null.
        $canonical = $dt->format(DATE_ATOM);

        return $canonical === $raw ? $raw : null;
    }

    /**
     * Validates an ISO 8601 datetime that must be strictly after $now.
     *
     * $now should be the current moment as an ISO 8601 datetime string
     * (e.g., date('c')). The comparison is by instant via DateTimeImmutable, so
     * $raw and $now may carry different timezone offsets and still compare
     * correctly — e.g. "...T00:00:00+09:00" is correctly seen as earlier than the
     * same wall-clock time at "+00:00".
     *
     * Returns null if $raw is an invalid datetime, if $now cannot be parsed, or
     * if $raw is not strictly after $now.
     *
     * @param string $now Current moment as an ISO 8601 datetime string
     */
    public static function futureDatetime(mixed $raw, string $now): ?string
    {
        $dt = self::isoDatetime($raw);

        if ($dt === null) {
            return null;
        }

        try {
            // $dt is already validated by isoDatetime(); $now is parsed leniently
            // (it is the server-provided current time, not untrusted input).
            $target = new DateTimeImmutable($dt);
            $reference = new DateTimeImmutable($now);
        } catch (\Exception) {
            return null;
        }

        return $target > $reference ? $dt : null;
    }

    /**
     * Validates a backed enum value from raw JSON input.
     *
     * Accepts only string input — rejects int, null, bool, array.
     * Returns the matched enum case, or null for invalid cases.
     *
     * @template T of \BackedEnum
     * @param  class-string<T> $enumClass
     * @return T|null
     */
    public static function enum(mixed $raw, string $enumClass): ?\BackedEnum
    {
        if (!is_string($raw)) {
            return null;
        }

        return $enumClass::tryFrom($raw);
    }

    /**
     * Validates the X-User-Id HTTP header as a positive integer.
     *
     * Applies ctype_digit + overflow guard + > 0 check.
     * Returns null if the header is absent, non-numeric, overflowing, or zero.
     */
    public static function userId(string $header): ?int
    {
        // ctype_digit('') === false — empty string already rejected.
        if (!ctype_digit($header) || strlen($header) > 18) {
            return null;
        }

        $id = (int) $header;

        return $id > 0 ? $id : null;
    }

    /**
     * Compares an API secret/key using constant-time comparison (timing-safe).
     *
     * Returns false when $expected is empty — an unconfigured key disables access
     * rather than accidentally granting it.
     */
    public static function secret(string $expected, string $actual): bool
    {
        return $expected !== '' && hash_equals($expected, $actual);
    }
}
