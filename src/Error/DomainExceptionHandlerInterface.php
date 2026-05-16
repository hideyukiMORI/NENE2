<?php

declare(strict_types=1);

namespace Nene2\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

interface DomainExceptionHandlerInterface
{
    public function supports(Throwable $exception): bool;

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface;
}
