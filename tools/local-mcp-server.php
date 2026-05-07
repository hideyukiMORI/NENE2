<?php

declare(strict_types=1);

use Nene2\Mcp\LocalMcpException;
use Nene2\Mcp\LocalMcpServer;
use Nene2\Mcp\LocalMcpToolCatalog;
use Nene2\Mcp\NativeLocalMcpHttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$apiBaseUrl = getenv('NENE2_LOCAL_API_BASE_URL');

if (!is_string($apiBaseUrl) || $apiBaseUrl === '') {
    $apiBaseUrl = 'http://localhost:8080';
}

$server = new LocalMcpServer(
    new LocalMcpToolCatalog($root . '/docs/mcp/tools.json'),
    new NativeLocalMcpHttpClient(),
    $apiBaseUrl,
);

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    try {
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($message)) {
            throw new LocalMcpException('JSON-RPC message must be an object.');
        }

        $response = $server->handle($message);

        if ($response === null) {
            continue;
        }
    } catch (Throwable $exception) {
        $response = [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => $exception->getMessage(),
            ],
        ];
    }

    fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
