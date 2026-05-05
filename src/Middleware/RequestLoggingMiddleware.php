<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startedAt = hrtime(true);
        $response = $handler->handle($request);

        $this->logger->info('HTTP request handled.', [
            'request_id' => $this->requestId($request),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath() ?: '/',
            'status' => $response->getStatusCode(),
            'duration_ms' => $this->durationMilliseconds($startedAt),
        ]);

        return $response;
    }

    private function requestId(ServerRequestInterface $request): ?string
    {
        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    private function durationMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
