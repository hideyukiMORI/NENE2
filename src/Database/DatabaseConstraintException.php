<?php

declare(strict_types=1);

namespace Nene2\Database;

/**
 * Thrown when a database constraint is violated (UNIQUE, FK, NOT NULL, CHECK).
 *
 * Extends DatabaseConnectionException so existing catch blocks are unaffected.
 * Catch this specific type to handle constraint violations (e.g. duplicate key)
 * without catching all connection errors.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class DatabaseConstraintException extends DatabaseConnectionException
{
}
