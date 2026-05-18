<?php

declare(strict_types=1);

namespace Nene2\Error;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds RFC 9457 `application/problem+json` responses.
 *
 * The `type` field is prefixed with `$problemDetailsBaseUrl` (default: `https://nene2.dev/problems/`).
 * Override via the `PROBLEM_DETAILS_BASE_URL` environment variable for custom problem types.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class ProblemDetailsResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private string $problemDetailsBaseUrl = 'https://nene2.dev/problems/',
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
            'type' => $this->problemDetailsBaseUrl . $type,
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
