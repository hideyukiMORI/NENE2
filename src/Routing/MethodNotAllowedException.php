<?php

declare(strict_types=1);

namespace Nene2\Routing;

use RuntimeException;

/**
 * Thrown by {@see Router} when the path matches but the HTTP method does not.
 * ErrorHandlerMiddleware maps this to a 405 Problem Details response.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param non-empty-list<string> $allowedMethods
     */
    public function __construct(
        private readonly array $allowedMethods,
    ) {
        parent::__construct('HTTP method is not allowed for this route.');
    }

    /**
     * @return non-empty-list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
