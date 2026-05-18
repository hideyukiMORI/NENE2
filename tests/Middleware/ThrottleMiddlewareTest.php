<?php

declare(strict_types=1);

namespace Nene2\Tests\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ThrottleMiddlewareTest extends TestCase
{
    private function makeHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory();

                return $factory->createResponse(200)
                    ->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
        };
    }

    public function testRequestUnderLimitPassesThrough(): void
    {
        $factory = new Psr17Factory();
        $storage = new InMemoryRateLimitStorage();
        $problemDetails = new ProblemDetailsResponseFactory($factory, $factory);
        $middleware = new ThrottleMiddleware($problemDetails, $storage, limit: 5, windowSeconds: 60);

        $request = $factory->createServerRequest('GET', 'https://example.test/health', ['REMOTE_ADDR' => '127.0.0.1']);

        $response = $middleware->process($request, $this->makeHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('4', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotEmpty($response->getHeaderLine('X-RateLimit-Reset'));
    }

    public function testRequestAtLimitPassesThrough(): void
    {
        $factory = new Psr17Factory();
        $storage = new InMemoryRateLimitStorage();
        $problemDetails = new ProblemDetailsResponseFactory($factory, $factory);
        $middleware = new ThrottleMiddleware($problemDetails, $storage, limit: 3, windowSeconds: 60);

        $request = $factory->createServerRequest('GET', 'https://example.test/health', ['REMOTE_ADDR' => '127.0.0.1']);

        $response = null;

        for ($i = 0; $i < 3; $i++) {
            $response = $middleware->process($request, $this->makeHandler());
        }

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('3', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testRequestOverLimitReturns429(): void
    {
        $factory = new Psr17Factory();
        $storage = new InMemoryRateLimitStorage();
        $problemDetails = new ProblemDetailsResponseFactory($factory, $factory);
        $middleware = new ThrottleMiddleware($problemDetails, $storage, limit: 2, windowSeconds: 60);

        $request = $factory->createServerRequest('GET', 'https://example.test/health', ['REMOTE_ADDR' => '127.0.0.1']);

        for ($i = 0; $i < 2; $i++) {
            $middleware->process($request, $this->makeHandler());
        }

        $response = $middleware->process($request, $this->makeHandler());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/too-many-requests', $payload['type']);
        self::assertSame('Too Many Requests', $payload['title']);
        self::assertSame(429, $payload['status']);
        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
        self::assertNotEmpty($response->getHeaderLine('X-RateLimit-Reset'));
    }

    public function testDifferentClientsHaveIndependentCounters(): void
    {
        $factory = new Psr17Factory();
        $storage = new InMemoryRateLimitStorage();
        $problemDetails = new ProblemDetailsResponseFactory($factory, $factory);
        $middleware = new ThrottleMiddleware($problemDetails, $storage, limit: 1, windowSeconds: 60);

        $request1 = $factory->createServerRequest('GET', 'https://example.test/health', ['REMOTE_ADDR' => '10.0.0.1']);
        $request2 = $factory->createServerRequest('GET', 'https://example.test/health', ['REMOTE_ADDR' => '10.0.0.2']);

        $middleware->process($request1, $this->makeHandler());
        $response2 = $middleware->process($request2, $this->makeHandler());

        self::assertSame(200, $response2->getStatusCode());
        self::assertSame('0', $response2->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testCustomKeyExtractor(): void
    {
        $factory = new Psr17Factory();
        $storage = new InMemoryRateLimitStorage();
        $problemDetails = new ProblemDetailsResponseFactory($factory, $factory);
        $middleware = new ThrottleMiddleware(
            $problemDetails,
            $storage,
            limit: 1,
            windowSeconds: 60,
            keyExtractor: static fn (ServerRequestInterface $r): string => 'user:' . ($r->getHeaderLine('X-User-Id') ?: 'anon'),
        );

        $requestA = $factory->createServerRequest('GET', 'https://example.test/')
            ->withHeader('X-User-Id', 'alice');
        $requestB = $factory->createServerRequest('GET', 'https://example.test/')
            ->withHeader('X-User-Id', 'bob');

        $middleware->process($requestA, $this->makeHandler());
        $response = $middleware->process($requestB, $this->makeHandler());

        self::assertSame(200, $response->getStatusCode());
    }
}
