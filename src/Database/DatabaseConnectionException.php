<?php

declare(strict_types=1);

namespace Nene2\Database;

use RuntimeException;

/**
 * Thrown when a database operation fails due to a connection or execution error.
 * Extended by DatabaseConstraintException for constraint violations.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
class DatabaseConnectionException extends RuntimeException
{
}
