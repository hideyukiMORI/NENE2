<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Example\Note\NoteNotFoundException;
use Nene2\Example\Note\NoteNotFoundExceptionHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NoteNotFoundExceptionHandlerTest extends TestCase
{
    private NoteNotFoundExceptionHandler $handler;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->handler = new NoteNotFoundExceptionHandler(
            new ProblemDetailsResponseFactory($this->factory, $this->factory),
        );
    }

    public function testSupportsNoteNotFoundException(): void
    {
        self::assertTrue($this->handler->supports(new NoteNotFoundException(1)));
    }

    public function testDoesNotSupportOtherExceptions(): void
    {
        self::assertFalse($this->handler->supports(new RuntimeException('other')));
    }

    public function testHandleReturns404ProblemDetails(): void
    {
        $request = $this->factory->createServerRequest('GET', 'https://example.test/examples/notes/99');
        $response = $this->handler->handle(new NoteNotFoundException(99), $request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('Not Found', $payload['title'] ?? null);
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type'] ?? null);
    }
}
