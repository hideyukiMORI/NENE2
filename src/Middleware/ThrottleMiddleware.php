<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fixed-window rate limiting middleware.
 *
 * Adds X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, and Retry-After
 * headers to every response. Returns 429 Problem Details when the limit is exceeded.
 *
 * The default key extractor uses REMOTE_ADDR. Pass a custom callable to key by
 * authenticated user, API key, or any other request attribute.
 *
 * **Production requirement**: inject a shared storage implementation (Redis, Memcached,
 * or a database-backed store) via {@see RateLimitStorageInterface}. The bundled
 * {@see InMemoryRateLimitStorage} does NOT share state across PHP-FPM worker processes
 * and is therefore only suitable for local development and single-process testing.
 *
 * Part of the public API stability guarantee (see ADR 0009, ADR 0010).
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    /** @var \Closure(ServerRequestInterface): string */
    private \Closure $keyExtractor;

    /**
     * @param \Closure(ServerRequestInterface): string|null $keyExtractor
     *   Extracts the rate limit key from the request. Defaults to REMOTE_ADDR.
     */
    public function __construct(
        private readonly ProblemDetailsResponseFactory $problemDetails,
        private readonly RateLimitStorageInterface $storage,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
        ?\Closure $keyExtractor = null,
    ) {
        $this->keyExtractor = $keyExtractor ?? static function (ServerRequestInterface $request): string {
            $params = $request->getServerParams();

            return 'ip:' . ($params['REMOTE_ADDR'] ?? 'unknown');
        };
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = ($this->keyExtractor)($request);
        $result = $this->storage->hit($key, $this->windowSeconds);

        $count = $result['count'];
        $resetAt = $result['reset_at'];
        $remaining = max(0, $this->limit - $count);
        $retryAfter = max(0, $resetAt - time());

        if ($count > $this->limit) {
            return $this->problemDetails->create(
                $request,
                'too-many-requests',
                'Too Many Requests',
                429,
                sprintf(
                    'Rate limit of %d requests per %d seconds exceeded. Try again in %d seconds.',
                    $this->limit,
                    $this->windowSeconds,
                    $retryAfter,
                ),
            )
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $this->limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string) $resetAt);
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $this->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) $resetAt);
    }
}
