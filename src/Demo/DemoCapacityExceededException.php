<?php

declare(strict_types=1);

namespace Nene2\Demo;

use RuntimeException;

/**
 * Thrown by a {@see DemoCapacityGuardInterface} when the instance-wide disposable
 * demo organization count has reached {@see DemoConfig::$maxOrgs}, so demo starts
 * fail closed instead of growing the tenant table without bound (the invoice #608
 * gap: sweeping alone caps steady state, not burst growth between sweeps).
 *
 * {@see StartDisposableDemoHandler} maps this to a 503 Problem Details response.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class DemoCapacityExceededException extends RuntimeException
{
}
