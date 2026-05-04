<?php

declare(strict_types=1);

namespace Nene2\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class JsonResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function create(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $body = $this->streamFactory->createStream(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );

        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody($body);
    }
}
