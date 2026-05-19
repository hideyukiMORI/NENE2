<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates the `X-NENE2-API-Key` request header for machine client endpoints.
 * Returns 401 Problem Details when the key is absent or does not match `NENE2_MACHINE_API_KEY`.
 *
 * Path matching modes (evaluated in priority order — first match wins):
 *
 * 1. **Allowlist** (`$protectedPaths`): only the listed exact paths are protected.
 * 2. **Prefix allowlist** (`$protectedPathPrefixes`): paths starting with any listed prefix are protected.
 *    Useful for dynamic routes such as `/admin/users/42` → prefix `/admin/`.
 * 3. **Protect all** (default): both arrays empty → every path requires an API key.
 *
 * In all modes, `OPTIONS` requests are always skipped (CORS preflight).
 *
 * Method filtering (`$protectedMethods`): when non-empty, only requests whose HTTP method appears
 * in the list are protected. Use this to allow safe methods (GET, HEAD) while requiring an API
 * key only for state-changing operations (POST, PUT, PATCH, DELETE).
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class ApiKeyAuthenticationMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedPaths        Exact paths to protect. Takes precedence over $protectedPathPrefixes.
     * @param list<string> $protectedPathPrefixes  Path prefixes to protect (e.g. `/admin/` matches `/admin/users/42`).
     *                                             Evaluated only when $protectedPaths is empty.
     * @param list<string> $protectedMethods       HTTP methods to protect (uppercase). When non-empty, only requests
     *                                             whose method appears here are protected. Useful to allow GET while
     *                                             requiring an API key for POST/PUT/DELETE. OPTIONS is always excluded.
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private ?string $expectedApiKey,
        private array $protectedPaths = [],
        private string $headerName = 'X-NENE2-API-Key',
        private array $protectedPathPrefixes = [],
        private array $protectedMethods = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->requiresAuthentication($request)) {
            return $handler->handle($request);
        }

        if ($this->expectedApiKey === null) {
            return $this->unauthorized($request);
        }

        $providedApiKey = $request->getHeaderLine($this->headerName);

        if ($providedApiKey === '' || !hash_equals($this->expectedApiKey, $providedApiKey)) {
            return $this->unauthorized($request);
        }

        return $handler->handle($request->withAttribute('nene2.auth.credential_type', 'api_key'));
    }

    private function requiresAuthentication(ServerRequestInterface $request): bool
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'OPTIONS') {
            return false;
        }

        if ($this->protectedMethods !== [] && !in_array($method, $this->protectedMethods, true)) {
            return false;
        }

        $path = $request->getUri()->getPath() ?: '/';

        if ($this->protectedPaths !== []) {
            return in_array($path, $this->protectedPaths, true);
        }

        if ($this->protectedPathPrefixes !== []) {
            foreach ($this->protectedPathPrefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function unauthorized(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails
            ->create(
                $request,
                'unauthorized',
                'Unauthorized',
                401,
                'A valid API key is required for this endpoint.',
            )
            ->withHeader('WWW-Authenticate', 'ApiKey realm="NENE2", header="' . $this->headerName . '"');
    }
}
