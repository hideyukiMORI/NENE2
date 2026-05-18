<?php

declare(strict_types=1);

namespace Nene2\Middleware;

/**
 * @internal
 *
 * In-memory rate limit storage for local development and testing.
 * NOT safe for production: state is not shared between PHP-FPM processes.
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
