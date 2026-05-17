<?php

declare(strict_types=1);

namespace Nene2\Tests;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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

    public function testHealthEndpointRunsThroughRuntime(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/health'));
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('ok', $payload['status']);
        self::assertSame('NENE2', $payload['service']);
    }

    public function testMachineHealthRequiresApiKey(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory, machineApiKey: 'test-key'))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/machine/health'));
        $payload = $this->decodeJson($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/unauthorized', $payload['type']);
    }

    public function testMachineHealthAcceptsConfiguredApiKey(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory, machineApiKey: 'test-key'))->create();

        $response = $application->handle(
            $factory
                ->createServerRequest('GET', 'https://example.test/machine/health')
                ->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertSame('NENE2', $payload['service']);
        self::assertSame('api_key', $payload['credential_type']);
    }

    public function testExamplePingEndpointRunsThroughRuntime(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/examples/ping'));
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', $payload['message']);
        self::assertSame('ok', $payload['status']);
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

    public function testRouteRegistrarAddsCustomRoute(): void
    {
        $factory = new Psr17Factory();
        $json = new JsonResponseFactory($factory, $factory);

        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            routeRegistrars: [
                static function (Router $router) use ($json): void {
                    $router->get(
                        '/custom',
                        static fn (ServerRequestInterface $req) => $json->create(['custom' => true]),
                    );
                },
            ],
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/custom'));
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['custom']);
    }

    public function testRouteRegistrarCanAccessPathParameters(): void
    {
        $factory = new Psr17Factory();
        $json = new JsonResponseFactory($factory, $factory);

        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            routeRegistrars: [
                static function (Router $router) use ($json): void {
                    $router->get(
                        '/greet/{name}',
                        static function (ServerRequestInterface $req) use ($json): ResponseInterface {
                            $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);

                            return $json->create(['message' => 'Hello, ' . ($params['name'] ?? '') . '!']);
                        },
                    );
                },
            ],
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/greet/NENE2'));
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello, NENE2!', $payload['message']);
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
