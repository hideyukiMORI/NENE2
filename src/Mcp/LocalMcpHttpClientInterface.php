<?php

declare(strict_types=1);

namespace Nene2\Mcp;

/**
 * HTTP client used by {@see LocalMcpServer} to proxy tool calls to the application API.
 * Implement this interface to inject custom transport or test doubles.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface LocalMcpHttpClientInterface
{
    public function get(string $baseUrl, string $path): LocalMcpHttpResponse;

    /** @param array<string, mixed> $body */
    public function post(string $baseUrl, string $path, array $body): LocalMcpHttpResponse;

    /** @param array<string, mixed> $body */
    public function put(string $baseUrl, string $path, array $body): LocalMcpHttpResponse;

    public function delete(string $baseUrl, string $path): LocalMcpHttpResponse;

    public function hasAuthentication(): bool;
}
