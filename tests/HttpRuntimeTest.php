<?php

declare(strict_types=1);

namespace Nene2\Tests;

use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class HttpRuntimeTest extends TestCase
{
    public function testSmokeEndpointRunsThroughRuntime(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/'));
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('NENE2', $payload['name']);
        self::assertSame('ok', $payload['status']);
    }

    public function testMissingRouteReturnsProblemDetails(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/missing'));
        $payload = $this->decodeJson($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type']);
        self::assertSame('Not Found', $payload['title']);
    }

    public function testUnsupportedMethodReturnsProblemDetailsWithAllowHeader(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('POST', 'https://example.test/'));
        $payload = $this->decodeJson($response);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame('https://nene2.dev/problems/method-not-allowed', $payload['type']);
    }

    public function testOversizedRequestReturnsProblemDetails(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle(
            $factory
                ->createServerRequest('POST', 'https://example.test/')
                ->withHeader('Content-Length', '1048577'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(413, $response->getStatusCode());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('https://nene2.dev/problems/payload-too-large', $payload['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);

        return $payload;
    }
}
