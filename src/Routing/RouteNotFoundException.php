<?php

declare(strict_types=1);

namespace Nene2\Routing;

use RuntimeException;

/**
 * Thrown by {@see Router} when no registered route matches the request path.
 * ErrorHandlerMiddleware maps this to a 404 Problem Details response.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class RouteNotFoundException extends RuntimeException
{
}
