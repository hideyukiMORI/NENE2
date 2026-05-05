<?php

declare(strict_types=1);

namespace Nene2\Tests\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Middleware\CorsMiddleware;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Middleware\RequestSizeLimitMiddleware;
use Nene2\Middleware\SecurityHeadersMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BaselineMiddlewareTest extends TestCase
{
    public function testRequestIdPreservesSafeIncomingHeader(): void
    {
        $factory = new Psr17Factory();
        $middleware = new RequestIdMiddleware();
        $request = $factory
            ->createServerRequest('GET', 'https://example.test/')
            ->withHeader('X-Request-Id', 'client-request-123');

        $response = $middleware->process($request, $this->okHandler($factory));

        self::assertSame('client-request-123', $response->getHeaderLine('X-Request-Id'));
    }

    public function testRequestIdReplacesUnsafeIncomingHeader(): void
    {
        $factory = new Psr17Factory();
        $middleware = new RequestIdMiddleware();
        $request = $factory
            ->createServerRequest('GET', 'https://example.test/')
            ->withHeader('X-Request-Id', 'unsafe value');

        $response = $middleware->process($request, $this->okHandler($factory));

        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
    }

    public function testSecurityHeadersAreAddedWithoutOverwritingExistingHeaders(): void
    {
        $factory = new Psr17Factory();
        $middleware = new SecurityHeadersMiddleware();

        $response = $middleware->process(
            $factory->createServerRequest('GET', 'https://example.test/'),
            new class ($factory) implements RequestHandlerInterface {
                public function __construct(
                    private readonly Psr17Factory $factory,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->factory
                        ->createResponse(200)
                        ->withHeader('X-Frame-Options', 'DENY');
                }
            },
        );

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testCorsAddsHeadersForAllowedOrigin(): void
    {
        $factory = new Psr17Factory();
        $middleware = new CorsMiddleware($factory, ['https://app.example.test']);
        $request = $factory
            ->createServerRequest('GET', 'https://api.example.test/')
            ->withHeader('Origin', 'https://app.example.test');

        $response = $middleware->process($request, $this->okHandler($factory));

        self::assertSame('https://app.example.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testCorsDoesNotAllowUnconfiguredOrigin(): void
    {
        $factory = new Psr17Factory();
        $middleware = new CorsMiddleware($factory);
        $request = $factory
            ->createServerRequest('GET', 'https://api.example.test/')
            ->withHeader('Origin', 'https://app.example.test');

        $response = $middleware->process($request, $this->okHandler($factory));

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testCorsPreflightReturnsNoContentForAllowedOrigin(): void
    {
        $factory = new Psr17Factory();
        $middleware = new CorsMiddleware($factory, ['https://app.example.test']);
        $request = $factory
            ->createServerRequest('OPTIONS', 'https://api.example.test/')
            ->withHeader('Origin', 'https://app.example.test')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $response = $middleware->process($request, $this->okHandler($factory));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testRequestSizeLimitReturnsProblemDetailsForOversizedPayload(): void
    {
        $factory = new Psr17Factory();
        $middleware = new RequestSizeLimitMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            10,
        );
        $request = $factory
            ->createServerRequest('POST', 'https://example.test/upload')
            ->withHeader('Content-Length', '11');

        $response = $middleware->process($request, $this->okHandler($factory));
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/payload-too-large', $payload['type']);
        self::assertSame(10, $payload['max_body_bytes']);
    }

    private function okHandler(Psr17Factory $factory): RequestHandlerInterface
    {
        return new class ($factory) implements RequestHandlerInterface {
            public function __construct(
                private readonly Psr17Factory $factory,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }
}
