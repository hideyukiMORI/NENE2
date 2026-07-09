<?php

declare(strict_types=1);

namespace Nene2\Demo;

use RuntimeException;

/**
 * Thrown by a {@see DemoCapacityGuardInterface} when a single client (keyed by IP
 * by default) starts demos faster than the configured per-client rate. Distinct
 * from {@see DemoCapacityExceededException}: throttling is a per-client 429 with
 * a Retry-After hint, capacity exhaustion is an instance-wide 503.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class DemoThrottledException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }
}
