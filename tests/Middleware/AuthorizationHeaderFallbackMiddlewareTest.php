<?php

declare(strict_types=1);

namespace Nene2\Tests\Middleware;

use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\AuthorizationHeaderFallbackMiddleware;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthorizationHeaderFallbackMiddlewareTest extends TestCase
{
    public function testStandardHeaderWinsOverTheMirror(): void
    {
        $request = $this->request()
            ->withHeader('Authorization', 'Bearer standard')
            ->withHeader('X-Authorization', 'Bearer mirror');

        self::assertSame('Bearer standard', $this->authorizationSeenDownstream($request));
    }

    public function testMirrorIsAdoptedWhenStandardHeaderIsAbsent(): void
    {
        $request = $this->request()->withHeader('X-Authorization', 'Bearer mirror');

        self::assertSame('Bearer mirror', $this->authorizationSeenDownstream($request));
    }

    public function testEmptyStandardHeaderCountsAsAbsent(): void
    {
        $request = $this->request()
            ->withHeader('Authorization', '')
            ->withHeader('X-Authorization', 'Bearer mirror');

        self::assertSame('Bearer mirror', $this->authorizationSeenDownstream($request));
    }

    public function testNoHeadersLeavesTheRequestUnchanged(): void
    {
        $handler = $this->capturingHandler();

        $middleware = new AuthorizationHeaderFallbackMiddleware();
        $middleware->process($this->request(), $handler);

        $downstream = $handler->captured;
        self::assertInstanceOf(ServerRequestInterface::class, $downstream);
        self::assertSame('', $downstream->getHeaderLine('Authorization'));
        self::assertFalse($downstream->hasHeader('Authorization'));
    }

    public function testFallbackIsMethodIndependent(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request = (new Psr17Factory())
                ->createServerRequest($method, 'https://example.test/protected/resource')
                ->withHeader('X-Authorization', 'Bearer mirror');

            self::assertSame(
                'Bearer mirror',
                $this->authorizationSeenDownstream($request),
                sprintf('The mirror must be adopted for %s requests too.', $method),
            );
        }
    }

    public function testDefaultPipelineDoesNotAdoptTheMirror(): void
    {
        $response = $this->application(enableFallback: false)->handle(
            $this->request()->withHeader('X-Authorization', 'Bearer mirror'),
        );

        self::assertSame('', $this->decodeAuthorizationEcho($response));
    }

    public function testOptInPipelineAdoptsTheMirrorBeforeAuthMiddleware(): void
    {
        $response = $this->application(enableFallback: true)->handle(
            $this->request()->withHeader('X-Authorization', 'Bearer mirror'),
        );

        self::assertSame('Bearer mirror', $this->decodeAuthorizationEcho($response));
    }

    public function testOptInPipelineKeepsTheStandardHeaderWhenBothArePresent(): void
    {
        $response = $this->application(enableFallback: true)->handle(
            $this->request()
                ->withHeader('Authorization', 'Bearer standard')
                ->withHeader('X-Authorization', 'Bearer mirror'),
        );

        self::assertSame('Bearer standard', $this->decodeAuthorizationEcho($response));
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'https://example.test/echo-authorization');
    }

    private function authorizationSeenDownstream(ServerRequestInterface $request): string
    {
        $handler = $this->capturingHandler();

        $middleware = new AuthorizationHeaderFallbackMiddleware();
        $middleware->process($request, $handler);

        $downstream = $handler->captured;
        self::assertInstanceOf(ServerRequestInterface::class, $downstream);

        return $downstream->getHeaderLine('Authorization');
    }

    /**
     * @return RequestHandlerInterface&object{captured: ?ServerRequestInterface}
     */
    private function capturingHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return (new Psr17Factory())->createResponse(200);
            }
        };
    }

    private function application(bool $enableFallback): RequestHandlerInterface
    {
        $factory = new Psr17Factory();

        return (new RuntimeApplicationFactory(
            $factory,
            $factory,
            routeRegistrars: [
                static function (Router $router): void {
                    $router->get('/echo-authorization', static function (ServerRequestInterface $request) {
                        $response = (new Psr17Factory())->createResponse(200)
                            ->withHeader('Content-Type', 'application/json');
                        $response->getBody()->write(json_encode(
                            ['authorization' => $request->getHeaderLine('Authorization')],
                            JSON_THROW_ON_ERROR,
                        ));

                        return $response;
                    });
                },
            ],
            enableAuthorizationHeaderFallback: $enableFallback,
        ))->create();
    }

    private function decodeAuthorizationEcho(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode());

        /** @var array{authorization: string} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $payload['authorization'];
    }
}
