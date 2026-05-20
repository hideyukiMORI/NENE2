<?php

declare(strict_types=1);

namespace Nene2\Middleware;

/**
 * In-memory rate limit storage for local development and single-process testing.
 *
 * State is held in a plain PHP array and is therefore NOT shared between PHP-FPM
 * worker processes. For production, inject a shared implementation (Redis, Memcached,
 * or a database-backed store) via {@see RateLimitStorageInterface}.
 *
 * Typical test usage:
 *
 * ```php
 * $storage = new InMemoryRateLimitStorage();
 * $throttle = new ThrottleMiddleware($problemDetails, $storage, limit: 60, windowSeconds: 60);
 * ```
 *
 * Part of the public API stability guarantee (see ADR 0009, ADR 0010).
 */
final class InMemoryRateLimitStorage implements RateLimitStorageInterface
{
    /** @var array<string, array{count: int, reset_at: int}> */
    private array $store = [];

    public function hit(string $key, int $windowSeconds): array
    {
        $now = time();

        if (!isset($this->store[$key]) || $this->store[$key]['reset_at'] <= $now) {
            $this->store[$key] = [
                'count' => 1,
                'reset_at' => $now + $windowSeconds,
            ];
        } else {
            $this->store[$key]['count']++;
        }

        return $this->store[$key];
    }
}
