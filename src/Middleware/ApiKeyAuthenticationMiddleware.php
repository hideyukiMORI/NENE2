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
 * Path matching modes:
 *
 * 0. **Exclude list** (`$excludedPaths`): paths that are always public regardless of other settings.
 *    Useful with "protect all" mode: pass `excludedPaths: ['/health', '/']` to keep those open.
 * 1. **Allowlist** (`$protectedPaths` + `$protectedPathPrefixes`, union): when either or both are
 *    non-empty, a request is protected if its path matches any entry in `$protectedPaths` (exact)
 *    OR starts with any entry in `$protectedPathPrefixes` (prefix). Both lists are evaluated
 *    together — setting one does not suppress the other.
 * 2. **Protect all** (default): both lists empty → every path requires an API key.
 *
 * In all modes, `OPTIONS` requests are always skipped (CORS preflight).
 *
 * Method filtering (`$protectedMethods`): when non-empty, only requests whose HTTP method appears
 * in the list are protected. Use this to require an API key only for state-changing operations
 * (POST, PUT, PATCH, DELETE) while leaving reads open. `HEAD` is normalized to `GET` before the
 * check — because the router serves HEAD via the GET handler, listing `GET` also protects `HEAD`
 * (and listing only `HEAD` has no effect). OPTIONS is always excluded.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class ApiKeyAuthenticationMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedPaths        Exact paths to protect. Combined with $protectedPathPrefixes
     *                                             in union mode — both lists are checked and either can match.
     * @param list<string> $protectedPathPrefixes  Path prefixes to protect (e.g. `/admin/` matches `/admin/users/42`).
     *                                             Combined with $protectedPaths in union mode.
     * @param list<string> $protectedMethods       HTTP methods to protect (uppercase). When non-empty, only requests
     *                                             whose method appears here are protected. Useful to require an API key
     *                                             for POST/PUT/DELETE while leaving reads open. HEAD is normalized to GET
     *                                             (listing GET also protects HEAD); OPTIONS is always excluded.
     * @param list<string> $excludedPaths          Exact paths that are always public, checked before any protect mode.
     *                                             Combine with the "protect all" default to make specific paths public:
     *                                             `excludedPaths: ['/', '/health', '/examples/ping']`.
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private ?string $expectedApiKey,
        private array $protectedPaths = [],
        private string $headerName = 'X-NENE2-API-Key',
        private array $protectedPathPrefixes = [],
        private array $protectedMethods = [],
        private array $excludedPaths = [],
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

        // The router serves HEAD through the GET handler (RFC 7231 §4.3.2), so HEAD
        // must be gated exactly like GET. Normalizing here closes the bypass where a
        // `protectedMethods: ['GET']` config would otherwise let an unauthenticated
        // HEAD reach — and return the body of — the protected GET handler.
        $effectiveMethod = $method === 'HEAD' ? 'GET' : $method;

        if ($this->protectedMethods !== [] && !in_array($effectiveMethod, $this->protectedMethods, true)) {
            return false;
        }

        $path = $request->getUri()->getPath() ?: '/';

        if ($this->excludedPaths !== [] && in_array($path, $this->excludedPaths, true)) {
            return false;
        }

        // Union mode: when either list is non-empty, protect if path matches any exact entry
        // OR starts with any prefix. Both lists are evaluated together.
        if ($this->protectedPaths !== [] || $this->protectedPathPrefixes !== []) {
            if (in_array($path, $this->protectedPaths, true)) {
                return true;
            }

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
