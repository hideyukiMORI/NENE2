<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security-related HTTP response headers (e.g. `Content-Security-Policy`, `X-Frame-Options`).
 * Headers are injected before the pipeline processes the request so they appear on error responses too.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, string> $headers Override or extend the default security headers.
     *                                        CSP is set to `default-src 'self'` by default; tighten
     *                                        this for apps that load scripts/styles from external origins.
     */
    public function __construct(
        private array $headers = [
            'Content-Security-Policy' => "default-src 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        ],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
