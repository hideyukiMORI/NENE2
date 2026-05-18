<?php

declare(strict_types=1);

namespace Nene2\Database;

use PDO;

/**
 * Creates a PDO database connection.
 *
 * Implement this interface to swap the connection strategy (e.g. pooling,
 * test doubles) without changing the adapters that depend on it.
 */
interface DatabaseConnectionFactoryInterface
{
    public function create(): PDO;
}
