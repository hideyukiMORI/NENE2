<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Health check outcome reported by {@see HealthCheckInterface}.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
}
