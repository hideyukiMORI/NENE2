<?php

declare(strict_types=1);

namespace Nene2\Http;

use RuntimeException;

/**
 * Thrown when the request body cannot be parsed as a JSON object.
 *
 * ErrorHandlerMiddleware maps this to a 400 Problem Details response with
 * type "invalid-json", keeping it distinct from 422 validation failures.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class JsonBodyParseException extends RuntimeException
{
}
