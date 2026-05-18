<?php

declare(strict_types=1);

namespace Nene2\Config;

use InvalidArgumentException;

/**
 * Thrown by {@see ConfigLoader} when a required environment variable is missing or invalid.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class ConfigException extends InvalidArgumentException
{
}
