<?php

declare(strict_types=1);

namespace Nene2\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Maps a domain exception to an HTTP response.
 *
 * Register implementations with {@see ErrorHandlerMiddleware} to convert
 * domain-specific exceptions into RFC 9457 Problem Details responses without
 * adding try/catch blocks to individual handlers.
 */
interface DomainExceptionHandlerInterface
{
    /** Returns true if this handler can process the given exception. */
    public function supports(Throwable $exception): bool;

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface;
}
