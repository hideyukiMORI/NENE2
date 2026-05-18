<?php

declare(strict_types=1);

namespace Nene2\View;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Creates PSR-7 `text/html; charset=UTF-8` responses from rendered template strings.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class HtmlResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private NativePhpViewRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function create(string $template, array $data = [], int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody(
            $this->streamFactory->createStream($this->renderer->render($template, $data))
        );
    }
}
