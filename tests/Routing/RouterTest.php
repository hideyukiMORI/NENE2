<?php

declare(strict_types=1);

namespace Nene2\Tests\Routing;

use InvalidArgumentException;
use Nene2\Routing\MethodNotAllowedException;
use Nene2\Routing\RouteNotFoundException;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouterTest extends TestCase
{
    public function testMatchesFixedRoute(): void
    {
        $factory = new Psr17Factory();
        $router = (new Router())->get(
            '/health',
            static fn (ServerRequestInterface $request): ResponseInterface => $factory->createResponse(204),
        );

        $response = $router->handle($factory->createServerRequest('GET', 'https://example.test/health'));

        self::assertSame(204, $response->getStatusCode());
    }

    public function testMatchesPathParametersAsRequestAttribute(): void
    {
        $factory = new Psr17Factory();
        $router = (new Router())->get(
            '/users/{userId}/posts/{postId}',
            static function (ServerRequestInterface $request) use ($factory): ResponseInterface {
                $parameters = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);

                self::assertSame(
                    [
                        'userId' => 'alice',
                        'postId' => 'hello world',
                    ],
                    $parameters,
                );

                return $factory->createResponse(200);
            },
        );

        $response = $router->handle(
            $factory->createServerRequest('GET', 'https://example.test/users/alice/posts/hello%20world'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testMethodNotAllowedStillUsesPathMatch(): void
    {
        $factory = new Psr17Factory();
        $router = (new Router())->get(
            '/users/{userId}',
            static fn (ServerRequestInterface $request): ResponseInterface => $factory->createResponse(200),
        );

        $this->expectException(MethodNotAllowedException::class);

        $router->handle($factory->createServerRequest('POST', 'https://example.test/users/alice'));
    }

    public function testMissingRouteStillThrowsRouteNotFound(): void
    {
        $factory = new Psr17Factory();
        $router = (new Router())->get(
            '/users/{userId}',
            static fn (ServerRequestInterface $request): ResponseInterface => $factory->createResponse(200),
        );

        $this->expectException(RouteNotFoundException::class);

        $router->handle($factory->createServerRequest('GET', 'https://example.test/projects/alice'));
    }

    public function testRejectsPartialPathParameterSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Router())->get(
            '/users/{userId}.json',
            static fn (ServerRequestInterface $request): ResponseInterface => (new Psr17Factory())->createResponse(200),
        );
    }
}
