<?php

declare(strict_types=1);

namespace Nene2\Mcp;

interface LocalMcpHttpClientInterface
{
    public function get(string $baseUrl, string $path): LocalMcpHttpResponse;

    /** @param array<string, mixed> $body */
    public function post(string $baseUrl, string $path, array $body): LocalMcpHttpResponse;

    /** @param array<string, mixed> $body */
    public function put(string $baseUrl, string $path, array $body): LocalMcpHttpResponse;

    public function delete(string $baseUrl, string $path): LocalMcpHttpResponse;
}
