<?php

declare(strict_types=1);

namespace Nene2\Tests\Error;

use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Routing\MethodNotAllowedException;
use Nene2\Routing\RouteNotFoundException;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
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

    public function testDebugModeExposesExceptionMessageInDetail(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails, [], debug: true);
        $request = $this->factory->createServerRequest('GET', 'https://example.test/crash');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('database connection refused');
                }
            },
        );

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('database connection refused', $payload['detail']);
    }

    public function testNonDebugModeHidesExceptionMessage(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->problemDetails, [], debug: false);
        $request = $this->factory->createServerRequest('GET', 'https://example.test/crash');

        $response = $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('database connection refused');
                }
            },
        );

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('The server encountered an unexpected condition.', $payload['detail']);
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

    public function testUnhandledExceptionIsLoggedAtErrorLevelRegardlessOfDebug(): void
    {
        $spyLogger = new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $middleware = new ErrorHandlerMiddleware($this->problemDetails, [], debug: false, logger: $spyLogger);
        // The sanitized request id arrives via the RequestIdMiddleware attribute,
        // not the raw client header.
        $request = $this->factory->createServerRequest('GET', 'https://example.test/crash')
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-test-123');

        $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'nene2'@'db-host'");
                }
            },
        );

        self::assertCount(1, $spyLogger->records);
        self::assertSame('error', $spyLogger->records[0]['level']);
        // Log message is static — the raw (secret-bearing) exception message must not leak.
        self::assertSame('Unhandled exception while processing request.', $spyLogger->records[0]['message']);
        self::assertStringNotContainsString('Access denied', $spyLogger->records[0]['message']);
        self::assertSame('req-test-123', $spyLogger->records[0]['context']['request_id']);
        // In non-debug mode only the class + location are kept; the full exception
        // object (which serializes the SQL/DSN-bearing message) is not logged.
        self::assertSame(RuntimeException::class, $spyLogger->records[0]['context']['exception_class']);
        self::assertArrayNotHasKey('exception', $spyLogger->records[0]['context']);
    }

    public function testUnhandledExceptionIsLoggedEvenInDebugMode(): void
    {
        $spyLogger = new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $middleware = new ErrorHandlerMiddleware($this->problemDetails, [], debug: true, logger: $spyLogger);
        $request = $this->factory->createServerRequest('GET', 'https://example.test/crash');

        $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('secret internal error');
                }
            },
        );

        self::assertCount(1, $spyLogger->records);
        self::assertSame('error', $spyLogger->records[0]['level']);
        // In debug mode the full exception object is attached for local diagnosis.
        self::assertInstanceOf(RuntimeException::class, $spyLogger->records[0]['context']['exception']);
    }

    public function testDomainHandledExceptionIsNotLogged(): void
    {
        $spyLogger = new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $domainHandler = new class () implements DomainExceptionHandlerInterface {
            public function supports(Throwable $exception): bool
            {
                return $exception instanceof RuntimeException;
            }

            public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(409);
            }
        };

        $middleware = new ErrorHandlerMiddleware($this->problemDetails, [$domainHandler], logger: $spyLogger);
        $request = $this->factory->createServerRequest('POST', 'https://example.test/resource');

        $middleware->process(
            $request,
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('conflict');
                }
            },
        );

        self::assertCount(0, $spyLogger->records, 'Domain-handled exceptions must not be logged by ErrorHandlerMiddleware.');
    }
}
