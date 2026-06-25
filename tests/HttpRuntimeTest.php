<?php

declare(strict_types=1);

namespace Nene2\Tests;

use Nene2\FrameworkInfo;
use Nene2\Http\HealthCheckInterface;
use Nene2\Http\HealthStatus;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
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
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
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

    public function testProblemDetailsBaseUrlAppliesToFrameworkErrors(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            problemDetailsBaseUrl: 'https://example.dev/problems/',
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/missing'));
        $payload = $this->decodeJson($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('https://example.dev/problems/not-found', $payload['type']);
    }

    public function testProblemDetailsBaseUrlAppliesToValidationFailures(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            routeRegistrars: [
                static function (Router $router): void {
                    $router->get(
                        '/validate',
                        static function (ServerRequestInterface $req): ResponseInterface {
                            throw new ValidationException([
                                new ValidationError('name', 'name is required', 'required'),
                            ]);
                        },
                    );
                },
            ],
            problemDetailsBaseUrl: 'https://example.dev/problems/',
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/validate'));
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://example.dev/problems/validation-failed', $payload['type']);
        self::assertSame('Validation Failed', $payload['title']);
        self::assertSame([
            ['field' => 'name', 'message' => 'name is required', 'code' => 'required'],
        ], $payload['errors']);
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
        // framework_version is always reported; the application version is omitted unless injected.
        self::assertSame(FrameworkInfo::VERSION, $payload['framework_version']);
        self::assertArrayNotHasKey('version', $payload);
    }

    public function testMachineHealthIncludesAppVersionWhenInjected(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            machineApiKey: 'test-key',
            appVersion: '2.3.4',
        ))->create();

        $response = $application->handle(
            $factory
                ->createServerRequest('GET', 'https://example.test/machine/health')
                ->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2.3.4', $payload['version']);
        self::assertSame(FrameworkInfo::VERSION, $payload['framework_version']);
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

    public function testHealthEndpointWithHealthyCheck(): void
    {
        $factory = new Psr17Factory();

        $check = new class () implements HealthCheckInterface {
            public function name(): string
            {
                return 'database';
            }

            public function check(): HealthStatus
            {
                return HealthStatus::Ok;
            }
        };

        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            healthChecks: [$check],
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/health'));
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertSame('NENE2', $payload['service']);
        self::assertSame(['database' => 'ok'], $payload['checks']);
    }

    public function testHealthEndpointWithDegradedCheck(): void
    {
        $factory = new Psr17Factory();

        $check = new class () implements HealthCheckInterface {
            public function name(): string
            {
                return 'database';
            }

            public function check(): HealthStatus
            {
                return HealthStatus::Error;
            }
        };

        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            healthChecks: [$check],
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/health'));
        $payload = $this->decodeJson($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('degraded', $payload['status']);
        self::assertSame('NENE2', $payload['service']);
        self::assertSame(['database' => 'error'], $payload['checks']);
    }

    public function testHealthEndpointWithCheckThatThrows(): void
    {
        $factory = new Psr17Factory();

        $check = new class () implements HealthCheckInterface {
            public function name(): string
            {
                return 'external';
            }

            public function check(): HealthStatus
            {
                throw new \RuntimeException('connection refused');
            }
        };

        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            healthChecks: [$check],
        ))->create();

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/health'));
        $payload = $this->decodeJson($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('degraded', $payload['status']);
        self::assertSame(['external' => 'error'], $payload['checks']);
    }

    public function testHeadRequestReceivesSecurityHeadersAndXRequestId(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('HEAD', 'https://example.test/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testHeadRequestToUnknownRouteReturns404WithHeaders(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest('HEAD', 'https://example.test/no-such-route'));

        self::assertSame(404, $response->getStatusCode());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testMachineApiKeyProtectedMethodsAllowsGetWithoutKey(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            machineApiKey:                      'test-key',
            machineApiKeyProtectedPaths:         [],
            machineApiKeyProtectedPathPrefixes:  ['/machine/'],
            machineApiKeyProtectedMethods:       ['POST', 'PUT', 'DELETE'],
        ))->create();

        $response = $application->handle(
            $factory->createServerRequest('GET', 'https://example.test/machine/health'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMachineApiKeyProtectedMethodsBlocksPostWithoutKey(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            machineApiKey:                      'test-key',
            machineApiKeyProtectedPaths:         [],
            machineApiKeyProtectedPathPrefixes:  ['/machine/'],
            machineApiKeyProtectedMethods:       ['POST', 'PUT', 'DELETE'],
        ))->create();

        $response = $application->handle(
            $factory->createServerRequest('POST', 'https://example.test/machine/health'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/unauthorized', $payload['type']);
    }

    public function testMachineApiKeyProtectedPathPrefixesProtectsDynamicPath(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            machineApiKey:                      'test-key',
            machineApiKeyProtectedPaths:         [],
            machineApiKeyProtectedPathPrefixes:  ['/machine/'],
        ))->create();

        $response = $application->handle(
            $factory->createServerRequest('GET', 'https://example.test/machine/health'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/unauthorized', $payload['type']);
    }

    public function testRequestMaxBodyBytesOverridesDefaultLimit(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            requestMaxBodyBytes: 10,
        ))->create();

        $response = $application->handle(
            $factory
                ->createServerRequest('POST', 'https://example.test/')
                ->withHeader('Content-Length', '11'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/payload-too-large', $payload['type']);
        self::assertSame(10, $payload['max_body_bytes']);
    }

    public function testUnionPathAndPrefixProtectionWithoutExplicitPathsClear(): void
    {
        // F-2 fix: protectedPaths=['/machine/health'] (default) + protectedPathPrefixes=['/products']
        // Both lists are non-empty → union mode → /products prefix is protected without needing to
        // clear machineApiKeyProtectedPaths first.
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            machineApiKey: 'test-key',
            machineApiKeyProtectedPathPrefixes: ['/products'],
            machineApiKeyProtectedMethods: ['POST'],
        ))->create();

        // POST /products → method + prefix match → protected
        $postProducts = $application->handle(
            $factory->createServerRequest('POST', 'https://example.test/products'),
        );
        self::assertSame(401, $postProducts->getStatusCode());

        // POST /machine/health → method + exact path match (from default protectedPaths) → protected
        // Key provided → middleware passes → router returns 405 (GET-only route), not 401
        $postMachineHealth = $application->handle(
            $factory
                ->createServerRequest('POST', 'https://example.test/machine/health')
                ->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        self::assertSame(405, $postMachineHealth->getStatusCode()); // middleware passed, router rejected method

        // POST /orders → method filter passes, no path/prefix match → not protected
        $postOrders = $application->handle(
            $factory->createServerRequest('POST', 'https://example.test/orders'),
        );
        self::assertSame(404, $postOrders->getStatusCode()); // route not registered, but not 401
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
