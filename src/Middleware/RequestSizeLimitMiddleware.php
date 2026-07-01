<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects requests whose body exceeds a configured byte limit with a 413 Problem Details response.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class RequestSizeLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param StreamFactoryInterface|null $streamFactory Used to re-expose an unknown-size body to
     *                                                    downstream handlers after it is measured.
     *                                                    Provide it (the framework wiring does) so that
     *                                                    chunked / streamed bodies stay readable; without
     *                                                    it the body is only rewound when seekable.
     */
    public function __construct(
        private ProblemDetailsResponseFactory $problemDetails,
        private int $maxBodyBytes = 1_048_576,
        private ?StreamFactoryInterface $streamFactory = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');

        if ($contentLength !== '') {
            if ($this->isOversized($contentLength)) {
                return $this->tooLarge($request);
            }

            return $handler->handle($request);
        }

        // No Content-Length header. Trust the stream size when it is known; otherwise
        // (chunked or otherwise unknown-size streamed body) measure the body while
        // reading, so it cannot bypass the limit unmeasured. A spoofed/absent length
        // no longer skips enforcement.
        $body = $request->getBody();
        $size = $body->getSize();

        if ($size !== null) {
            if ($size > $this->maxBodyBytes) {
                return $this->tooLarge($request);
            }

            return $handler->handle($request);
        }

        $buffered = $this->readCapped($body);

        if (strlen($buffered) > $this->maxBodyBytes) {
            return $this->tooLarge($request);
        }

        return $handler->handle($this->rewindOrReplaceBody($request, $body, $buffered));
    }

    /**
     * Reads at most `maxBodyBytes + 1` bytes so an overflow is detectable without
     * buffering an unbounded body into memory.
     */
    private function readCapped(StreamInterface $body): string
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $limit = $this->maxBodyBytes + 1;
        $buffered = '';

        while (strlen($buffered) < $limit && !$body->eof()) {
            $chunk = $body->read($limit - strlen($buffered));

            if ($chunk === '') {
                break;
            }

            $buffered .= $chunk;
        }

        return $buffered;
    }

    /**
     * Re-exposes an already-read body to downstream handlers: replace it with a fresh
     * stream when a factory is available, otherwise rewind it in place when seekable.
     */
    private function rewindOrReplaceBody(
        ServerRequestInterface $request,
        StreamInterface $body,
        string $buffered,
    ): ServerRequestInterface {
        if ($this->streamFactory !== null) {
            return $request->withBody($this->streamFactory->createStream($buffered));
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $request;
    }

    private function tooLarge(ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create(
            $request,
            'payload-too-large',
            'Payload Too Large',
            413,
            'The request body exceeds the configured size limit.',
            [
                'max_body_bytes' => $this->maxBodyBytes,
            ],
        );
    }

    private function isOversized(string $contentLength): bool
    {
        if (preg_match('/\A\d+\z/', $contentLength) !== 1) {
            // Invalid Content-Length (negative, decimal, hex, etc.) — treat as oversized.
            // RFC 9110 §8.6 requires a non-negative decimal integer; reject anything else.
            return true;
        }

        return (int) $contentLength > $this->maxBodyBytes;
    }
}
