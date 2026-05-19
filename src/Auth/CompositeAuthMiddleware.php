<?php

declare(strict_types=1);

namespace Nene2\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Chains multiple authentication middlewares into a single PSR-15 middleware.
 *
 * Each middleware in the chain handles only the paths it is configured for.
 * When a middleware determines that the current request does not require
 * authentication (based on its path/prefix/exclusion settings), it passes
 * the request to the next entry in the chain automatically.
 *
 * Typical use case — three-tier access model:
 *
 * ```php
 * $authMiddleware = new CompositeAuthMiddleware([
 *     new BearerTokenMiddleware($problemDetails, $verifier, protectedPathPrefixes: ['/me/']),
 *     new ApiKeyAuthenticationMiddleware($problemDetails, $apiKey, protectedPaths: ['/admin/...']),
 * ]);
 * ```
 *
 * Evaluation order:
 * 1. Each middleware processes the request in the order it appears in `$middlewares`.
 * 2. If a middleware decides the path is not its responsibility, it calls the next handler,
 *    which forwards to the subsequent middleware.
 * 3. The final handler after all middlewares is the original downstream handler.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class CompositeAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param list<MiddlewareInterface> $middlewares Authentication middlewares to compose, evaluated in order.
     */
    public function __construct(private array $middlewares)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->buildChain(0, $handler)->handle($request);
    }

    private function buildChain(int $index, RequestHandlerInterface $tail): RequestHandlerInterface
    {
        if ($index >= count($this->middlewares)) {
            return $tail;
        }

        $middleware = $this->middlewares[$index];
        $next = $this->buildChain($index + 1, $tail);

        return new class ($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
