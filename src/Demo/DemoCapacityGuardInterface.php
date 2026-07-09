<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Pre-creation guards for the demo start endpoint.
 *
 * Sweeping alone only caps the steady state — between sweeps, an attacker (or a
 * crawler) can grow the tenant table without bound. These checks run BEFORE any
 * organization is created (the invoice #608 root fix):
 *
 * - {@see assertHasCapacity()} enforces the instance-wide ceiling
 *   ({@see DemoConfig::$maxOrgs}) on existing demo organizations;
 * - {@see assertNotThrottled()} enforces a per-client (per-IP by default) rate
 *   on demo starts.
 *
 * {@see CountingDemoCapacityGuard} is the framework default; implement this
 * interface directly for custom policies.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DemoCapacityGuardInterface
{
    /**
     * @throws DemoCapacityExceededException when the demo org ceiling is reached.
     */
    public function assertHasCapacity(): void;

    /**
     * @throws DemoThrottledException when this client must wait before starting another demo.
     */
    public function assertNotThrottled(ServerRequestInterface $request): void;
}
