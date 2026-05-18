<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Reports the health of a single dependency (e.g. database, cache, external API).
 *
 * Implement this interface and pass instances to RuntimeApplicationFactory to extend
 * the GET /health endpoint with dependency-level checks.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface HealthCheckInterface
{
    /** A short, machine-readable name used as the key in the health response (e.g. "database"). */
    public function name(): string;

    public function check(): HealthStatus;
}
