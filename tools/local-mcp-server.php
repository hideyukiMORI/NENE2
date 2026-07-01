<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Mcp\LocalMcpException;
use Nene2\Mcp\LocalMcpServer;
use Nene2\Mcp\LocalMcpToolCatalog;
use Nene2\Mcp\NativeLocalMcpHttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$apiBaseUrl = getenv('NENE2_LOCAL_API_BASE_URL');

if (!is_string($apiBaseUrl) || $apiBaseUrl === '') {
    $apiBaseUrl = 'http://localhost:8200';
}

$bearerToken = null;
$jwtSecret = getenv('NENE2_LOCAL_JWT_SECRET');

if (is_string($jwtSecret) && $jwtSecret !== '') {
    $v = new LocalBearerTokenVerifier($jwtSecret);
    $bearerToken = $v->issue(['sub' => 'mcp-server', 'scope' => 'read:system write:example', 'iat' => time(), 'exp' => time() + 86400]);
}

// Audit log for state-changing tool calls goes to STDERR (STDOUT carries the JSON-RPC
// protocol and must not be polluted).
$auditHandler = new StreamHandler('php://stderr', Level::Info);
$auditHandler->setFormatter(new JsonFormatter());
$auditLogger = new Logger('mcp-audit', [$auditHandler]);

$server = new LocalMcpServer(
    new LocalMcpToolCatalog($root . '/docs/mcp/tools.json'),
    new NativeLocalMcpHttpClient($bearerToken),
    $apiBaseUrl,
    $auditLogger,
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
