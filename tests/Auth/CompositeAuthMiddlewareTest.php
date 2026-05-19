<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\CompositeAuthMiddleware;
use Nene2\Auth\TokenVerifierInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Middleware\ApiKeyAuthenticationMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CompositeAuthMiddlewareTest extends TestCase
{
    public function testEmptyCompositePassesThrough(): void
    {
        $factory = new Psr17Factory();
        $composite = new CompositeAuthMiddleware([]);
        $request = $factory->createServerRequest('GET', 'https://example.test/any');

        $response = $composite->process($request, $this->okHandler($factory));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSingleMiddlewareIsApplied(): void
    {
        $factory = new Psr17Factory();
        $composite = new CompositeAuthMiddleware([
            $this->rejectingMiddleware($factory),
        ]);
        $request = $factory->createServerRequest('GET', 'https://example.test/protected');

        $response = $composite->process($request, $this->okHandler($factory));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testBearerMiddlewareProtectsItsPrefixOtherPathPassesThrough(): void
    {
        $factory = new Psr17Factory();
        $bearer = new BearerTokenMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            $this->acceptingVerifier(),
            protectedPathPrefixes: ['/me/'],
        );
        $composite = new CompositeAuthMiddleware([$bearer]);

        // /public is not under /me/ → should pass through without auth
        $request = $factory->createServerRequest('GET', 'https://example.test/public');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(200, $response->getStatusCode());

        // /me/profile without token → 401
        $request = $factory->createServerRequest('GET', 'https://example.test/me/profile');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testThreeTierAuthModel(): void
    {
        // Three-tier model:
        //   - /me/* → Bearer auth required
        //   - /admin → API key required (all methods)
        //   - /events → public (neither middleware claims it)
        $factory = new Psr17Factory();
        $problemDetails = new ProblemDetailsResponseFactory($factory, $factory);

        $bearer = new BearerTokenMiddleware(
            $problemDetails,
            $this->acceptingVerifier(['sub' => '42']),
            protectedPathPrefixes: ['/me/'],
        );
        $apiKey = new ApiKeyAuthenticationMiddleware(
            $problemDetails,
            'secret-key',
            protectedPaths: ['/admin'],
        );

        $composite = new CompositeAuthMiddleware([$bearer, $apiKey]);

        // GET /events (public) → neither middleware claims it → handler
        $request = $factory->createServerRequest('GET', 'https://example.test/events');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(200, $response->getStatusCode());

        // GET /admin without API key → Bearer passes through; ApiKey rejects
        $request = $factory->createServerRequest('GET', 'https://example.test/admin');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(401, $response->getStatusCode());

        // GET /admin with valid API key → passes both middlewares
        $request = $factory
            ->createServerRequest('GET', 'https://example.test/admin')
            ->withHeader('X-NENE2-API-Key', 'secret-key');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(200, $response->getStatusCode());

        // GET /me/profile without Bearer → BearerMiddleware rejects
        $request = $factory->createServerRequest('GET', 'https://example.test/me/profile');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(401, $response->getStatusCode());

        // GET /me/profile with Bearer → Bearer passes; ApiKey does not claim /me/ → handler
        $request = $factory
            ->createServerRequest('GET', 'https://example.test/me/profile')
            ->withHeader('Authorization', 'Bearer valid.token');
        $response = $composite->process($request, $this->okHandler($factory));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAuthAttributesAreForwardedDownstream(): void
    {
        $factory = new Psr17Factory();
        $claims = ['sub' => 'user-1'];
        $bearer = new BearerTokenMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            $this->acceptingVerifier($claims),
            protectedPathPrefixes: ['/me/'],
        );
        $composite = new CompositeAuthMiddleware([$bearer]);

        $capture = new \stdClass();
        $capture->request = null;
        $handler = new class ($factory, $capture) implements RequestHandlerInterface {
            public function __construct(
                private readonly Psr17Factory $factory,
                private readonly \stdClass $capture,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capture->request = $request;

                return $this->factory->createResponse(200);
            }
        };

        $request = $factory
            ->createServerRequest('GET', 'https://example.test/me/profile')
            ->withHeader('Authorization', 'Bearer valid.token');

        $composite->process($request, $handler);

        self::assertNotNull($capture->request);
        self::assertSame('bearer', $capture->request->getAttribute('nene2.auth.credential_type'));
        self::assertSame($claims, $capture->request->getAttribute('nene2.auth.claims'));
    }

    private function okHandler(Psr17Factory $factory): RequestHandlerInterface
    {
        return new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }

    private function rejectingMiddleware(Psr17Factory $factory): MiddlewareInterface
    {
        return new class ($factory) implements MiddlewareInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->factory->createResponse(401);
            }
        };
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function acceptingVerifier(array $claims = []): TokenVerifierInterface
    {
        return new class ($claims) implements TokenVerifierInterface {
            /** @param array<string, mixed> $claims */
            public function __construct(private readonly array $claims)
            {
            }

            public function verify(string $token): array
            {
                return $this->claims;
            }
        };
    }
}
