<?php

declare(strict_types=1);

namespace Nene2\Error;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class ProblemDetailsResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $extensions
     */
    public function create(
        ServerRequestInterface $request,
        string $type,
        string $title,
        int $status,
        ?string $detail = null,
        array $extensions = [],
    ): ResponseInterface {
        $payload = [
            'type' => 'https://nene2.dev/problems/' . $type,
            'title' => $title,
            'status' => $status,
            'instance' => $request->getUri()->getPath() ?: '/',
        ];

        if ($detail !== null) {
            $payload['detail'] = $detail;
        }

        $payload = array_merge($payload, $extensions);

        $body = $this->streamFactory->createStream(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/problem+json; charset=utf-8')
            ->withBody($body);
    }
}
