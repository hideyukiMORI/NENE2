<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects requests whose body exceeds a configured byte limit with a 413 Problem Details response.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class RequestSizeLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private int $maxBodyBytes = 1_048_576,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');

        if ($contentLength !== '' && $this->isOversized($contentLength)) {
            return $this->tooLarge($request);
        }

        if ($contentLength === '') {
            $bodySize = $request->getBody()->getSize();

            if ($bodySize !== null && $bodySize > $this->maxBodyBytes) {
                return $this->tooLarge($request);
            }
        }

        return $handler->handle($request);
    }

    private function tooLarge(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create(
            $request,
            'payload-too-large',
            'Payload Too Large',
            413,
            'The request body exceeds the configured size limit.',
            [
                'max_body_bytes' => $this->maxBodyBytes,
            ],
        );
    }

    private function isOversized(string $contentLength): bool
    {
        if (preg_match('/\A\d+\z/', $contentLength) !== 1) {
            return false;
        }

        return (int) $contentLength > $this->maxBodyBytes;
    }
}
