<?php

declare(strict_types=1);

namespace Nene2\Mcp;

interface LocalMcpHttpClientInterface
{
    public function get(string $baseUrl, string $path): LocalMcpHttpResponse;
}
