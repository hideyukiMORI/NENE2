<?php

declare(strict_types=1);

namespace Nene2\Mcp;

final readonly class LocalMcpHttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function requestId(): ?string
    {
        return $this->headers['x-request-id'] ?? null;
    }
}
