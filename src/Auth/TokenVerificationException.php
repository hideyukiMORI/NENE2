<?php

declare(strict_types=1);

namespace Nene2\Auth;

use RuntimeException;

/**
 * Thrown by {@see TokenVerifierInterface} implementations when a bearer token is invalid.
 * BearerTokenMiddleware maps this to a 401 Problem Details response.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class TokenVerificationException extends RuntimeException
{
}
