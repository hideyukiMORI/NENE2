<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array $allowedOrigins = [],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Request-Id'],
        private bool $allowCredentials = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isPreflightRequest($request)) {
            return $this->addCorsHeaders($request, $this->responseFactory->createResponse(204));
        }

        return $this->addCorsHeaders($request, $handler->handle($request));
    }

    private function isPreflightRequest(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Origin')
            && $request->hasHeader('Access-Control-Request-Method');
    }

    private function addCorsHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $response = $response->withHeader('Vary', 'Origin');

        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
