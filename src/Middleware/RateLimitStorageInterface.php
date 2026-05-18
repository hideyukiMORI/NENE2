<?php

declare(strict_types=1);

namespace Nene2\Middleware;

/**
 * Stores and retrieves rate limit counters for a fixed-window algorithm.
 *
 * Each key maps to a hit count and an expiry timestamp. Implement this interface
 * to back ThrottleMiddleware with Redis, Memcached, or another shared store.
 *
 * The shipped InMemoryRateLimitStorage is for local development and testing only —
 * it does not persist across PHP-FPM processes.
 *
 * Part of the public API stability guarantee (see ADR 0009, ADR 0010).
 */
interface RateLimitStorageInterface
{
    /**
     * Increment the hit counter for $key within a $windowSeconds fixed window.
     *
     * If the key does not exist, create it with count 1 and set the window expiry.
     * If the key exists but the window has expired, reset count to 1 with a new expiry.
     *
     * @return array{count: int, reset_at: int} count after increment; Unix timestamp when the window resets
     */
    public function hit(string $key, int $windowSeconds): array;
}
