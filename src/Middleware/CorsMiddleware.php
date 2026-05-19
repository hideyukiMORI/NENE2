<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handles CORS preflight requests and adds `Access-Control-*` headers to responses.
 * Allowed origins are injected at construction time; use an explicit allowlist in production.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins Exact origins to allow (e.g. `['https://app.example.com']`).
     *                                     An empty list disables CORS headers entirely.
     *                                     Do NOT pass `['*']` — that matches only the literal string `*`
     *                                     (no browser sends that as an Origin) and effectively blocks all
     *                                     CORS. To open all origins, list each one explicitly or implement
     *                                     a custom middleware that echoes the request Origin unconditionally.
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param int $maxAge Seconds the browser may cache the preflight response
     *                   (`Access-Control-Max-Age`). Must be a positive integer. Defaults to 3600 (1 hour).
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array $allowedOrigins = [],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Request-Id'],
        private bool $allowCredentials = false,
        private int $maxAge = 3600,
    ) {
        if (in_array('*', $allowedOrigins, true)) {
            throw new \InvalidArgumentException(
                'CorsMiddleware: do not pass \'*\' in $allowedOrigins. '
                . 'It matches only the literal string "*" as an Origin (no browser sends this). '
                . 'List each allowed origin explicitly instead.',
            );
        }

        if ($maxAge <= 0) {
            throw new \InvalidArgumentException(
                sprintf('CorsMiddleware: $maxAge must be a positive integer, got %d.', $maxAge),
            );
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isPreflightRequest($request)) {
            return $this->addCorsHeaders($request, $this->responseFactory->createResponse(204), preflight: true);
        }

        return $this->addCorsHeaders($request, $handler->handle($request), preflight: false);
    }

    private function isPreflightRequest(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Origin')
            && $request->hasHeader('Access-Control-Request-Method');
    }

    private function addCorsHeaders(
        ServerRequestInterface $request,
        ResponseInterface $response,
        bool $preflight,
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');
        $response = $response->withHeader('Vary', 'Origin');

        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

        if ($preflight) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
