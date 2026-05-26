<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Production {@see ClockInterface} that always returns the current time in UTC.
 *
 * Inject {@see ClockInterface} into use cases and handlers that need the current
 * time. In tests, replace with an anonymous class or a simple stub that returns
 * a fixed instant — no mocking framework required.
 *
 * ```php
 * // Handler constructor
 * public function __construct(private readonly ClockInterface $clock) {}
 *
 * // Usage
 * $now       = $this->clock->now();              // DateTimeImmutable (UTC)
 * $nowIso    = $this->clock->nowIso();           // "2026-05-26T12:00:00Z"
 * $expiresAt = $this->clock->nowIso('+24 hours'); // ISO string 24 h from now
 * ```
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class UtcClock implements ClockInterface
{
    private static \DateTimeZone $utc;

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::utcZone());
    }

    /**
     * Returns the current UTC time as an ISO 8601 string (`Y-m-d\TH:i:s\Z`).
     *
     * Pass a `$modify` string (same format as {@see \DateTimeImmutable::modify()})
     * to shift the instant — e.g. `'+24 hours'`, `'-30 minutes'`.
     *
     * ```php
     * $now       = $clock->nowIso();            // "2026-05-26T12:00:00Z"
     * $expiresAt = $clock->nowIso('+1 hour');   // "2026-05-26T13:00:00Z"
     * ```
     */
    public function nowIso(string $modify = ''): string
    {
        $dt = $this->now();

        if ($modify !== '') {
            $dt = $dt->modify($modify);
        }

        return $dt->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Converts any {@see \DateTimeImmutable} to an ISO 8601 UTC string.
     *
     * Useful when you have a datetime from another source and need to normalise
     * it to the same format used throughout the application.
     *
     * ```php
     * UtcClock::toIso(new \DateTimeImmutable('2026-05-26T12:00:00+09:00'));
     * // → "2026-05-26T03:00:00Z"
     * ```
     */
    public static function toIso(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::utcZone())->format('Y-m-d\TH:i:s\Z');
    }

    private static function utcZone(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
