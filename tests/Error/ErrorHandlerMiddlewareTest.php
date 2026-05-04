<?php

declare(strict_types=1);

namespace Nene2\Tests\Error;

use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ErrorHandlerMiddlewareTest extends TestCase
{
    public function testValidationExceptionMapsToProblemDetails(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ErrorHandlerMiddleware(new ProblemDetailsResponseFactory($factory, $factory));
        $request = $factory->createServerRequest('POST', 'https://example.test/users');

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
}
