<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Minimal clock abstraction for injecting time dependencies.
 *
 * Implement this interface in tests to return a fixed instant and eliminate
 * flakiness caused by real-time drift.
 *
 * ```php
 * // Production
 * $clock = new UtcClock();
 *
 * // Test
 * $clock = new class implements ClockInterface {
 *     public function now(): \DateTimeImmutable {
 *         return new \DateTimeImmutable('2026-01-01T00:00:00Z');
 *     }
 * };
 * ```
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface ClockInterface
{
    /**
     * Returns the current time as an immutable datetime object.
     *
     * Implementations MUST return a value in UTC.
     */
    public function now(): \DateTimeImmutable;
}
