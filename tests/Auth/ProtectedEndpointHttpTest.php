<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RequestScopedHolder;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ProtectedEndpointHttpTest extends TestCase
{
    private const string SECRET = 'test-secret-key-for-protected-endpoint!!';

    private Psr17Factory $factory;
    private LocalBearerTokenVerifier $verifier;
    private RequestHandlerInterface $application;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->verifier = new LocalBearerTokenVerifier(self::SECRET);
        $problemDetails = new ProblemDetailsResponseFactory($this->factory, $this->factory);

        $bearerMiddleware = new BearerTokenMiddleware(
            $problemDetails,
            $this->verifier,
            ['/examples/protected'],
        );

        $this->application = (new RuntimeApplicationFactory(
            $this->factory,
            $this->factory,
            authMiddleware: $bearerMiddleware,
        ))->create();
    }

    public function testMissingTokenReturns401(): void
    {
        $response = $this->request('GET', '/examples/protected');

        $payload = $this->decodeBody($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('https://nene2.dev/problems/unauthorized', $payload['type']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $response = $this->request('GET', '/examples/protected', ['Authorization' => 'Bearer tampered.invalid.token']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testValidTokenReturns200WithClaims(): void
    {
        $token = $this->verifier->issue(['sub' => 'user-42', 'exp' => time() + 3600, 'scope' => 'read:system']);
        $response = $this->request('GET', '/examples/protected', ['Authorization' => 'Bearer ' . $token]);

        $payload = $this->decodeBody($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Welcome, authenticated user.', $payload['message']);
        self::assertSame('user-42', $payload['claims']['sub']);
        self::assertSame('read:system', $payload['claims']['scope']);
    }

    public function testPublicRouteIsUnaffectedByBearerMiddleware(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAuthMiddlewareAcceptsListOfMiddlewares(): void
    {
        $factory = new Psr17Factory();
        $probs   = new ProblemDetailsResponseFactory($factory, $factory);

        /** @var RequestScopedHolder<string> $holder */
        $holder = new RequestScopedHolder();

        $first = new class ($holder) implements MiddlewareInterface {
            /** @param RequestScopedHolder<string> $holder */
            public function __construct(private readonly RequestScopedHolder $holder)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->holder->set('first-ran');
                return $handler->handle($request);
            }
        };

        $second = new class ($holder) implements MiddlewareInterface {
            /** @param RequestScopedHolder<string> $holder */
            public function __construct(private readonly RequestScopedHolder $holder)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $request = $request->withAttribute('test-order', $this->holder->get() . '+second-ran');
                return $handler->handle($request);
            }
        };

        $app = (new RuntimeApplicationFactory(
            $factory,
            $factory,
            authMiddleware: [$first, $second],
            routeRegistrars: [
                static function (\Nene2\Routing\Router $router) use ($factory): void {
                    $jsonFactory = new \Nene2\Http\JsonResponseFactory($factory, $factory);
                    $router->get('/probe', static fn (ServerRequestInterface $req) => $jsonFactory->create([
                        'order' => $req->getAttribute('test-order'),
                    ]));
                },
            ],
        ))->create();

        $request  = $factory->createServerRequest('GET', 'http://localhost/probe');
        $response = $app->handle($request);
        $payload  = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('first-ran+second-ran', $payload['order']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest($method, 'http://localhost' . $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->application->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
