<?php

declare(strict_types=1);

namespace Nene2\Routing;

use RuntimeException;

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
