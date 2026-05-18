<?php

declare(strict_types=1);

namespace Nene2\Database;

use RuntimeException;

/**
 * Thrown when a database connection cannot be established.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class DatabaseConnectionException extends RuntimeException
{
}
