<?php

declare(strict_types=1);

namespace Nene2\Tests\Auth;

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\TokenVerificationException;
use Nene2\Auth\TokenVerifierInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerTokenMiddlewareTest extends TestCase
{
    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $factory = new Psr17Factory();
        $middleware = $this->makeMiddleware($factory, $this->acceptingVerifier());
        $request = $factory->createServerRequest('GET', 'https://example.test/protected');

        $response = $middleware->process($request, $this->failHandler());
        $payload = $this->decodeBody($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringContainsString('missing_token', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('https://nene2.dev/problems/unauthorized', $payload['type']);
    }

    public function testNonBearerSchemeReturns401(): void
    {
        $factory = new Psr17Factory();
        $middleware = $this->makeMiddleware($factory, $this->acceptingVerifier());
        $request = $factory
            ->createServerRequest('GET', 'https://example.test/protected')
            ->withHeader('Authorization', 'Basic dXNlcjpwYXNz');

        $response = $middleware->process($request, $this->failHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('invalid_token', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testInvalidTokenReturns401(): void
    {
        $factory = new Psr17Factory();
        $verifier = new class () implements TokenVerifierInterface {
            public function verify(string $token): array
            {
                throw new TokenVerificationException('Token has expired.');
            }
        };
        $middleware = $this->makeMiddleware($factory, $verifier);
        $request = $factory
            ->createServerRequest('GET', 'https://example.test/protected')
            ->withHeader('Authorization', 'Bearer expired.token.here');

        $response = $middleware->process($request, $this->failHandler());
        $payload = $this->decodeBody($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Token has expired.', $payload['detail']);
    }

    public function testValidTokenForwardsRequestWithClaims(): void
    {
        $factory = new Psr17Factory();
        $claims = ['sub' => 'user-42', 'scope' => 'read:system'];
        $middleware = $this->makeMiddleware($factory, $this->acceptingVerifier($claims));

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
            ->createServerRequest('GET', 'https://example.test/protected')
            ->withHeader('Authorization', 'Bearer valid.token.here');

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $capture->request);
        self::assertSame('bearer', $capture->request->getAttribute('nene2.auth.credential_type'));
        self::assertSame($claims, $capture->request->getAttribute('nene2.auth.claims'));
    }

    private function makeMiddleware(Psr17Factory $factory, TokenVerifierInterface $verifier): BearerTokenMiddleware
    {
        return new BearerTokenMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            $verifier,
        );
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

    private function failHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Handler should not be reached.');
            }
        };
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
