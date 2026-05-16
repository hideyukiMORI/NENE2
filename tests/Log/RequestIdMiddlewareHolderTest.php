<?php

declare(strict_types=1);

namespace Nene2\Tests\Log;

use Nene2\Log\RequestIdHolder;
use Nene2\Middleware\RequestIdMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddlewareHolderTest extends TestCase
{
    public function testPopulatesHolderWithRequestId(): void
    {
        $factory = new Psr17Factory();
        $holder = new RequestIdHolder();
        $middleware = new RequestIdMiddleware('X-Request-Id', $holder);

        $request = $factory->createServerRequest('GET', 'https://example.test/')
            ->withHeader('X-Request-Id', 'test-id-123');

        $handler = new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $middleware->process($request, $handler);

        self::assertSame('test-id-123', $holder->get());
    }

    public function testGeneratesAndPopulatesHolderWhenHeaderAbsent(): void
    {
        $factory = new Psr17Factory();
        $holder = new RequestIdHolder();
        $middleware = new RequestIdMiddleware('X-Request-Id', $holder);

        $request = $factory->createServerRequest('GET', 'https://example.test/');

        $handler = new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $middleware->process($request, $handler);

        self::assertNotEmpty($holder->get());
    }
}
