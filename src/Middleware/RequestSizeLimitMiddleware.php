<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

        return $handler->handle($request);
    }

    private function isOversized(string $contentLength): bool
    {
        if (preg_match('/\A\d+\z/', $contentLength) !== 1) {
            return false;
        }

        return (int) $contentLength > $this->maxBodyBytes;
    }
}
