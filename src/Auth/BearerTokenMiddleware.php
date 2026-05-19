<?php

declare(strict_types=1);

namespace Nene2\Auth;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates the `Authorization: Bearer <token>` header using a {@see TokenVerifierInterface}.
 * Returns 401 Problem Details if the header is absent or the token fails verification.
 *
 * Path matching modes (evaluated in priority order — first match wins):
 *
 * 1. **Allowlist** (`$protectedPaths`): only the listed exact paths are protected.
 * 2. **Prefix allowlist** (`$protectedPathPrefixes`): paths starting with any listed prefix are protected.
 *    Ideal for dynamic routes such as `/me/favorites/{id}` → prefix `/me/`.
 * 3. **Blocklist** (`$excludedPaths`): all paths are protected *except* the listed exact paths.
 *    Ideal when public paths (e.g. `/auth/register`, `/auth/login`) are the minority.
 * 4. **Protect all** (default): all four arrays empty → every path requires a token.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class BearerTokenMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedPaths        Exact paths to protect (allowlist). Takes precedence over all other modes.
     * @param list<string> $protectedPathPrefixes  Path prefixes to protect (prefix allowlist). Matches e.g. `/me/` → `/me/favorites/1`.
     * @param list<string> $excludedPaths          Exact paths to skip authentication (blocklist). Ignored when $protectedPaths or $protectedPathPrefixes is non-empty.
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private TokenVerifierInterface $verifier,
        private array $protectedPaths = [],
        private array $protectedPathPrefixes = [],
        private array $excludedPaths = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->requiresAuthentication($request)) {
            return $handler->handle($request);
        }

        $authorization = $request->getHeaderLine('Authorization');

        if ($authorization === '') {
            return $this->unauthorized($request, 'missing_token', 'No Bearer token was provided.');
        }

        if (!str_starts_with($authorization, 'Bearer ')) {
            return $this->unauthorized($request, 'invalid_token', 'Authorization header must use the Bearer scheme.');
        }

        $token = substr($authorization, 7);

        try {
            $claims = $this->verifier->verify($token);
        } catch (TokenVerificationException $e) {
            return $this->unauthorized($request, 'invalid_token', $e->getMessage());
        }

        return $handler->handle(
            $request
                ->withAttribute('nene2.auth.credential_type', 'bearer')
                ->withAttribute('nene2.auth.claims', $claims),
        );
    }

    private function requiresAuthentication(ServerRequestInterface $request): bool
    {
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

        if ($this->excludedPaths !== []) {
            return !in_array($path, $this->excludedPaths, true);
        }

        return true;
    }

    private function unauthorized(ServerRequestInterface $request, string $error, string $description): ResponseInterface
    {
        return $this->problemDetails
            ->create($request, 'unauthorized', 'Unauthorized', 401, $description)
            ->withHeader(
                'WWW-Authenticate',
                sprintf('Bearer realm="NENE2", error="%s", error_description="%s"', $error, $description),
            );
    }
}
