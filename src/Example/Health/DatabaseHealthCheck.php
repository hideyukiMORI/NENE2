<?php

declare(strict_types=1);

namespace Nene2\Example\Health;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\HealthStatus;
use Throwable;

/**
 * Reference implementation of HealthCheckInterface that pings the database.
 *
 * Copy and adapt this class into your own application; do not import it as a
 * library dependency — it is outside the public API stability guarantee.
 */
final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private DatabaseConnectionFactoryInterface $connectionFactory,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthStatus
    {
        try {
            $pdo = $this->connectionFactory->create();
            $pdo->query('SELECT 1');

            return HealthStatus::Ok;
        } catch (Throwable) {
            return HealthStatus::Error;
        }
    }
}
