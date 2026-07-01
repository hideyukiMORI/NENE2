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
 * `Strict-Transport-Security` (HSTS) is **opt-in** (`$enableHsts`): it must only be sent on
 * deployments where TLS is terminated at or in front of this application, so it is off by default
 * to avoid pinning HTTPS on plain-HTTP local/dev setups. Browsers ignore an HSTS header received
 * over plain HTTP (RFC 6797 §7.2), so enabling it is safe once the app is served over HTTPS.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, string> $headers Override or extend the default security headers.
     *                                        CSP is set to `default-src 'self'` by default; tighten
     *                                        this for apps that load scripts/styles from external origins.
     *                                        `Referrer-Policy` defaults to `strict-origin-when-cross-origin`
     *                                        so full URLs (with path/query) are not sent as `Referer`
     *                                        on cross-origin navigations.
     * @param bool   $enableHsts Emit `Strict-Transport-Security`. Enable only when the app is served
     *                           over HTTPS (directly or behind a TLS-terminating proxy).
     * @param string $hstsValue  The HSTS header value used when `$enableHsts` is true. Defaults to one
     *                           year with `includeSubDomains`. Add `; preload` only after submitting to
     *                           the preload list.
     */
    public function __construct(
        private array $headers = [
            'Content-Security-Policy' => "default-src 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        ],
        private bool $enableHsts = false,
        private string $hstsValue = 'max-age=31536000; includeSubDomains',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $headers = $this->headers;

        if ($this->enableHsts) {
            $headers['Strict-Transport-Security'] ??= $this->hstsValue;
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
