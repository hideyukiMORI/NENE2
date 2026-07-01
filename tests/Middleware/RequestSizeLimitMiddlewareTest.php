<?php

declare(strict_types=1);

namespace Nene2\Tests\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Middleware\RequestSizeLimitMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Focuses on the unknown-size body path (#1444): a body whose stream reports
 * `getSize() === null` (chunked / streamed) must still be measured and bounded,
 * not passed through unchecked.
 */
final class RequestSizeLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function rejectsUnknownSizeBodyExceedingLimit(): void
    {
        $factory = new Psr17Factory();
        $middleware = new RequestSizeLimitMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            10,
            $factory,
        );

        // 20-byte body, no Content-Length, stream size unknown (like chunked / php://input).
        $request = $factory->createServerRequest('POST', 'https://example.test/upload')
            ->withBody($this->unknownSizeStream(str_repeat('a', 20), seekable: false));

        $response = $middleware->process($request, $this->recordingHandler($factory));

        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function allowsUnknownSizeBodyWithinLimitAndKeepsItReadable(): void
    {
        $factory = new Psr17Factory();
        $middleware = new RequestSizeLimitMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            100,
            $factory,
        );

        $handler = $this->recordingHandler($factory);
        $request = $factory->createServerRequest('POST', 'https://example.test/upload')
            ->withBody($this->unknownSizeStream('{"ok":true}', seekable: false));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        // Downstream must still see the full body after it was measured.
        self::assertSame('{"ok":true}', $handler->seenBody);
    }

    #[Test]
    public function rewindsSeekableUnknownSizeBodyWhenNoFactoryProvided(): void
    {
        $factory = new Psr17Factory();
        // No stream factory — falls back to rewinding a seekable body in place.
        $middleware = new RequestSizeLimitMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            100,
        );

        $handler = $this->recordingHandler($factory);
        $request = $factory->createServerRequest('POST', 'https://example.test/upload')
            ->withBody($this->unknownSizeStream('payload', seekable: true));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('payload', $handler->seenBody);
    }

    #[Test]
    public function exactLimitUnknownSizeBodyIsAllowed(): void
    {
        $factory = new Psr17Factory();
        $middleware = new RequestSizeLimitMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            10,
            $factory,
        );

        $request = $factory->createServerRequest('POST', 'https://example.test/upload')
            ->withBody($this->unknownSizeStream(str_repeat('a', 10), seekable: false));

        $response = $middleware->process($request, $this->recordingHandler($factory));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return RequestHandlerInterface&object{seenBody: string}
     */
    private function recordingHandler(Psr17Factory $factory): RequestHandlerInterface
    {
        return new class ($factory) implements RequestHandlerInterface {
            public string $seenBody = '';

            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenBody = (string) $request->getBody();

                return $this->factory->createResponse(200);
            }
        };
    }

    /**
     * A read-only stream over an in-memory string that reports an unknown size
     * (`getSize() === null`), modelling a chunked / streamed request body.
     */
    private function unknownSizeStream(string $content, bool $seekable): StreamInterface
    {
        return new class ($content, $seekable) implements StreamInterface {
            private int $offset = 0;

            public function __construct(private string $content, private readonly bool $seekable)
            {
            }

            public function __toString(): string
            {
                if ($this->seekable) {
                    $this->offset = 0;
                }

                return substr($this->content, $this->offset);
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return $this->offset;
            }

            public function eof(): bool
            {
                return $this->offset >= strlen($this->content);
            }

            public function isSeekable(): bool
            {
                return $this->seekable;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                if (!$this->seekable) {
                    throw new \RuntimeException('Stream is not seekable.');
                }

                $this->offset = $offset;
            }

            public function rewind(): void
            {
                $this->seek(0);
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Stream is not writable.');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $chunk = substr($this->content, $this->offset, $length);
                $this->offset += strlen($chunk);

                return $chunk;
            }

            public function getContents(): string
            {
                $chunk = substr($this->content, $this->offset);
                $this->offset = strlen($this->content);

                return $chunk;
            }

            public function getMetadata(?string $key = null): mixed
            {
                return $key === null ? [] : null;
            }
        };
    }
}
