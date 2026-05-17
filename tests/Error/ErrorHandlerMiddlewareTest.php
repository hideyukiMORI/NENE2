<?php

declare(strict_types=1);

namespace Nene2\Tests\Error;

use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Routing\MethodNotAllowedException;
use Nene2\Routing\RouteNotFoundException;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class ErrorHandlerMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private ProblemDetailsResponseFactory $problemDetails;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->problemDetails = new ProblemDetailsResponseFactory($this->factory, $this->factory);
    }

    public function testValidationExceptionMapsToProblemDetails(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails);
        $request = $this->factory->createServerRequest('POST', 'https://example.test/users');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new ValidationException([
                        new ValidationError('email', 'Email must be a valid email address.', 'invalid_email'),
                    ]);
                }
            },
        );

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/validation-failed', $payload['type']);
        self::assertSame('Validation Failed', $payload['title']);
        self::assertSame('/users', $payload['instance']);
        self::assertSame(
            [
                [
                    'field' => 'email',
                    'message' => 'Email must be a valid email address.',
                    'code' => 'invalid_email',
                ],
            ],
            $payload['errors'],
        );
    }

    public function testRouteNotFoundReturns404(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails);
        $request = $this->factory->createServerRequest('GET', 'https://example.test/does-not-exist');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RouteNotFoundException();
                }
            },
        );

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type']);
        self::assertSame('Not Found', $payload['title']);
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails);
        $request = $this->factory->createServerRequest('DELETE', 'https://example.test/examples/notes');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new MethodNotAllowedException(['GET', 'POST']);
                }
            },
        );

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(405, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/method-not-allowed', $payload['type']);
        self::assertSame('Method Not Allowed', $payload['title']);
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testDomainExceptionHandlerIsDelegatedTo(): void
    {
        $domainHandler = new class () implements DomainExceptionHandlerInterface {
            public function supports(Throwable $exception): bool
            {
                return $exception instanceof RuntimeException && $exception->getMessage() === 'domain';
            }

            public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory();
                return $factory->createResponse(409);
            }
        };

        $middleware = new ErrorHandlerMiddleware($this->problemDetails, [$domainHandler]);
        $request = $this->factory->createServerRequest('POST', 'https://example.test/examples/notes');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('domain');
                }
            },
        );

        self::assertSame(409, $response->getStatusCode());
    }

    public function testUnhandledExceptionReturns500(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails);
        $request = $this->factory->createServerRequest('GET', 'https://example.test/crash');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('unexpected');
                }
            },
        );

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/internal-server-error', $payload['type']);
        self::assertSame('Internal Server Error', $payload['title']);
    }

    public function testSuccessPassthroughIsUnmodified(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails);
        $request = $this->factory->createServerRequest('GET', 'https://example.test/health');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return (new Psr17Factory())->createResponse(200);
                }
            },
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
